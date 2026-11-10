@extends('shared::pdf.layout')

@section('title', 'Stock Movements Audit Report')

@section('content')
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
                <div class="doc-type">STOCK MOVEMENTS REPORT</div>
                <div style="font-size: 11px; color: #64748b; margin-top: 4px;">
                    <strong>Generated At:</strong> {{ now()->toDateTimeString() }}<br>
                    <strong>Total Movements:</strong> {{ count($items) }}
                </div>
            </td>
        </tr>
    </table>

    <table class="line-items-table" style="width: 100%; border-collapse: collapse; margin-top: 16px;">
        <thead>
            <tr style="background-color: #f8fafc; border-bottom: 2px solid #cbd5e1; font-size: 10px; text-transform: uppercase;">
                <th style="padding: 6px 8px; text-align: left; width: 14%;">Date / Time</th>
                <th style="padding: 6px 8px; text-align: left; width: 20%;">Product</th>
                <th style="padding: 6px 8px; text-align: left; width: 14%;">Warehouse</th>
                <th style="padding: 6px 8px; text-align: left; width: 14%;">Type</th>
                <th style="padding: 6px 8px; text-align: right; width: 10%;">Qty</th>
                <th style="padding: 6px 8px; text-align: right; width: 12%;">Unit Cost</th>
                <th style="padding: 6px 8px; text-align: left; width: 16%;">Notes</th>
            </tr>
        </thead>
        <tbody>
            @forelse($items as $row)
                @php
                    $product = $row['product'] ?? null;
                    $warehouse = $row['warehouse'] ?? null;
                    $qty = (int) ($row['quantity'] ?? 0);
                    $isPositive = $qty > 0;
                @endphp
                <tr style="border-bottom: 1px solid #e2e8f0; font-size: 10px;">
                    <td style="padding: 5px 8px; font-family: monospace; color: #64748b;">
                        {{ \Carbon\Carbon::parse($row['created_at'] ?? now())->format('Y-m-d H:i') }}
                    </td>
                    <td style="padding: 5px 8px;">
                        <span style="font-weight: 600;">{{ $product['name'] ?? $row['product_id'] ?? '—' }}</span>
                        @if(!empty($product['sku']))
                            <div style="font-size: 9px; color: #64748b; font-family: monospace;">{{ $product['sku'] }}</div>
                        @endif
                    </td>
                    <td style="padding: 5px 8px; color: #334155;">{{ $warehouse['name'] ?? $row['warehouse_id'] ?? '—' }}</td>
                    <td style="padding: 5px 8px;">
                        <span style="padding: 2px 6px; background-color: #f1f5f9; border-radius: 3px; font-size: 9px; font-weight: 600; text-transform: uppercase;">
                            {{ $row['type_label'] ?? $row['type'] ?? 'Movement' }}
                        </span>
                    </td>
                    <td style="padding: 5px 8px; text-align: right; font-family: monospace; font-weight: 600; color: {{ $isPositive ? '#16a34a' : '#dc2626' }};">
                        {{ $isPositive ? '+' . $qty : $qty }}
                    </td>
                    <td style="padding: 5px 8px; text-align: right; font-family: monospace;">
                        {{ number_format(($row['unit_cost_cents'] ?? 0) / 100, 2) }}
                    </td>
                    <td style="padding: 5px 8px; font-size: 9px; color: #64748b;">
                        {{ $row['notes'] ?? '—' }}
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="7" style="padding: 16px; text-align: center; color: #64748b; font-size: 11px;">
                        No stock movement records found.
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>
@endsection
