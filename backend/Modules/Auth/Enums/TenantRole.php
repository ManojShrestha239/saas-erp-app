<?php

declare(strict_types=1);

namespace Modules\Auth\Enums;

enum TenantRole: string
{
    case Owner = 'owner';
    case Admin = 'admin';
    case Staff = 'staff';
    case Accountant = 'accountant';
    case WarehouseManager = 'warehouse_manager';
    case SalesRep = 'sales_rep';

    public function label(): string
    {
        return match ($this) {
            self::Owner => 'Tenant Owner',
            self::Admin => 'Administrator',
            self::Staff => 'Staff',
            self::Accountant => 'Accountant',
            self::WarehouseManager => 'Warehouse Manager',
            self::SalesRep => 'Sales Representative',
        };
    }

    /**
     * @return list<TenantPermission>
     */
    public function permissions(): array
    {
        return match ($this) {
            self::Owner, self::Admin => TenantPermission::cases(),

            self::Accountant => [
                TenantPermission::ProductsView,
                TenantPermission::StockView,
                TenantPermission::CustomersManage,
                TenantPermission::SuppliersManage,
                TenantPermission::SalesManage,
                TenantPermission::PurchasesManage,
                TenantPermission::PurchasesPoDraft,
                TenantPermission::PaymentsCustomerReceipts,
                TenantPermission::PaymentsSupplierBills,
                TenantPermission::AccountingManage,
                TenantPermission::ReportsFinancial,
                TenantPermission::ReportsStock,
                TenantPermission::AuditView,
            ],

            self::WarehouseManager => [
                TenantPermission::ProductsView,
                TenantPermission::StockView,
                TenantPermission::ProductsManage,
                TenantPermission::StockAdjust,
                TenantPermission::PurchasesPoDraft,
                TenantPermission::ReportsStock,
            ],

            self::SalesRep => [
                TenantPermission::ProductsView,
                TenantPermission::StockView,
                TenantPermission::CustomersManage,
                TenantPermission::SalesManage,
                TenantPermission::PaymentsCustomerReceipts,
            ],

            self::Staff => [
                TenantPermission::ProductsView,
                TenantPermission::StockView,
            ],
        };
    }
}
