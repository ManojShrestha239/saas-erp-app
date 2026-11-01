<?php

declare(strict_types=1);

namespace Modules\Payments\Services;

use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\DatabaseManager;
use Modules\Accounting\Services\DocumentPostingService;
use Modules\Payments\DTOs\AllocatePaymentData;
use Modules\Payments\DTOs\CreatePaymentData;
use Modules\Payments\Enums\PaymentStatus;
use Modules\Payments\Enums\PaymentType;
use Modules\Payments\Events\PaymentAllocated;
use Modules\Payments\Events\PaymentDisbursed;
use Modules\Payments\Events\PaymentReceived;
use Modules\Payments\Models\Payment;
use Modules\Payments\Models\PaymentAllocation;
use Modules\Purchasing\Services\SupplierBillService;
use Modules\Sales\Services\SalesInvoiceService;
use Shared\Enums\DocumentType;
use Shared\Exceptions\ApiException;
use Shared\Services\DocumentNumberingService;
use Shared\Tenancy\TenantContext;

final class PaymentService
{
    public function __construct(
        private readonly DatabaseManager $db,
        private readonly DocumentNumberingService $numberingService,
        private readonly SalesInvoiceService $invoiceService,
        private readonly SupplierBillService $billService,
        private readonly DocumentPostingService $documentPostingService,
    ) {}

    public function create(CreatePaymentData $data, string $userId): Payment
    {
        return $this->db->connection('tenant')->transaction(function () use ($data, $userId): Payment {
            if ($data->amountCents <= 0) {
                throw ApiException::unprocessable('Payment amount must be greater than zero.', 'INVALID_PAYMENT_AMOUNT');
            }

            $totalAllocations = 0;
            foreach ($data->allocations as $alloc) {
                if ($alloc->amountCents <= 0) {
                    throw ApiException::unprocessable('Allocation amount must be greater than zero.', 'INVALID_ALLOCATION_AMOUNT');
                }
                $totalAllocations += $alloc->amountCents;
            }

            if ($totalAllocations > $data->amountCents) {
                throw ApiException::unprocessable(
                    "Total allocations ({$totalAllocations} cents) exceed payment amount ({$data->amountCents} cents).",
                    'EXCESS_ALLOCATION'
                );
            }

            $docType = $data->type === PaymentType::Inbound
                ? DocumentType::CustomerReceipt
                : DocumentType::SupplierPayment;

            $paymentNumber = $this->numberingService->generateNext($docType);

            $status = $data->postImmediately ? PaymentStatus::Posted : PaymentStatus::Draft;
            $postedAt = $data->postImmediately ? now() : null;
            $postedBy = $data->postImmediately ? $userId : null;

            // Only apply allocations immediately if posting
            $allocatedCents = $data->postImmediately ? $totalAllocations : 0;
            $unallocatedCents = $data->amountCents - $allocatedCents;

            /** @var Payment $payment */
            $payment = Payment::create([
                'payment_number' => $paymentNumber,
                'type' => $data->type,
                'contact_id' => $data->contactId,
                'amount_cents' => $data->amountCents,
                'allocated_amount_cents' => $allocatedCents,
                'unallocated_amount_cents' => $unallocatedCents,
                'currency' => $data->currency,
                'payment_method' => $data->paymentMethod,
                'payment_date' => $data->paymentDate,
                'reference_number' => $data->referenceNumber,
                'notes' => $data->notes,
                'status' => $status,
                'created_by_user_id' => $userId,
                'posted_by_user_id' => $postedBy,
                'posted_at' => $postedAt,
            ]);

            $tenant = TenantContext::get();
            $tenantId = $tenant !== null ? (string) $tenant->id : '';

            if ($data->postImmediately) {
                if ($data->type === PaymentType::Inbound) {
                    PaymentReceived::dispatch($payment, $tenantId, $userId);
                } else {
                    PaymentDisbursed::dispatch($payment, $tenantId, $userId);
                }

                $this->documentPostingService->postPayment(
                    paymentId: (string) $payment->id,
                    paymentNumber: $payment->payment_number,
                    paymentDate: Carbon::parse($payment->payment_date),
                    type: $payment->type->value,
                    amountCents: $payment->amount_cents,
                    currency: $payment->currency,
                    userId: $userId,
                );

                foreach ($data->allocations as $alloc) {
                    $this->validatePayableTypeMatchesPayment($data->type, $alloc->payableType);

                    /** @var PaymentAllocation $record */
                    $record = $payment->allocations()->create([
                        'payable_type' => $alloc->payableType,
                        'payable_id' => $alloc->payableId,
                        'amount_cents' => $alloc->amountCents,
                        'allocated_at' => now(),
                        'allocated_by_user_id' => $userId,
                    ]);

                    $this->applyAllocationToPayable($alloc->payableType, $alloc->payableId, $alloc->amountCents);

                    PaymentAllocated::dispatch($payment, $record, $tenantId, $userId);
                }
            }

            return $payment->load('allocations');
        }, 3);
    }

    public function post(string $id, string $userId): Payment
    {
        return $this->db->connection('tenant')->transaction(function () use ($id, $userId): Payment {
            /** @var Payment|null $payment */
            $payment = Payment::with('allocations')->where('id', $id)->lockForUpdate()->first();

            if ($payment === null) {
                throw ApiException::notFound("Payment '{$id}' not found.", 'PAYMENT_NOT_FOUND');
            }

            if ($payment->status !== PaymentStatus::Draft) {
                throw ApiException::unprocessable(
                    "Payment cannot be posted from '{$payment->status->value}' status.",
                    'INVALID_PAYMENT_STATUS'
                );
            }

            $payment->update([
                'status' => PaymentStatus::Posted,
                'posted_at' => now(),
                'posted_by_user_id' => $userId,
            ]);

            $tenant = TenantContext::get();
            $tenantId = $tenant !== null ? (string) $tenant->id : '';

            if ($payment->type === PaymentType::Inbound) {
                PaymentReceived::dispatch($payment, $tenantId, $userId);
            } else {
                PaymentDisbursed::dispatch($payment, $tenantId, $userId);
            }

            $this->documentPostingService->postPayment(
                paymentId: (string) $payment->id,
                paymentNumber: $payment->payment_number,
                paymentDate: Carbon::parse($payment->payment_date),
                type: $payment->type->value,
                amountCents: $payment->amount_cents,
                currency: $payment->currency,
                userId: $userId,
            );

            return $payment->load('allocations');
        }, 3);
    }

    public function allocate(string $id, AllocatePaymentData $data, string $userId): Payment
    {
        return $this->db->connection('tenant')->transaction(function () use ($id, $data, $userId): Payment {
            /** @var Payment|null $payment */
            $payment = Payment::with('allocations')->where('id', $id)->lockForUpdate()->first();

            if ($payment === null) {
                throw ApiException::notFound("Payment '{$id}' not found.", 'PAYMENT_NOT_FOUND');
            }

            if ($payment->status !== PaymentStatus::Posted) {
                throw ApiException::unprocessable(
                    "Cannot allocate payment in status '{$payment->status->value}'. Payment must be posted first.",
                    'INVALID_PAYMENT_STATUS'
                );
            }

            if (empty($data->allocations)) {
                throw ApiException::unprocessable('Please provide at least one allocation.', 'NO_ALLOCATIONS');
            }

            $totalToAllocate = 0;
            foreach ($data->allocations as $alloc) {
                if ($alloc->amountCents <= 0) {
                    throw ApiException::unprocessable('Allocation amount must be greater than zero.', 'INVALID_ALLOCATION_AMOUNT');
                }
                $this->validatePayableTypeMatchesPayment($payment->type, $alloc->payableType);
                $totalToAllocate += $alloc->amountCents;
            }

            if ($totalToAllocate > $payment->unallocated_amount_cents) {
                throw ApiException::unprocessable(
                    "Total allocations ({$totalToAllocate} cents) exceed available unallocated balance ({$payment->unallocated_amount_cents} cents).",
                    'EXCESS_ALLOCATION'
                );
            }

            $tenant = TenantContext::get();
            $tenantId = $tenant !== null ? (string) $tenant->id : '';

            foreach ($data->allocations as $alloc) {
                /** @var PaymentAllocation $record */
                $record = $payment->allocations()->create([
                    'payable_type' => $alloc->payableType,
                    'payable_id' => $alloc->payableId,
                    'amount_cents' => $alloc->amountCents,
                    'allocated_at' => now(),
                    'allocated_by_user_id' => $userId,
                ]);

                $this->applyAllocationToPayable($alloc->payableType, $alloc->payableId, $alloc->amountCents);

                PaymentAllocated::dispatch($payment, $record, $tenantId, $userId);
            }

            $newAllocated = $payment->allocated_amount_cents + $totalToAllocate;
            $newUnallocated = $payment->amount_cents - $newAllocated;

            $payment->update([
                'allocated_amount_cents' => $newAllocated,
                'unallocated_amount_cents' => $newUnallocated,
            ]);

            return $payment->load('allocations');
        }, 3);
    }

    public function getUnallocatedCredit(string $contactId, PaymentType $type): int
    {
        return (int) Payment::where('contact_id', $contactId)
            ->where('type', $type)
            ->where('status', PaymentStatus::Posted)
            ->sum('unallocated_amount_cents');
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, Payment>
     */
    public function list(array $filters, int $perPage = 25): LengthAwarePaginator
    {
        $query = Payment::query()->with('allocations');

        if (! empty($filters['type'])) {
            $query->where('type', $filters['type']);
        }

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['contact_id'])) {
            $query->where('contact_id', $filters['contact_id']);
        }

        if (! empty($filters['payment_method'])) {
            $query->where('payment_method', $filters['payment_method']);
        }

        if (! empty($filters['search'])) {
            $search = (string) $filters['search'];
            $query->where(function ($q) use ($search): void {
                $q->where('payment_number', 'ilike', "%{$search}%")
                    ->orWhere('reference_number', 'ilike', "%{$search}%")
                    ->orWhere('notes', 'ilike', "%{$search}%");
            });
        }

        $perPage = min(max($perPage, 1), 100);

        return $query->orderByDesc('payment_date')->orderByDesc('created_at')->paginate($perPage);
    }

    public function find(string $id): Payment
    {
        /** @var Payment|null $payment */
        $payment = Payment::with('allocations')->find($id);

        if ($payment === null) {
            throw ApiException::notFound("Payment '{$id}' not found.", 'PAYMENT_NOT_FOUND');
        }

        return $payment;
    }

    private function validatePayableTypeMatchesPayment(PaymentType $paymentType, string $payableType): void
    {
        if ($paymentType === PaymentType::Inbound && $payableType !== 'invoice') {
            throw ApiException::unprocessable(
                "Inbound payment (receipt) can only be allocated to 'invoice', received '{$payableType}'.",
                'PAYABLE_TYPE_MISMATCH'
            );
        }

        if ($paymentType === PaymentType::Outbound && $payableType !== 'bill') {
            throw ApiException::unprocessable(
                "Outbound payment can only be allocated to 'bill', received '{$payableType}'.",
                'PAYABLE_TYPE_MISMATCH'
            );
        }
    }

    private function applyAllocationToPayable(string $payableType, string $payableId, int $amountCents): void
    {
        if ($payableType === 'invoice') {
            $this->invoiceService->recordPayment($payableId, $amountCents);
        } elseif ($payableType === 'bill') {
            $this->billService->recordPayment($payableId, $amountCents);
        }
    }

    /**
     * @return array{
     *     prior_paid_cents: int,
     *     transactions: list<array{
     *         id: string,
     *         date: string,
     *         due_date: null,
     *         document_number: string,
     *         type: string,
     *         description: string,
     *         debit_cents: int,
     *         credit_cents: int,
     *         amount_due_cents: int,
     *         status: string
     *     }>
     * }
     */
    public function getStatementTransactions(string $contactId, PaymentType $type, ?Carbon $startDate = null, ?Carbon $endDate = null): array
    {
        $priorPaidCents = 0;
        if ($startDate !== null) {
            $priorPaidCents = (int) Payment::where('contact_id', $contactId)
                ->where('type', $type)
                ->where('status', PaymentStatus::Posted)
                ->whereDate('payment_date', '<', $startDate->toDateString())
                ->sum('amount_cents');
        }

        $query = Payment::where('contact_id', $contactId)
            ->where('type', $type)
            ->where('status', PaymentStatus::Posted);

        if ($startDate !== null) {
            $query->whereDate('payment_date', '>=', $startDate->toDateString());
        }

        if ($endDate !== null) {
            $query->whereDate('payment_date', '<=', $endDate->toDateString());
        }

        $payments = $query->orderBy('payment_date', 'asc')->orderBy('payment_number', 'asc')->get();

        $transactions = [];
        foreach ($payments as $payment) {
            $refText = $payment->reference_number !== null && $payment->reference_number !== ''
                ? " (Ref: {$payment->reference_number})"
                : '';
            $desc = $type === PaymentType::Inbound
                ? "Customer Receipt {$payment->payment_number}{$refText}"
                : "Supplier Payment {$payment->payment_number}{$refText}";

            $debitCents = $type === PaymentType::Outbound ? $payment->amount_cents : 0;
            $creditCents = $type === PaymentType::Inbound ? $payment->amount_cents : 0;

            $transactions[] = [
                'id' => (string) $payment->id,
                'date' => Carbon::parse($payment->payment_date)->toDateString(),
                'due_date' => null,
                'document_number' => $payment->payment_number,
                'type' => 'payment',
                'description' => $desc,
                'debit_cents' => $debitCents,
                'credit_cents' => $creditCents,
                'amount_due_cents' => 0,
                'status' => $payment->status->value,
            ];
        }

        return [
            'prior_paid_cents' => $priorPaidCents,
            'transactions' => $transactions,
        ];
    }
}
