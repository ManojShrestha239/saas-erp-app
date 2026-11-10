# Performance, Indexing & Query Budgets

> **NexusERP Performance Architecture Guide**  
> Standards, database indexing strategies, query budget limits, and N+1 prevention patterns across the modular monolith.

---

## 1. Overview & Principles

NexusERP enforces strict database-per-tenant isolation on PostgreSQL 17. Because each tenant operates on an isolated database with independent connections, query performance relies on:
1. **Explicit B-Tree Indexing**: Single and composite indexes covering high-frequency search, status filter, foreign key join, and chronological range scan paths.
2. **Deterministic Query Budgets ($O(1)$)**: The number of queries executed by any paginated list or reporting calculation must remain constant regardless of the number of items or line items ($N$).
3. **Eager Loading Contracts**: Relationships rendered by API resources (`items`, `lines.account`, `warehouse`, `product`) must be explicitly eager-loaded (`with(...)`) at the service query layer.
4. **Batched Aggregations**: Aggregated financial reports (Trial Balance, AR Aging, AP Aging, VAT Returns) must aggregate in the database engine (`groupBy`, `selectRaw('SUM(...)')`) and hydrate external references in single batched lookups (`whereIn('id', $ids)`).

---

## 2. Core Tenant Database Indexes

All indexes are defined via forward-only migrations and verified via automated test suites.

| Table | Index Name | Columns | Purpose & Query Pattern |
|---|---|---|---|
| `sales_invoices` | `sales_invoices_status_due_date_idx` | `(status, due_date)` | Overdue invoice alerts (`alerts:send-overdue-invoices`) and AR aging buckets |
| `sales_invoices` | `sales_invoices_status_issue_date_idx` | `(status, issue_date)` | VAT return quarterly/monthly output tax calculations |
| `sales_invoices` | `sales_invoices_status_amount_due_idx` | `(status, amount_due_cents)` | Outstanding receivables filtering and payment allocation candidates |
| `sales_invoices` | `sales_invoices_customer_due_date_idx` | `(customer_id, due_date)` | Customer statements and individual customer aging history |
| `sales_quotations` | `sales_quotations_customer_issue_idx` | `(customer_id, issue_date)` | Customer quote history and conversion auditing |
| `sales_quotations` | `sales_quotations_status_expiry_idx` | `(status, expiry_date)` | Quotation expiration sweeps and pipeline status tracking |
| `supplier_bills` | `supplier_bills_status_due_date_idx` | `(status, due_date)` | AP aging calculations, overdue bill disbursements |
| `supplier_bills` | `supplier_bills_status_bill_date_idx` | `(status, bill_date)` | VAT return input tax credit calculations |
| `supplier_bills` | `supplier_bills_status_amount_due_idx` | `(status, amount_due_cents)` | Outstanding payables filtering and supplier payment settlement |
| `supplier_bills` | `supplier_bills_supplier_due_date_idx` | `(supplier_id, due_date)` | Supplier ledger statements and aging sorting |
| `purchase_orders` | `purchase_orders_supplier_date_idx` | `(supplier_id, order_date)` | Supplier order histories and procurement volume |
| `purchase_orders` | `purchase_orders_status_delivery_idx` | `(status, expected_delivery_date)` | Pending delivery tracking and goods receipt expediting |
| `stock_movements` | `stock_movements_source_idx` | `(source_type, source_id)` | Polymorphic source lookup (e.g. all movements for a Sales Delivery or GRN) |
| `stock_movements` | `stock_movements_warehouse_created_idx` | `(warehouse_id, created_at)` | Multi-warehouse chronological stock ledger reports |
| `stock_movements` | `stock_movements_type_created_idx` | `(type, created_at)` | Movement type filtering (Adjustments, Sales Deliveries, Receipts) |
| `stock_levels` | `stock_levels_warehouse_product_unique` | `(warehouse_id, product_id)` | Atomic row locking (`lockForUpdate()`) and $O(1)$ stock balance reads |
| `journal_entries` | `journal_entries_status_entry_date_index` | `(status, entry_date)` | General Ledger date-range posting bounds and period locks |
| `journal_entries` | `journal_entries_source_type_source_id_index` | `(source_type, source_id)` | Transaction source linkage audit (e.g. Sales Invoice to Journal Entry) |
| `journal_entry_lines` | `journal_entry_lines_entry_account_idx` | `(journal_entry_id, account_id)` | Fast joining and account balance aggregations |
| `credit_notes` | `credit_notes_status_issue_idx` | `(status, issue_date)` | Customer sales return VAT adjustments |
| `debit_notes` | `debit_notes_status_issue_idx` | `(status, issue_date)` | Supplier purchase return VAT adjustments |
| `products` | `products_active_name_idx` | `(is_active, name)` | Active product catalog search, autocomplete, and dropdown lists |
| `products` | `products_category_active_idx` | `(category_id, is_active)` | Category-filtered product listings |
| `contacts` | `contacts_type_name_idx` | `(type, name)` | Customer and supplier directory listings sorted by name |
| `activity_log` | `activity_log_name_created_idx` | `(log_name, created_at)` | Tenant audit log viewer filtered by module/log name |
| `activity_log` | `activity_log_event_created_idx` | `(event, created_at)` | Audit log viewer filtered by action (`created`, `updated`, `posted`) |

---

## 3. Query Budgets & N+1 Prevention Standards

Automated tests in `tests/Feature/Performance/QueryBudgetsTest.php` enforce deterministic query count ceilings on tenant connections.

| Endpoint / Operation | Query Budget Ceiling | Actual Queries Executed | Complexity | Eager-Loaded Relations |
|---|:---:|:---:|:---:|---|
| `GET /api/v1/sales/invoices` | $\le 4$ queries | 3 | $O(1)$ | `with('items')` |
| `GET /api/v1/purchasing/bills` | $\le 4$ queries | 3 | $O(1)$ | `with('items')` |
| `GET /api/v1/inventory/stock-movements` | $\le 5$ queries | 4 | $O(1)$ | `with(['warehouse', 'product'])` |
| `GET /api/v1/inventory/stock-levels` | $\le 5$ queries | 4 | $O(1)$ | `with(['warehouse', 'product.category', 'product.unitOfMeasure'])` |
| `GET /api/v1/accounting/journal-entries` | $\le 5$ queries | 4 | $O(1)$ | `with(['lines.account', 'reversalOf', 'reversedBy'])` |
| `FinancialReportService::getTrialBalance` | $\le 3$ queries | 2 | $O(1)$ | Aggregated line totals via `groupBy('account_id')` |
| `AgingReportService::getArAging` | $\le 3$ queries | 2 | $O(1)$ | Batched contact lookup via `Contact::whereIn('id', $ids)` |

### Key Anti-Patterns Prohibited in NexusERP:
1. **Lazy Loading Inside Loops / Resources**: Accessing `$item->product->name` in API resources without eager loading is strictly prohibited. Resources must either access scalar attributes or use `$this->whenLoaded(...)`.
2. **Non-Batched Cross-Module Hydration**: If module A needs contact names from module B, it must pass a list of IDs to `ContactService::getSummariesByIds(array $ids)`, which runs `Contact::whereIn('id', $ids)->get()`, rather than making $N$ single lookups.
3. **In-Memory Sums on High-Volume Records**: Ledger totals and VAT returns aggregate in PostgreSQL (`SUM(debit_cents)`, `SUM(credit_cents)`) rather than loading thousands of models into PHP memory.

---

## 4. Profiling & Dev Tooling

- **Laravel Telescope & Debugbar**:
  - Configured for development profiling only (`TELESCOPE_ENABLED=false` in testing and production).
  - Production deployments disable all profilers, debuggers, and query collectors to preserve maximum CPU and memory throughput.
- **Automated Regression Defense**:
  - CI test suite executes `QueryBudgetsTest.php` on fresh tenant databases, asserting both constant query counts across dataset scaling (1 record vs 6 records) and absolute query count limits.
