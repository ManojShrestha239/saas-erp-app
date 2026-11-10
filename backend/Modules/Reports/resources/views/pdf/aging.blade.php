@extends('shared::pdf.layout')

@section('title', ($type === 'ar' ? 'AR Aging Report' : 'AP Aging Report') . ' - ' . ($asOfDate ?? 'Current'))

@section('content')
    @php
        $isAr = ($type ?? 'ar') === 'ar';
        $title = $isAr ? 'ACCOUNTS RECEIVABLE AGING' : 'ACCOUNTS PAYABLE AGING';
        $contactLabel = $isAr ? 'Customer' : 'Supplier';
        $records = $isAr ? ($data['customers'] ?? []) : ($data['suppliers'] ?? []);
        $summary = $data['summary'] ?? [];
        $glBalance = (int) ($data['gl_control_account_cents'] ?? 0);
        $diff = (int) ($data['reconciliation_difference_cents'] ?? 0);
        $isReconciled = !empty($data['is_reconciled']);
        $controlCode = $isAr ? '1130' : '2110';
    @endphp

    <table class="header-table">
        <tr>
            <td style="width: 55%;">
                <div class="company-title">{{ $company['name'] ?? 'NexusERP' }}</div>
                <div class="company-meta">
                    @if(!empty($company['address']))
                        {{ $company['address'] }}<br>
                    @endif
                    @if(!empty($company['tax_id']))
                        <strong>PAN / VAT ID:</strong> {{ $company['tax_id'] }}<br>
                    @endif
                </div>
            </td>
            <td class="doc-title-block" style="width: 45%;">
                <div class="doc-type">{{ $title }}</div>
                <div style="font-size: 11px; color: #64748b; margin-top: 4px;">
                    <strong>As of Date:</strong> {{ $asOfDate ?? ($data['as_of_date'] ?? 'Current') }}<br>
                    <strong>GL Status:</strong> {{ $isReconciled ? 'Reconciled with GL' : 'Discrepancy (Diff: ' . number_format($diff / 100, 2) . ')' }}
                </div>
            </td>
        </tr>
    </table>

    <table class="line-items-table" style="width: 100%; border-collapse: collapse; margin-top: 16px;">
        <thead>
            <tr style="background-color: #f8fafc; border-bottom: 2px solid #cbd5e1; font-size: 10px; text-transform: uppercase;">
                <th style="padding: 6px 8px; text-align: left; width: 10%;">Code</th>
                <th style="padding: 6px 8px; text-align: left; width: 22%;">{{ $contactLabel }}</th>
                <th style="padding: 6px 8px; text-align: right; width: 11%;">Current</th>
                <th style="padding: 6px 8px; text-align: right; width: 11%;">1–30 D</th>
                <th style="padding: 6px 8px; text-align: right; width: 11%;">31–60 D</th>
                <th style="padding: 6px 8px; text-align: right; width: 11%;">61–90 D</th>
                <th style="padding: 6px 8px; text-align: right; width: 11%;">90+ D</th>
                <th style="padding: 6px 8px; text-align: right; width: 13%;">Total Due</th>
            </tr>
        </thead>
        <tbody>
            @forelse($records as $row)
                @php
                    $code = $isAr ? ($row['customer_code'] ?? '') : ($row['supplier_code'] ?? '');
                    $name = $isAr ? ($row['customer_name'] ?? '') : ($row['supplier_name'] ?? '');
                @endphp
                <tr style="border-bottom: 1px solid #e2e8f0; font-size: 10px;">
                    <td style="padding: 5px 8px; font-family: monospace; font-weight: 600;">{{ $code }}</td>
                    <td style="padding: 5px 8px;">{{ $name }}</td>
                    <td style="padding: 5px 8px; text-align: right; font-family: monospace;">{{ number_format(($row['current_cents'] ?? 0) / 100, 2) }}</td>
                    <td style="padding: 5px 8px; text-align: right; font-family: monospace;">{{ number_format(($row['days_1_30_cents'] ?? 0) / 100, 2) }}</td>
                    <td style="padding: 5px 8px; text-align: right; font-family: monospace;">{{ number_format(($row['days_31_60_cents'] ?? 0) / 100, 2) }}</td>
                    <td style="padding: 5px 8px; text-align: right; font-family: monospace;">{{ number_format(($row['days_61_90_cents'] ?? 0) / 100, 2) }}</td>
                    <td style="padding: 5px 8px; text-align: right; font-family: monospace;">{{ number_format(($row['days_over_90_cents'] ?? 0) / 100, 2) }}</td>
                    <td style="padding: 5px 8px; text-align: right; font-family: monospace; font-weight: 600;">{{ number_format(($row['total_due_cents'] ?? 0) / 100, 2) }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="8" style="padding: 16px; text-align: center; color: #64748b; font-size: 11px;">
                        No outstanding aging records found.
                    </td>
                </tr>
            @endforelse
        </tbody>
        <tfoot>
            <tr style="background-color: #f1f5f9; font-weight: bold; font-size: 10px; border-top: 2px solid #0f172a;">
                <td colspan="2" style="padding: 6px 8px; text-transform: uppercase;">Summary Totals</td>
                <td style="padding: 6px 8px; text-align: right; font-family: monospace;">{{ number_format(($summary['current_cents'] ?? 0) / 100, 2) }}</td>
                <td style="padding: 6px 8px; text-align: right; font-family: monospace;">{{ number_format(($summary['days_1_30_cents'] ?? 0) / 100, 2) }}</td>
                <td style="padding: 6px 8px; text-align: right; font-family: monospace;">{{ number_format(($summary['days_31_60_cents'] ?? 0) / 100, 2) }}</td>
                <td style="padding: 6px 8px; text-align: right; font-family: monospace;">{{ number_format(($summary['days_61_90_cents'] ?? 0) / 100, 2) }}</td>
                <td style="padding: 6px 8px; text-align: right; font-family: monospace;">{{ number_format(($summary['days_over_90_cents'] ?? 0) / 100, 2) }}</td>
                <td style="padding: 6px 8px; text-align: right; font-family: monospace;">{{ number_format(($summary['total_due_cents'] ?? 0) / 100, 2) }}</td>
            </tr>
        </tfoot>
    </table>

    <div style="margin-top: 20px; padding: 12px; background-color: #f8fafc; border: 1px solid #e2e8f0; border-radius: 4px; font-size: 11px;">
        <div style="font-weight: bold; text-transform: uppercase; color: #334155; margin-bottom: 6px;">General Ledger Reconciliation</div>
        <table style="width: 100%; border-collapse: collapse;">
            <tr>
                <td style="color: #64748b; width: 35%;">Control Account ({{ $controlCode }}):</td>
                <td style="font-family: monospace; font-weight: 600; text-align: right; width: 15%;">{{ number_format($glBalance / 100, 2) }}</td>
                <td style="color: #64748b; width: 35%; padding-left: 20px;">Subledger Total Due:</td>
                <td style="font-family: monospace; font-weight: 600; text-align: right; width: 15%;">{{ number_format(($summary['total_due_cents'] ?? 0) / 100, 2) }}</td>
            </tr>
            <tr>
                <td style="color: #64748b;">Reconciliation Variance:</td>
                <td style="font-family: monospace; font-weight: 600; text-align: right; color: {{ $diff === 0 ? '#16a34a' : '#dc2626' }};">
                    {{ number_format($diff / 100, 2) }}
                </td>
                <td style="color: #64748b; padding-left: 20px;">Reconciliation Status:</td>
                <td style="font-weight: bold; text-align: right; color: {{ $isReconciled ? '#16a34a' : '#dc2626' }};">
                    {{ $isReconciled ? 'RECONCILED' : 'DISCREPANCY' }}
                </td>
            </tr>
        </table>
    </div>
@endsection
