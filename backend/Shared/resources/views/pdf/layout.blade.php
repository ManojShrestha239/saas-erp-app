<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('title', 'Document')</title>
    <style>
        @page {
            size: A4 portrait;
            margin: 12mm 15mm 15mm 15mm;
        }

        *, *::before, *::after {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            color: #0f172a;
            background-color: #ffffff;
            font-size: 12px;
            line-height: 1.5;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }

        .document-container {
            width: 100%;
            max-width: 800px;
            margin: 0 auto;
        }

        .header-table {
            width: 100%;
            margin-bottom: 24px;
            border-collapse: collapse;
        }

        .header-table td {
            vertical-align: top;
        }

        .company-title {
            font-size: 20px;
            font-weight: 700;
            color: #0f172a;
            letter-spacing: -0.02em;
            margin-bottom: 4px;
        }

        .company-meta {
            font-size: 11px;
            color: #475569;
            line-height: 1.4;
        }

        .doc-title-block {
            text-align: right;
        }

        .doc-type {
            font-size: 22px;
            font-weight: 800;
            color: #1e3a8a;
            letter-spacing: 0.05em;
            text-transform: uppercase;
            margin-bottom: 4px;
        }

        .doc-number {
            font-size: 13px;
            font-weight: 600;
            color: #0f172a;
            font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
        }

        .status-badge {
            display: inline-block;
            font-size: 10px;
            font-weight: 700;
            padding: 2px 8px;
            border-radius: 4px;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            margin-top: 6px;
        }

        .badge-draft { background-color: #f1f5f9; color: #475569; border: 1px solid #cbd5e1; }
        .badge-sent, .badge-approved, .badge-posted { background-color: #eff6ff; color: #1d4ed8; border: 1px solid #bfdbfe; }
        .badge-accepted, .badge-paid, .badge-received { background-color: #ecfdf5; color: #047857; border: 1px solid #a7f3d0; }
        .badge-partially_paid, .badge-partially_received { background-color: #fefce8; color: #a16207; border: 1px solid #fef08a; }
        .badge-rejected, .badge-void, .badge-cancelled { background-color: #fef2f2; color: #b91c1c; border: 1px solid #fecaca; }

        .meta-grid {
            width: 100%;
            margin-bottom: 24px;
            border-collapse: collapse;
        }

        .meta-grid td {
            vertical-align: top;
            width: 50%;
        }

        .card-box {
            background-color: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 6px;
            padding: 12px 14px;
            min-height: 110px;
        }

        .card-box-right {
            margin-left: 10px;
        }

        .card-box-left {
            margin-right: 10px;
        }

        .card-label {
            font-size: 10px;
            font-weight: 700;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            margin-bottom: 6px;
        }

        .card-name {
            font-size: 13px;
            font-weight: 700;
            color: #0f172a;
            margin-bottom: 3px;
        }

        .card-text {
            font-size: 11px;
            color: #475569;
            line-height: 1.4;
        }

        .items-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 24px;
        }

        .items-table th {
            background-color: #f1f5f9;
            color: #334155;
            font-size: 10px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            padding: 8px 10px;
            border-top: 1px solid #cbd5e1;
            border-bottom: 2px solid #cbd5e1;
            text-align: left;
        }

        .items-table th.text-right {
            text-align: right;
        }

        .items-table th.text-center {
            text-align: center;
        }

        .items-table td {
            padding: 9px 10px;
            border-bottom: 1px solid #e2e8f0;
            font-size: 11px;
            vertical-align: middle;
        }

        .items-table td.text-right {
            text-align: right;
        }

        .items-table td.text-center {
            text-align: center;
        }

        .items-table tbody tr:nth-child(even) {
            background-color: #fafafa;
        }

        .summary-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 24px;
        }

        .summary-table td {
            vertical-align: top;
        }

        .notes-column {
            width: 55%;
            padding-right: 20px;
        }

        .totals-column {
            width: 45%;
        }

        .totals-card {
            border: 1px solid #e2e8f0;
            border-radius: 6px;
            overflow: hidden;
        }

        .totals-row {
            display: flex;
            justify-content: space-between;
            padding: 6px 12px;
            font-size: 11px;
            color: #475569;
            border-bottom: 1px solid #f1f5f9;
        }

        .totals-row-grand {
            display: flex;
            justify-content: space-between;
            padding: 8px 12px;
            font-size: 13px;
            font-weight: 700;
            color: #0f172a;
            background-color: #f8fafc;
            border-top: 1px solid #cbd5e1;
            border-bottom: 1px solid #cbd5e1;
        }

        .totals-row-due {
            display: flex;
            justify-content: space-between;
            padding: 8px 12px;
            font-size: 13px;
            font-weight: 800;
            color: #1e3a8a;
            background-color: #eff6ff;
        }

        .notes-card {
            background-color: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 6px;
            padding: 10px 12px;
            font-size: 11px;
            color: #475569;
            line-height: 1.4;
        }

        .notes-card strong {
            color: #0f172a;
        }

        .footer {
            margin-top: 30px;
            padding-top: 12px;
            border-top: 1px solid #e2e8f0;
            font-size: 10px;
            color: #94a3b8;
            text-align: center;
        }

        .signature-grid {
            width: 100%;
            margin-top: 40px;
            margin-bottom: 20px;
            border-collapse: collapse;
        }

        .signature-grid td {
            width: 50%;
            vertical-align: bottom;
            padding: 0 20px;
        }

        .signature-line {
            border-top: 1px solid #94a3b8;
            padding-top: 6px;
            font-size: 11px;
            font-weight: 600;
            color: #475569;
            text-align: center;
        }

        .signature-sub {
            font-size: 9px;
            color: #94a3b8;
            text-align: center;
        }
    </style>
</head>
<body>
    <div class="document-container">
        @yield('content')

        <div class="footer">
            NexusERP &bull; Computer-Generated Document &bull; Generated on {{ now()->format('Y-m-d H:i:s') }}
        </div>
    </div>
</body>
</html>
