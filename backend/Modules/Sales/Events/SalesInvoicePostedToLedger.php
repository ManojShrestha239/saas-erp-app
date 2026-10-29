<?php

declare(strict_types=1);

namespace Modules\Sales\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Modules\Sales\Models\SalesInvoice;

/**
 * Dispatched after a sales invoice is successfully posted and stock is decremented.
 * Serves as the domain event hook for Phase 5 General Ledger posting (AR, Revenue, VAT, COGS, Inventory).
 */
final class SalesInvoicePostedToLedger implements ShouldDispatchAfterCommit
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * @param  list<array{product_id: string, quantity: int, unit_price_cents: int, subtotal_cents: int, tax_cents: int, total_cents: int}>  $lineItems
     */
    public function __construct(
        public readonly SalesInvoice $invoice,
        public readonly string $tenantId,
        public readonly string $userId,
        public readonly int $cogsCents = 0,
        public readonly array $lineItems = [],
    ) {}
}
