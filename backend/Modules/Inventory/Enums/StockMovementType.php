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
    case SalesReturn = 'sales_return';
    case PurchaseReturn = 'purchase_return';

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
            self::SalesReturn => 'Sales Return',
            self::PurchaseReturn => 'Purchase Return',
        };
    }

    public function isAddition(): bool
    {
        return match ($this) {
            self::OpeningBalance, self::PurchaseReceipt, self::AdjustmentIn, self::TransferIn, self::SalesReturn => true,
            self::SalesDelivery, self::AdjustmentOut, self::TransferOut, self::PurchaseReturn => false,
        };
    }

    public function isReduction(): bool
    {
        return ! $this->isAddition();
    }
}
