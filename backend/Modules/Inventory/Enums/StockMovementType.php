<?php

declare(strict_types=1);

namespace Modules\Inventory\Enums;

enum StockMovementType: string
{
    case OpeningBalance = 'opening_balance';
    case PurchaseReceipt = 'purchase_receipt';
    case SalesDelivery = 'sales_delivery';
    case AdjustmentIn = 'adjustment_in';
    case AdjustmentOut = 'adjustment_out';
    case TransferIn = 'transfer_in';
    case TransferOut = 'transfer_out';

    public function label(): string
    {
        return match ($this) {
            self::OpeningBalance => 'Opening Balance',
            self::PurchaseReceipt => 'Purchase Receipt',
            self::SalesDelivery => 'Sales Delivery',
            self::AdjustmentIn => 'Stock Adjustment (In)',
            self::AdjustmentOut => 'Stock Adjustment (Out)',
            self::TransferIn => 'Warehouse Transfer (In)',
            self::TransferOut => 'Warehouse Transfer (Out)',
        };
    }

    public function isAddition(): bool
    {
        return match ($this) {
            self::OpeningBalance, self::PurchaseReceipt, self::AdjustmentIn, self::TransferIn => true,
            self::SalesDelivery, self::AdjustmentOut, self::TransferOut => false,
        };
    }

    public function isReduction(): bool
    {
        return ! $this->isAddition();
    }
}
