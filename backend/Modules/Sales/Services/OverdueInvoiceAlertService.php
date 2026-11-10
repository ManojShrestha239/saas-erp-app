<?php

declare(strict_types=1);

namespace Modules\Sales\Services;

use Illuminate\Support\Carbon;
use Modules\Auth\Enums\TenantRole;
use Modules\Auth\Services\FcmDeviceTokenService;
use Modules\Sales\Enums\SalesInvoiceStatus;
use Modules\Sales\Models\SalesInvoice;
use Shared\Services\FcmService;

final class OverdueInvoiceAlertService
{
    public function __construct(
        private readonly FcmService $fcmService,
        private readonly FcmDeviceTokenService $deviceTokenService,
    ) {}

    /**
     * @return array{
     *     sent: bool,
     *     overdue_count: int,
     *     total_overdue_cents: int,
     *     reason?: string,
     *     result?: array{
     *         total: int,
     *         successful_count: int,
     *         failed_count: int,
     *         successful_tokens: list<string>,
     *         failed_tokens: list<string>,
     *         invalid_tokens: list<string>
     *     }
     * }
     */
    public function sendOverdueAlerts(): array
    {
        $invoices = SalesInvoice::query()
            ->whereIn('status', [SalesInvoiceStatus::Posted, SalesInvoiceStatus::PartiallyPaid])
            ->where('due_date', '<', Carbon::today()->toDateString())
            ->where('amount_due_cents', '>', 0)
            ->get();

        $overdueCount = $invoices->count();
        $totalOverdueCents = (int) $invoices->sum('amount_due_cents');

        if ($overdueCount === 0) {
            return [
                'sent' => false,
                'overdue_count' => 0,
                'total_overdue_cents' => 0,
                'reason' => 'no_overdue_invoices',
            ];
        }

        $tokens = $this->deviceTokenService->getTokensForRoles([
            TenantRole::Accountant->value,
            TenantRole::Owner->value,
        ]);

        if (empty($tokens)) {
            return [
                'sent' => false,
                'overdue_count' => $overdueCount,
                'total_overdue_cents' => $totalOverdueCents,
                'reason' => 'no_registered_tokens',
            ];
        }

        $formattedAmount = sprintf('$%.2f', $totalOverdueCents / 100);
        $title = "Overdue Invoices Alert: {$overdueCount} invoices past due";
        $body = "{$overdueCount} customer invoice(s) totaling {$formattedAmount} are currently overdue.";

        $data = [
            'type' => 'overdue_invoices_alert',
            'overdue_count' => (string) $overdueCount,
            'total_overdue_cents' => (string) $totalOverdueCents,
            'url' => '/sales/invoices',
        ];

        $result = $this->fcmService->sendMulticast(
            tokens: $tokens,
            title: $title,
            body: $body,
            data: $data,
        );

        if (! empty($result['invalid_tokens'])) {
            $this->deviceTokenService->removeTokens($result['invalid_tokens']);
        }

        return [
            'sent' => true,
            'overdue_count' => $overdueCount,
            'total_overdue_cents' => $totalOverdueCents,
            'result' => $result,
        ];
    }

    /**
     * @return array{
     *     sent: bool,
     *     reason?: string,
     *     result?: array{
     *         total: int,
     *         successful_count: int,
     *         failed_count: int,
     *         successful_tokens: list<string>,
     *         failed_tokens: list<string>,
     *         invalid_tokens: list<string>
     *     }
     * }
     */
    public function sendSingleInvoiceOverdueAlert(SalesInvoice $invoice): array
    {
        if ($invoice->amount_due_cents <= 0) {
            return ['sent' => false, 'reason' => 'already_paid'];
        }

        $tokens = $this->deviceTokenService->getTokensForRoles([
            TenantRole::Accountant->value,
            TenantRole::Owner->value,
        ]);

        if (empty($tokens)) {
            return ['sent' => false, 'reason' => 'no_registered_tokens'];
        }

        $formattedAmount = sprintf('$%.2f', $invoice->amount_due_cents / 100);
        $dueDate = $invoice->due_date->format('Y-m-d');
        $title = "Overdue Invoice: {$invoice->invoice_number}";
        $body = "Invoice {$invoice->invoice_number} is overdue since {$dueDate} (amount due: {$formattedAmount}).";

        $data = [
            'type' => 'overdue_invoice_alert',
            'invoice_id' => $invoice->id,
            'invoice_number' => $invoice->invoice_number,
            'amount_due_cents' => (string) $invoice->amount_due_cents,
            'due_date' => $dueDate,
            'url' => '/sales/invoices',
        ];

        $result = $this->fcmService->sendMulticast(
            tokens: $tokens,
            title: $title,
            body: $body,
            data: $data,
        );

        if (! empty($result['invalid_tokens'])) {
            $this->deviceTokenService->removeTokens($result['invalid_tokens']);
        }

        return [
            'sent' => true,
            'result' => $result,
        ];
    }
}
