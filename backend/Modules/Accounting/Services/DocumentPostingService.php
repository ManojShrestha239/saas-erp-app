<?php

declare(strict_types=1);

namespace Modules\Accounting\Services;

use Carbon\Carbon;
use Modules\Accounting\DTOs\JournalEntryLineData;
use Modules\Accounting\DTOs\PostJournalEntryData;
use Modules\Accounting\Enums\SystemAccount;
use Modules\Accounting\Models\JournalEntry;

final class DocumentPostingService
{
    public function __construct(
        private readonly AccountService $accountService,
        private readonly JournalEntryService $journalEntryService,
    ) {}

    /**
     * Post journal entry for a posted sales invoice.
     *
     * Debits:
     * - Accounts Receivable (total_cents)
     * - COGS (if cogsCents > 0)
     *
     * Credits:
     * - Sales Revenue (subtotal_cents)
     * - Output VAT (if taxCents > 0)
     * - Inventory Asset (if cogsCents > 0)
     */
    public function postSalesInvoice(
        string $invoiceId,
        string $invoiceNumber,
        Carbon $issueDate,
        int $subtotalCents,
        int $taxCents,
        int $totalCents,
        int $cogsCents = 0,
        string $currency = 'USD',
        ?string $userId = null,
    ): JournalEntry {
        $arAccountId = $this->accountService->getSystemAccount(SystemAccount::AccountsReceivable)->id;
        $revenueAccountId = $this->accountService->getSystemAccount(SystemAccount::SalesRevenue)->id;

        /** @var list<JournalEntryLineData> $lines */
        $lines = [
            new JournalEntryLineData(
                accountId: $arAccountId,
                debitCents: $totalCents,
                creditCents: 0,
                description: "Accounts Receivable - Invoice {$invoiceNumber}",
            ),
            new JournalEntryLineData(
                accountId: $revenueAccountId,
                debitCents: 0,
                creditCents: $subtotalCents,
                description: "Sales Revenue - Invoice {$invoiceNumber}",
            ),
        ];

        if ($taxCents > 0) {
            $vatAccountId = $this->accountService->getSystemAccount(SystemAccount::OutputVat)->id;
            $lines[] = new JournalEntryLineData(
                accountId: $vatAccountId,
                debitCents: 0,
                creditCents: $taxCents,
                description: "Output VAT (13%) - Invoice {$invoiceNumber}",
            );
        }

        if ($cogsCents > 0) {
            $cogsAccountId = $this->accountService->getSystemAccount(SystemAccount::CostOfGoodsSold)->id;
            $inventoryAccountId = $this->accountService->getSystemAccount(SystemAccount::InventoryAsset)->id;

            $lines[] = new JournalEntryLineData(
                accountId: $cogsAccountId,
                debitCents: $cogsCents,
                creditCents: 0,
                description: "COGS - Invoice {$invoiceNumber}",
            );
            $lines[] = new JournalEntryLineData(
                accountId: $inventoryAccountId,
                debitCents: 0,
                creditCents: $cogsCents,
                description: "Inventory Relief - Invoice {$invoiceNumber}",
            );
        }

        return $this->journalEntryService->post(new PostJournalEntryData(
            entryDate: $issueDate,
            lines: $lines,
            description: "Sales Invoice {$invoiceNumber}",
            sourceType: 'sales_invoice',
            sourceId: $invoiceId,
            currency: $currency,
            userId: $userId,
        ));
    }

    /**
     * Reverse journal entry for a voided sales invoice.
     */
    public function reverseSalesInvoice(string $invoiceId, string $reason, ?string $userId = null): ?JournalEntry
    {
        $existing = $this->journalEntryService->findForSource('sales_invoice', $invoiceId);
        if ($existing === null || $existing->isReversed()) {
            return null;
        }

        return $this->journalEntryService->reverse(
            entry: $existing,
            reason: $reason,
            reversalDate: now(),
            userId: $userId,
        );
    }

    /**
     * Post journal entry for a posted supplier bill.
     *
     * Debits:
     * - Inventory Asset (subtotal_cents)
     * - Input VAT (if taxCents > 0)
     *
     * Credits:
     * - Accounts Payable (total_cents)
     */
    public function postSupplierBill(
        string $billId,
        string $billNumber,
        Carbon $billDate,
        int $subtotalCents,
        int $taxCents,
        int $totalCents,
        string $currency = 'USD',
        ?string $userId = null,
    ): JournalEntry {
        $inventoryAccountId = $this->accountService->getSystemAccount(SystemAccount::InventoryAsset)->id;
        $apAccountId = $this->accountService->getSystemAccount(SystemAccount::AccountsPayable)->id;

        /** @var list<JournalEntryLineData> $lines */
        $lines = [
            new JournalEntryLineData(
                accountId: $inventoryAccountId,
                debitCents: $subtotalCents,
                creditCents: 0,
                description: "Inventory Acquisition - Bill {$billNumber}",
            ),
        ];

        if ($taxCents > 0) {
            $inputVatAccountId = $this->accountService->getSystemAccount(SystemAccount::InputVat)->id;
            $lines[] = new JournalEntryLineData(
                accountId: $inputVatAccountId,
                debitCents: $taxCents,
                creditCents: 0,
                description: "Input VAT (Recoverable) - Bill {$billNumber}",
            );
        }

        $lines[] = new JournalEntryLineData(
            accountId: $apAccountId,
            debitCents: 0,
            creditCents: $totalCents,
            description: "Accounts Payable - Bill {$billNumber}",
        );

        return $this->journalEntryService->post(new PostJournalEntryData(
            entryDate: $billDate,
            lines: $lines,
            description: "Supplier Bill {$billNumber}",
            sourceType: 'supplier_bill',
            sourceId: $billId,
            currency: $currency,
            userId: $userId,
        ));
    }

    /**
     * Post journal entry for recorded payment (Customer Receipt or Supplier Payment).
     *
     * Inbound (Receipt):
     * - Debit: Operating Bank Account (amount_cents)
     * - Credit: Accounts Receivable (amount_cents)
     *
     * Outbound (Disbursement):
     * - Debit: Accounts Payable (amount_cents)
     * - Credit: Operating Bank Account (amount_cents)
     */
    public function postPayment(
        string $paymentId,
        string $paymentNumber,
        Carbon $paymentDate,
        string $type,
        int $amountCents,
        string $currency = 'USD',
        ?string $userId = null,
    ): JournalEntry {
        $bankAccountId = $this->accountService->getSystemAccount(SystemAccount::Bank)->id;

        if ($type === 'inbound') {
            $arAccountId = $this->accountService->getSystemAccount(SystemAccount::AccountsReceivable)->id;

            /** @var list<JournalEntryLineData> $lines */
            $lines = [
                new JournalEntryLineData(
                    accountId: $bankAccountId,
                    debitCents: $amountCents,
                    creditCents: 0,
                    description: "Customer Receipt - {$paymentNumber}",
                ),
                new JournalEntryLineData(
                    accountId: $arAccountId,
                    debitCents: 0,
                    creditCents: $amountCents,
                    description: "Receivable Settlement - {$paymentNumber}",
                ),
            ];
        } else {
            $apAccountId = $this->accountService->getSystemAccount(SystemAccount::AccountsPayable)->id;

            /** @var list<JournalEntryLineData> $lines */
            $lines = [
                new JournalEntryLineData(
                    accountId: $apAccountId,
                    debitCents: $amountCents,
                    creditCents: 0,
                    description: "Liability Settlement - {$paymentNumber}",
                ),
                new JournalEntryLineData(
                    accountId: $bankAccountId,
                    debitCents: 0,
                    creditCents: $amountCents,
                    description: "Supplier Payment - {$paymentNumber}",
                ),
            ];
        }

        return $this->journalEntryService->post(new PostJournalEntryData(
            entryDate: $paymentDate,
            lines: $lines,
            description: "Payment {$paymentNumber} ({$type})",
            sourceType: 'payment',
            sourceId: $paymentId,
            currency: $currency,
            userId: $userId,
        ));
    }

    /**
     * Post journal entry for manual stock adjustment.
     *
     * Positive impact (Gain):
     * - Debit: Inventory Asset
     * - Credit: Inventory Adjustment Gain
     *
     * Negative impact (Loss/Shrinkage):
     * - Debit: Inventory Adjustment Loss
     * - Credit: Inventory Asset
     */
    public function postStockAdjustment(
        string $adjustmentId,
        string $adjustmentNumber,
        Carbon $adjustmentDate,
        int $totalCostImpactCents,
        string $currency = 'USD',
        ?string $userId = null,
    ): ?JournalEntry {
        if ($totalCostImpactCents === 0) {
            return null;
        }

        $inventoryAccountId = $this->accountService->getSystemAccount(SystemAccount::InventoryAsset)->id;
        $adjustmentAccountId = $this->accountService->getSystemAccount(SystemAccount::InventoryAdjustment)->id;

        if ($totalCostImpactCents > 0) {
            /** @var list<JournalEntryLineData> $lines */
            $lines = [
                new JournalEntryLineData(
                    accountId: $inventoryAccountId,
                    debitCents: $totalCostImpactCents,
                    creditCents: 0,
                    description: "Inventory Gain - Adjustment {$adjustmentNumber}",
                ),
                new JournalEntryLineData(
                    accountId: $adjustmentAccountId,
                    debitCents: 0,
                    creditCents: $totalCostImpactCents,
                    description: "Inventory Adjustment Gain - {$adjustmentNumber}",
                ),
            ];
        } else {
            $cost = abs($totalCostImpactCents);
            /** @var list<JournalEntryLineData> $lines */
            $lines = [
                new JournalEntryLineData(
                    accountId: $adjustmentAccountId,
                    debitCents: $cost,
                    creditCents: 0,
                    description: "Inventory Adjustment Loss - {$adjustmentNumber}",
                ),
                new JournalEntryLineData(
                    accountId: $inventoryAccountId,
                    debitCents: 0,
                    creditCents: $cost,
                    description: "Inventory Asset Reduction - {$adjustmentNumber}",
                ),
            ];
        }

        return $this->journalEntryService->post(new PostJournalEntryData(
            entryDate: $adjustmentDate,
            lines: $lines,
            description: "Stock Adjustment {$adjustmentNumber}",
            sourceType: 'stock_adjustment',
            sourceId: $adjustmentId,
            currency: $currency,
            userId: $userId,
        ));
    }

    /**
     * Post journal entry for a posted customer credit note (sales return / credit memo).
     *
     * Debits:
     * - Sales Revenue (subtotal_cents)
     * - Output VAT (VAT Payable) (if tax_cents > 0)
     * - Inventory Asset (if cogsCents > 0)
     *
     * Credits:
     * - Accounts Receivable (total_cents)
     * - Cost of Goods Sold (if cogsCents > 0)
     */
    public function postCreditNote(
        string $creditNoteId,
        string $creditNoteNumber,
        Carbon $issueDate,
        int $subtotalCents,
        int $taxCents,
        int $totalCents,
        int $cogsCents = 0,
        string $currency = 'USD',
        ?string $userId = null,
    ): JournalEntry {
        $arAccountId = $this->accountService->getSystemAccount(SystemAccount::AccountsReceivable)->id;
        $revenueAccountId = $this->accountService->getSystemAccount(SystemAccount::SalesRevenue)->id;

        /** @var list<JournalEntryLineData> $lines */
        $lines = [
            new JournalEntryLineData(
                accountId: $revenueAccountId,
                debitCents: $subtotalCents,
                creditCents: 0,
                description: "Sales Return - Credit Note {$creditNoteNumber}",
            ),
            new JournalEntryLineData(
                accountId: $arAccountId,
                debitCents: 0,
                creditCents: $totalCents,
                description: "Receivable Reduction - Credit Note {$creditNoteNumber}",
            ),
        ];

        if ($taxCents > 0) {
            $vatAccountId = $this->accountService->getSystemAccount(SystemAccount::OutputVat)->id;
            $lines[] = new JournalEntryLineData(
                accountId: $vatAccountId,
                debitCents: $taxCents,
                creditCents: 0,
                description: "Output VAT Reversal - Credit Note {$creditNoteNumber}",
            );
        }

        if ($cogsCents > 0) {
            $cogsAccountId = $this->accountService->getSystemAccount(SystemAccount::CostOfGoodsSold)->id;
            $inventoryAccountId = $this->accountService->getSystemAccount(SystemAccount::InventoryAsset)->id;

            $lines[] = new JournalEntryLineData(
                accountId: $inventoryAccountId,
                debitCents: $cogsCents,
                creditCents: 0,
                description: "Inventory Restock - Credit Note {$creditNoteNumber}",
            );
            $lines[] = new JournalEntryLineData(
                accountId: $cogsAccountId,
                debitCents: 0,
                creditCents: $cogsCents,
                description: "COGS Reversal - Credit Note {$creditNoteNumber}",
            );
        }

        return $this->journalEntryService->post(new PostJournalEntryData(
            entryDate: $issueDate,
            lines: $lines,
            description: "Credit Note {$creditNoteNumber}",
            sourceType: 'credit_note',
            sourceId: $creditNoteId,
            currency: $currency,
            userId: $userId,
        ));
    }

    /**
     * Post journal entry for a posted supplier debit note (purchase return / vendor debit).
     *
     * Debits:
     * - Accounts Payable (total_cents)
     *
     * Credits:
     * - Inventory Asset (subtotal_cents)
     * - Input VAT (if tax_cents > 0)
     */
    public function postDebitNote(
        string $debitNoteId,
        string $debitNoteNumber,
        Carbon $issueDate,
        int $subtotalCents,
        int $taxCents,
        int $totalCents,
        string $currency = 'USD',
        ?string $userId = null,
    ): JournalEntry {
        $apAccountId = $this->accountService->getSystemAccount(SystemAccount::AccountsPayable)->id;
        $inventoryAccountId = $this->accountService->getSystemAccount(SystemAccount::InventoryAsset)->id;

        /** @var list<JournalEntryLineData> $lines */
        $lines = [
            new JournalEntryLineData(
                accountId: $apAccountId,
                debitCents: $totalCents,
                creditCents: 0,
                description: "Accounts Payable Reduction - Debit Note {$debitNoteNumber}",
            ),
            new JournalEntryLineData(
                accountId: $inventoryAccountId,
                debitCents: 0,
                creditCents: $subtotalCents,
                description: "Inventory Return - Debit Note {$debitNoteNumber}",
            ),
        ];

        if ($taxCents > 0) {
            $vatAccountId = $this->accountService->getSystemAccount(SystemAccount::InputVat)->id;
            $lines[] = new JournalEntryLineData(
                accountId: $vatAccountId,
                debitCents: 0,
                creditCents: $taxCents,
                description: "Input VAT Reversal - Debit Note {$debitNoteNumber}",
            );
        }

        return $this->journalEntryService->post(new PostJournalEntryData(
            entryDate: $issueDate,
            lines: $lines,
            description: "Debit Note {$debitNoteNumber}",
            sourceType: 'debit_note',
            sourceId: $debitNoteId,
            currency: $currency,
            userId: $userId,
        ));
    }
}
