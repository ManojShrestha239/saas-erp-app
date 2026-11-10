@extends('shared::pdf.layout')

@section('title', 'Stock Valuation Report - ' . ($asOfDate ?? 'Current'))

@section('content')
    @php
        $items = $data['items'] ?? [];
        $summary = $data['summary'] ?? [];
        $glBalance = (int) ($summary['gl_control_account_cents'] ?? 0);
        $diff = (int) ($summary['reconciliation_difference_cents'] ?? 0);
        $isReconciled = !empty($summary['is_reconciled']);
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
                <div class="doc-type">STOCK VALUATION REPORT</div>
                <div style="font-size: 11px; color: #64748b; margin-top: 4px;">
                    <strong>As of Date:</strong> {{ $asOfDate ?? ($data['as_of_date'] ?? 'Current') }}<br>
                    <strong>Costing Method:</strong> Moving Average (Weighted)<br>
                    <strong>GL Status:</strong> {{ $isReconciled ? 'Reconciled with GL' : 'Discrepancy' }}
                </div>
            </td>
        </tr>
    </table>

    <table class="line-items-table" style="width: 100%; border-collapse: collapse; margin-top: 16px;">
        <thead>
            <tr style="background-color: #f8fafc; border-bottom: 2px solid #cbd5e1; font-size: 10px; text-transform: uppercase;">
                <th style="padding: 6px 8px; text-align: left; width: 14%;">SKU</th>
                <th style="padding: 6px 8px; text-align: left;">Product Name</th>
                <th style="padding: 6px 8px; text-align: left; width: 16%;">Category</th>
                <th style="padding: 6px 8px; text-align: right; width: 10%;">Qty</th>
                <th style="padding: 6px 8px; text-align: right; width: 14%;">Avg Cost</th>
                <th style="padding: 6px 8px; text-align: right; width: 16%;">Total Valuation</th>
            </tr>
        </thead>
        <tbody>
            @forelse($items as $item)
                <tr style="border-bottom: 1px solid #e2e8f0; font-size: 10px;">
                    <td style="padding: 5px 8px; font-family: monospace; font-weight: 600;">{{ $item['sku'] ?? '—' }}</td>
                    <td style="padding: 5px 8px;">{{ $item['name'] ?? '—' }}</td>
                    <td style="padding: 5px 8px; color: #64748b;">{{ $item['category_name'] ?? '—' }}</td>
                    <td style="padding: 5px 8px; text-align: right; font-family: monospace;">{{ $item['total_quantity_on_hand'] ?? 0 }}</td>
                    <td style="padding: 5px 8px; text-align: right; font-family: monospace;">{{ number_format(($item['moving_average_cost_cents'] ?? 0) / 100, 2) }}</td>
                    <td style="padding: 5px 8px; text-align: right; font-family: monospace; font-weight: 600;">{{ number_format(($item['total_valuation_cents'] ?? 0) / 100, 2) }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="6" style="padding: 16px; text-align: center; color: #64748b; font-size: 11px;">
                        No inventory items found.
                    </td>
                </tr>
            @endforelse
        </tbody>
        <tfoot>
            <tr style="background-color: #f1f5f9; font-weight: bold; font-size: 10px; border-top: 2px solid #0f172a;">
                <td colspan="3" style="padding: 6px 8px; text-transform: uppercase;">Total Valuation</td>
                <td style="padding: 6px 8px; text-align: right; font-family: monospace;">{{ $summary['total_units_on_hand'] ?? 0 }}</td>
                <td style="padding: 6px 8px; text-align: right; font-family: monospace;">—</td>
                <td style="padding: 6px 8px; text-align: right; font-family: monospace;">{{ number_format(($summary['total_valuation_cents'] ?? 0) / 100, 2) }}</td>
            </tr>
        </tfoot>
    </table>

    <div style="margin-top: 20px; padding: 12px; background-color: #f8fafc; border: 1px solid #e2e8f0; border-radius: 4px; font-size: 11px;">
        <div style="font-weight: bold; text-transform: uppercase; color: #334155; margin-bottom: 6px;">General Ledger Reconciliation</div>
        <table style="width: 100%; border-collapse: collapse;">
            <tr>
                <td style="color: #64748b; width: 35%;">Control Account 1150 (Inventory Asset):</td>
                <td style="font-family: monospace; font-weight: 600; text-align: right; width: 15%;">{{ number_format($glBalance / 100, 2) }}</td>
                <td style="color: #64748b; width: 35%; padding-left: 20px;">Inventory Subledger Valuation:</td>
                <td style="font-family: monospace; font-weight: 600; text-align: right; width: 15%;">{{ number_format(($summary['total_valuation_cents'] ?? 0) / 100, 2) }}</td>
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
