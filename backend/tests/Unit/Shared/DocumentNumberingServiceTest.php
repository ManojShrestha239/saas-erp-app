<?php

declare(strict_types=1);

namespace Tests\Unit\Shared;

use Illuminate\Database\DatabaseManager;
use InvalidArgumentException;
use Mockery;
use PHPUnit\Framework\TestCase;
use Shared\Enums\DocumentType;
use Shared\Services\DocumentNumberingService;

final class DocumentNumberingServiceTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_document_types_have_expected_default_prefixes_and_labels(): void
    {
        $this->assertSame('INV', DocumentType::Invoice->defaultPrefix());
        $this->assertSame('QUO', DocumentType::Quotation->defaultPrefix());
        $this->assertSame('PO', DocumentType::PurchaseOrder->defaultPrefix());
        $this->assertSame('GRN', DocumentType::GoodsReceipt->defaultPrefix());
        $this->assertSame('BILL', DocumentType::SupplierBill->defaultPrefix());
        $this->assertSame('REC', DocumentType::CustomerReceipt->defaultPrefix());
        $this->assertSame('PAY', DocumentType::SupplierPayment->defaultPrefix());
        $this->assertSame('ADJ', DocumentType::StockAdjustment->defaultPrefix());
        $this->assertSame('CN', DocumentType::CreditNote->defaultPrefix());
        $this->assertSame('DN', DocumentType::DebitNote->defaultPrefix());
        $this->assertSame('JRN', DocumentType::JournalEntry->defaultPrefix());

        $this->assertSame('Sales Invoice', DocumentType::Invoice->label());
        $this->assertSame('Purchase Order', DocumentType::PurchaseOrder->label());
        $this->assertSame('Stock Adjustment', DocumentType::StockAdjustment->label());
    }

    public function test_format_number_with_yearly_sequence(): void
    {
        /** @var DatabaseManager $db */
        $db = Mockery::mock(DatabaseManager::class);
        $service = new DocumentNumberingService($db);

        $formatted = $service->formatNumber(prefix: 'INV', year: 2026, number: 1, padding: 6);
        $this->assertSame('INV-2026-000001', $formatted);

        $formattedLarge = $service->formatNumber(prefix: 'INV', year: 2026, number: 123456, padding: 6);
        $this->assertSame('INV-2026-123456', $formattedLarge);

        $formattedOverflow = $service->formatNumber(prefix: 'INV', year: 2026, number: 1000001, padding: 6);
        $this->assertSame('INV-2026-1000001', $formattedOverflow);
    }

    public function test_format_number_with_non_yearly_sequence(): void
    {
        /** @var DatabaseManager $db */
        $db = Mockery::mock(DatabaseManager::class);
        $service = new DocumentNumberingService($db);

        $formatted = $service->formatNumber(prefix: 'CUST', year: 0, number: 42, padding: 6);
        $this->assertSame('CUST-000042', $formatted);

        $formattedCustomPad = $service->formatNumber(prefix: 'PO', year: 0, number: 5, padding: 4);
        $this->assertSame('PO-0005', $formattedCustomPad);
    }

    public function test_set_next_number_rejects_zero_or_negative_numbers(): void
    {
        /** @var DatabaseManager $db */
        $db = Mockery::mock(DatabaseManager::class);
        $service = new DocumentNumberingService($db);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Next number must be an integer greater than or equal to 1.');

        $service->setNextNumber(DocumentType::Invoice, 0);
    }

    public function test_set_next_number_rejects_negative_integers(): void
    {
        /** @var DatabaseManager $db */
        $db = Mockery::mock(DatabaseManager::class);
        $service = new DocumentNumberingService($db);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Next number must be an integer greater than or equal to 1.');

        $service->setNextNumber(DocumentType::Invoice, -10);
    }
}
