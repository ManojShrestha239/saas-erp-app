<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Sales Invoices
        if (Schema::connection('tenant')->hasTable('sales_invoices')) {
            Schema::connection('tenant')->table('sales_invoices', function (Blueprint $table): void {
                $table->index(['status', 'due_date'], 'sales_invoices_status_due_date_idx');
                $table->index(['status', 'issue_date'], 'sales_invoices_status_issue_date_idx');
                $table->index(['status', 'amount_due_cents'], 'sales_invoices_status_amount_due_idx');
                $table->index(['customer_id', 'due_date'], 'sales_invoices_customer_due_date_idx');
            });
        }

        // 2. Sales Quotations
        if (Schema::connection('tenant')->hasTable('sales_quotations')) {
            Schema::connection('tenant')->table('sales_quotations', function (Blueprint $table): void {
                $table->index(['customer_id', 'issue_date'], 'sales_quotations_customer_issue_idx');
                $table->index(['status', 'expiry_date'], 'sales_quotations_status_expiry_idx');
            });
        }

        // 3. Supplier Bills
        if (Schema::connection('tenant')->hasTable('supplier_bills')) {
            Schema::connection('tenant')->table('supplier_bills', function (Blueprint $table): void {
                $table->index(['status', 'due_date'], 'supplier_bills_status_due_date_idx');
                $table->index(['status', 'bill_date'], 'supplier_bills_status_bill_date_idx');
                $table->index(['status', 'amount_due_cents'], 'supplier_bills_status_amount_due_idx');
                $table->index(['supplier_id', 'due_date'], 'supplier_bills_supplier_due_date_idx');
            });
        }

        // 4. Purchase Orders
        if (Schema::connection('tenant')->hasTable('purchase_orders')) {
            Schema::connection('tenant')->table('purchase_orders', function (Blueprint $table): void {
                $table->index(['supplier_id', 'order_date'], 'purchase_orders_supplier_date_idx');
                $table->index(['status', 'expected_delivery_date'], 'purchase_orders_status_delivery_idx');
            });
        }

        // 5. Stock Movements
        if (Schema::connection('tenant')->hasTable('stock_movements')) {
            Schema::connection('tenant')->table('stock_movements', function (Blueprint $table): void {
                $table->index(['source_type', 'source_id'], 'stock_movements_source_idx');
                $table->index(['warehouse_id', 'created_at'], 'stock_movements_warehouse_created_idx');
                $table->index(['type', 'created_at'], 'stock_movements_type_created_idx');
            });
        }

        // 6. Journal Entry Lines
        if (Schema::connection('tenant')->hasTable('journal_entry_lines')) {
            Schema::connection('tenant')->table('journal_entry_lines', function (Blueprint $table): void {
                $table->index(['journal_entry_id', 'account_id'], 'journal_entry_lines_entry_account_idx');
            });
        }

        // 7. Credit Notes
        if (Schema::connection('tenant')->hasTable('credit_notes')) {
            Schema::connection('tenant')->table('credit_notes', function (Blueprint $table): void {
                $table->index(['status', 'issue_date'], 'credit_notes_status_issue_idx');
            });
        }

        // 8. Debit Notes
        if (Schema::connection('tenant')->hasTable('debit_notes')) {
            Schema::connection('tenant')->table('debit_notes', function (Blueprint $table): void {
                $table->index(['status', 'issue_date'], 'debit_notes_status_issue_idx');
            });
        }

        // 9. Products
        if (Schema::connection('tenant')->hasTable('products')) {
            Schema::connection('tenant')->table('products', function (Blueprint $table): void {
                $table->index(['is_active', 'name'], 'products_active_name_idx');
                $table->index(['category_id', 'is_active'], 'products_category_active_idx');
            });
        }

        // 10. Contacts
        if (Schema::connection('tenant')->hasTable('contacts')) {
            Schema::connection('tenant')->table('contacts', function (Blueprint $table): void {
                $table->index(['type', 'name'], 'contacts_type_name_idx');
            });
        }

        // 11. Activity Log
        if (Schema::connection('tenant')->hasTable('activity_log')) {
            Schema::connection('tenant')->table('activity_log', function (Blueprint $table): void {
                $table->index(['log_name', 'created_at'], 'activity_log_name_created_idx');
                $table->index(['event', 'created_at'], 'activity_log_event_created_idx');
            });
        }
    }

    public function down(): void
    {
        if (Schema::connection('tenant')->hasTable('sales_invoices')) {
            Schema::connection('tenant')->table('sales_invoices', function (Blueprint $table): void {
                $table->dropIndex('sales_invoices_status_due_date_idx');
                $table->dropIndex('sales_invoices_status_issue_date_idx');
                $table->dropIndex('sales_invoices_status_amount_due_idx');
                $table->dropIndex('sales_invoices_customer_due_date_idx');
            });
        }

        if (Schema::connection('tenant')->hasTable('sales_quotations')) {
            Schema::connection('tenant')->table('sales_quotations', function (Blueprint $table): void {
                $table->dropIndex('sales_quotations_customer_issue_idx');
                $table->dropIndex('sales_quotations_status_expiry_idx');
            });
        }

        if (Schema::connection('tenant')->hasTable('supplier_bills')) {
            Schema::connection('tenant')->table('supplier_bills', function (Blueprint $table): void {
                $table->dropIndex('supplier_bills_status_due_date_idx');
                $table->dropIndex('supplier_bills_status_bill_date_idx');
                $table->dropIndex('supplier_bills_status_amount_due_idx');
                $table->dropIndex('supplier_bills_supplier_due_date_idx');
            });
        }

        if (Schema::connection('tenant')->hasTable('purchase_orders')) {
            Schema::connection('tenant')->table('purchase_orders', function (Blueprint $table): void {
                $table->dropIndex('purchase_orders_supplier_date_idx');
                $table->dropIndex('purchase_orders_status_delivery_idx');
            });
        }

        if (Schema::connection('tenant')->hasTable('stock_movements')) {
            Schema::connection('tenant')->table('stock_movements', function (Blueprint $table): void {
                $table->dropIndex('stock_movements_source_idx');
                $table->dropIndex('stock_movements_warehouse_created_idx');
                $table->dropIndex('stock_movements_type_created_idx');
            });
        }

        if (Schema::connection('tenant')->hasTable('journal_entry_lines')) {
            Schema::connection('tenant')->table('journal_entry_lines', function (Blueprint $table): void {
                $table->dropIndex('journal_entry_lines_entry_account_idx');
            });
        }

        if (Schema::connection('tenant')->hasTable('credit_notes')) {
            Schema::connection('tenant')->table('credit_notes', function (Blueprint $table): void {
                $table->dropIndex('credit_notes_status_issue_idx');
            });
        }

        if (Schema::connection('tenant')->hasTable('debit_notes')) {
            Schema::connection('tenant')->table('debit_notes', function (Blueprint $table): void {
                $table->dropIndex('debit_notes_status_issue_idx');
            });
        }

        if (Schema::connection('tenant')->hasTable('products')) {
            Schema::connection('tenant')->table('products', function (Blueprint $table): void {
                $table->dropIndex('products_active_name_idx');
                $table->dropIndex('products_category_active_idx');
            });
        }

        if (Schema::connection('tenant')->hasTable('contacts')) {
            Schema::connection('tenant')->table('contacts', function (Blueprint $table): void {
                $table->dropIndex('contacts_type_name_idx');
            });
        }

        if (Schema::connection('tenant')->hasTable('activity_log')) {
            Schema::connection('tenant')->table('activity_log', function (Blueprint $table): void {
                $table->dropIndex('activity_log_name_created_idx');
                $table->dropIndex('activity_log_event_created_idx');
            });
        }
    }
};
