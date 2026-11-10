# Introduction

NexusERP RESTful multi-tenant ERP API for trading, retail, and wholesale.

<aside>
    <strong>Base URL</strong>: <code>http://demo.nexuserp.test</code>
</aside>

    Welcome to the NexusERP API documentation.

    NexusERP is a multi-tenant ERP platform designed for commercial, trading, wholesale, and inventory operations.

    ### Architecture & Core Conventions
    - **Tenant Isolation**: Every tenant has an isolated PostgreSQL database. Requests must target the tenant subdomain (e.g. `http://alpha.nexuserp.test/api/v1/...`).
    - **Standard Envelope**: All API responses use the standard JSON envelope:
      ```json
      {
        "success": true,
        "message": "Resource retrieved successfully.",
        "data": {},
        "meta": {}
      }
      ```
    - **Monetary Precision**: All currency amounts are represented strictly in integer minor units (`*_cents` as 64-bit BIGINT, e.g. `$100.50` = `10050`). No floats are used.
    - **Sequential Document Numbers**: Documents (Invoices, Bills, Receipts, Journals) use legal gap-free per-tenant numbering (`PREFIX-YYYY-000000`).

