<?php

declare(strict_types=1);

namespace Modules\Auth\Enums;

enum TenantPermission: string
{
    // Settings & Users
    case UsersManage = 'users.manage';
    case RolesManage = 'roles.manage';
    case SettingsManage = 'settings.manage';

    // Products & Stock
    case ProductsView = 'products.view';
    case ProductsManage = 'products.manage';
    case StockView = 'stock.view';
    case StockAdjust = 'stock.adjust';

    // Contacts
    case CustomersManage = 'customers.manage';
    case SuppliersManage = 'suppliers.manage';

    // Sales & Purchasing
    case SalesManage = 'sales.manage';
    case PurchasesManage = 'purchases.manage';
    case PurchasesPoDraft = 'purchases.po.draft';

    // Payments
    case PaymentsCustomerReceipts = 'payments.customer.receipts';
    case PaymentsSupplierBills = 'payments.supplier.bills';

    // Accounting & Tax
    case AccountingManage = 'accounting.manage';

    // Reports & Audit
    case ReportsFinancial = 'reports.financial';
    case ReportsStock = 'reports.stock';
    case AuditView = 'audit.view';

    public function label(): string
    {
        return match ($this) {
            self::UsersManage => 'Manage users',
            self::RolesManage => 'Manage roles',
            self::SettingsManage => 'Manage settings',
            self::ProductsView => 'View products',
            self::ProductsManage => 'Manage products',
            self::StockView => 'View stock',
            self::StockAdjust => 'Adjust stock',
            self::CustomersManage => 'Manage customers',
            self::SuppliersManage => 'Manage suppliers',
            self::SalesManage => 'Manage sales (quotations & invoices)',
            self::PurchasesManage => 'Manage purchases (orders & bills)',
            self::PurchasesPoDraft => 'Draft purchase orders',
            self::PaymentsCustomerReceipts => 'Manage customer receipts',
            self::PaymentsSupplierBills => 'Manage supplier bill payments',
            self::AccountingManage => 'Manage accounting (CoA, journals, notes, VAT)',
            self::ReportsFinancial => 'View financial reports',
            self::ReportsStock => 'View stock reports',
            self::AuditView => 'View audit logs',
        };
    }
}
