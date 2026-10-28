<?php

declare(strict_types=1);

namespace Shared\Enums;

enum DocumentType: string
{
    case Invoice = 'invoice';
    case Quotation = 'quotation';
    case PurchaseOrder = 'purchase_order';
    case GoodsReceipt = 'goods_receipt';
    case SupplierBill = 'supplier_bill';
    case CustomerReceipt = 'customer_receipt';
    case SupplierPayment = 'supplier_payment';
    case StockAdjustment = 'stock_adjustment';
    case CreditNote = 'credit_note';
    case DebitNote = 'debit_note';
    case JournalEntry = 'journal_entry';

    public function defaultPrefix(): string
    {
        return match ($this) {
            self::Invoice => 'INV',
            self::Quotation => 'QUO',
            self::PurchaseOrder => 'PO',
            self::GoodsReceipt => 'GRN',
            self::SupplierBill => 'BILL',
            self::CustomerReceipt => 'REC',
            self::SupplierPayment => 'PAY',
            self::StockAdjustment => 'ADJ',
            self::CreditNote => 'CN',
            self::DebitNote => 'DN',
            self::JournalEntry => 'JRN',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Invoice => 'Sales Invoice',
            self::Quotation => 'Sales Quotation',
            self::PurchaseOrder => 'Purchase Order',
            self::GoodsReceipt => 'Goods Receipt Note',
            self::SupplierBill => 'Supplier Bill',
            self::CustomerReceipt => 'Customer Receipt',
            self::SupplierPayment => 'Supplier Payment',
            self::StockAdjustment => 'Stock Adjustment',
            self::CreditNote => 'Credit Note',
            self::DebitNote => 'Debit Note',
            self::JournalEntry => 'Journal Entry',
        };
    }
}
