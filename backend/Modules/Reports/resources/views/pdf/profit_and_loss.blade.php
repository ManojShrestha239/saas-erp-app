@extends('shared::pdf.layout')

@section('title', 'Profit & Loss Statement - ' . $startDate . ' to ' . $endDate)

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
                <div class="doc-type">PROFIT & LOSS</div>
                <div style="font-size: 11px; color: #64748b; margin-top: 4px;">
                    <strong>Period:</strong> {{ $startDate }} to {{ $endDate }}
                </div>
            </td>
        </tr>
    </table>

    <table class="line-items-table" style="width: 100%; border-collapse: collapse; margin-top: 16px;">
        <thead>
            <tr style="background-color: #f8fafc; border-bottom: 2px solid #cbd5e1; font-size: 10px; text-transform: uppercase;">
                <th style="padding: 8px; text-align: left; width: 15%;">Code</th>
                <th style="padding: 8px; text-align: left;">Category / Description</th>
                <th style="padding: 8px; text-align: right; width: 25%;">Amount</th>
            </tr>
        </thead>
        <tbody>
            <!-- 1. Revenue -->
            <tr style="background-color: #f1f5f9; font-weight: bold; font-size: 11px;">
                <td colspan="2" style="padding: 6px 8px; text-transform: uppercase;">1. Operating Revenue</td>
                <td style="padding: 6px 8px; text-align: right; font-family: monospace;">
                    {{ number_format($report->totalRevenueCents / 100, 2) }}
                </td>
            </tr>
            @foreach($report->revenueAccounts as $acc)
                <tr style="border-bottom: 1px solid #f1f5f9; font-size: 11px;">
                    <td style="padding: 4px 8px; font-family: monospace; color: #64748b;">{{ $acc['code'] }}</td>
                    <td style="padding: 4px 8px; padding-left: 20px;">{{ $acc['name'] }}</td>
                    <td style="padding: 4px 8px; text-align: right; font-family: monospace;">
                        {{ number_format($acc['amount_cents'] / 100, 2) }}
                    </td>
                </tr>
            @endforeach

            <!-- 2. Cost of Goods Sold -->
            <tr style="background-color: #f1f5f9; font-weight: bold; font-size: 11px;">
                <td colspan="2" style="padding: 6px 8px; text-transform: uppercase;">2. Cost of Goods Sold</td>
                <td style="padding: 6px 8px; text-align: right; font-family: monospace;">
                    {{ number_format($report->totalCogsCents / 100, 2) }}
                </td>
            </tr>
            @foreach($report->cogsAccounts as $acc)
                <tr style="border-bottom: 1px solid #f1f5f9; font-size: 11px;">
                    <td style="padding: 4px 8px; font-family: monospace; color: #64748b;">{{ $acc['code'] }}</td>
                    <td style="padding: 4px 8px; padding-left: 20px;">{{ $acc['name'] }}</td>
                    <td style="padding: 4px 8px; text-align: right; font-family: monospace;">
                        {{ number_format($acc['amount_cents'] / 100, 2) }}
                    </td>
                </tr>
            @endforeach

            <!-- Gross Profit -->
            <tr style="background-color: #e0f2fe; font-weight: bold; font-size: 11px; border-top: 1px solid #0284c7; border-bottom: 1px solid #0284c7;">
                <td colspan="2" style="padding: 6px 8px; text-transform: uppercase; color: #0369a1;">Gross Profit (Revenue - COGS)</td>
                <td style="padding: 6px 8px; text-align: right; font-family: monospace; color: #0369a1;">
                    {{ number_format($report->grossProfitCents / 100, 2) }}
                </td>
            </tr>

            <!-- 3. Operating Expenses -->
            <tr style="background-color: #f1f5f9; font-weight: bold; font-size: 11px;">
                <td colspan="2" style="padding: 6px 8px; text-transform: uppercase;">3. Operating Expenses</td>
                <td style="padding: 6px 8px; text-align: right; font-family: monospace;">
                    {{ number_format($report->totalOperatingExpensesCents / 100, 2) }}
                </td>
            </tr>
            @foreach($report->expenseAccounts as $acc)
                <tr style="border-bottom: 1px solid #f1f5f9; font-size: 11px;">
                    <td style="padding: 4px 8px; font-family: monospace; color: #64748b;">{{ $acc['code'] }}</td>
                    <td style="padding: 4px 8px; padding-left: 20px;">{{ $acc['name'] }}</td>
                    <td style="padding: 4px 8px; text-align: right; font-family: monospace;">
                        {{ number_format($acc['amount_cents'] / 100, 2) }}
                    </td>
                </tr>
            @endforeach

            <!-- Net Income -->
            <tr style="background-color: {{ $report->netIncomeCents >= 0 ? '#dcfce7' : '#fee2e2' }}; font-weight: bold; font-size: 12px; border-top: 2px solid #0f172a;">
                <td colspan="2" style="padding: 8px; text-transform: uppercase;">Net Income / (Loss)</td>
                <td style="padding: 8px; text-align: right; font-family: monospace;">
                    {{ number_format($report->netIncomeCents / 100, 2) }}
                </td>
            </tr>
        </tbody>
    </table>
@endsection
