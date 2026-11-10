@extends('shared::pdf.layout')

@section('title', 'Balance Sheet - ' . $asOfDate)

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
                <div class="doc-type">BALANCE SHEET</div>
                <div style="font-size: 11px; color: #64748b; margin-top: 4px;">
                    <strong>As of Date:</strong> {{ $asOfDate }}<br>
                    <strong>Status:</strong> {{ $report->isBalanced ? 'Balanced' : 'Imbalanced (Diff: ' . number_format($report->netDifferenceCents / 100, 2) . ')' }}
                </div>
            </td>
        </tr>
    </table>

    <table class="line-items-table" style="width: 100%; border-collapse: collapse; margin-top: 16px;">
        <thead>
            <tr style="background-color: #f8fafc; border-bottom: 2px solid #cbd5e1; font-size: 10px; text-transform: uppercase;">
                <th style="padding: 8px; text-align: left; width: 15%;">Code</th>
                <th style="padding: 8px; text-align: left;">Category / Account</th>
                <th style="padding: 8px; text-align: right; width: 25%;">Amount</th>
            </tr>
        </thead>
        <tbody>
            <!-- 1. Assets -->
            <tr style="background-color: #f1f5f9; font-weight: bold; font-size: 11px;">
                <td colspan="2" style="padding: 6px 8px; text-transform: uppercase;">1. ASSETS</td>
                <td style="padding: 6px 8px; text-align: right; font-family: monospace;">
                    {{ number_format($report->assets['total_cents'] / 100, 2) }}
                </td>
            </tr>
            <tr style="font-weight: 600; font-size: 11px; color: #475569;">
                <td colspan="3" style="padding: 4px 8px; padding-left: 12px;">Current Assets</td>
            </tr>
            @foreach($report->assets['current'] as $acc)
                <tr style="border-bottom: 1px solid #f1f5f9; font-size: 11px;">
                    <td style="padding: 4px 8px; font-family: monospace; color: #64748b;">{{ $acc['code'] }}</td>
                    <td style="padding: 4px 8px; padding-left: 24px;">{{ $acc['name'] }}</td>
                    <td style="padding: 4px 8px; text-align: right; font-family: monospace;">
                        {{ number_format($acc['amount_cents'] / 100, 2) }}
                    </td>
                </tr>
            @endforeach
            <tr style="font-weight: 600; font-size: 11px; color: #475569;">
                <td colspan="3" style="padding: 4px 8px; padding-left: 12px;">Non-Current Assets</td>
            </tr>
            @foreach($report->assets['non_current'] as $acc)
                <tr style="border-bottom: 1px solid #f1f5f9; font-size: 11px;">
                    <td style="padding: 4px 8px; font-family: monospace; color: #64748b;">{{ $acc['code'] }}</td>
                    <td style="padding: 4px 8px; padding-left: 24px;">{{ $acc['name'] }}</td>
                    <td style="padding: 4px 8px; text-align: right; font-family: monospace;">
                        {{ number_format($acc['amount_cents'] / 100, 2) }}
                    </td>
                </tr>
            @endforeach

            <!-- 2. Liabilities -->
            <tr style="background-color: #f1f5f9; font-weight: bold; font-size: 11px;">
                <td colspan="2" style="padding: 6px 8px; text-transform: uppercase;">2. LIABILITIES</td>
                <td style="padding: 6px 8px; text-align: right; font-family: monospace;">
                    {{ number_format($report->liabilities['total_cents'] / 100, 2) }}
                </td>
            </tr>
            <tr style="font-weight: 600; font-size: 11px; color: #475569;">
                <td colspan="3" style="padding: 4px 8px; padding-left: 12px;">Current Liabilities</td>
            </tr>
            @foreach($report->liabilities['current'] as $acc)
                <tr style="border-bottom: 1px solid #f1f5f9; font-size: 11px;">
                    <td style="padding: 4px 8px; font-family: monospace; color: #64748b;">{{ $acc['code'] }}</td>
                    <td style="padding: 4px 8px; padding-left: 24px;">{{ $acc['name'] }}</td>
                    <td style="padding: 4px 8px; text-align: right; font-family: monospace;">
                        {{ number_format($acc['amount_cents'] / 100, 2) }}
                    </td>
                </tr>
            @endforeach
            <tr style="font-weight: 600; font-size: 11px; color: #475569;">
                <td colspan="3" style="padding: 4px 8px; padding-left: 12px;">Long-Term Liabilities</td>
            </tr>
            @foreach($report->liabilities['non_current'] as $acc)
                <tr style="border-bottom: 1px solid #f1f5f9; font-size: 11px;">
                    <td style="padding: 4px 8px; font-family: monospace; color: #64748b;">{{ $acc['code'] }}</td>
                    <td style="padding: 4px 8px; padding-left: 24px;">{{ $acc['name'] }}</td>
                    <td style="padding: 4px 8px; text-align: right; font-family: monospace;">
                        {{ number_format($acc['amount_cents'] / 100, 2) }}
                    </td>
                </tr>
            @endforeach

            <!-- 3. Equity -->
            <tr style="background-color: #f1f5f9; font-weight: bold; font-size: 11px;">
                <td colspan="2" style="padding: 6px 8px; text-transform: uppercase;">3. EQUITY</td>
                <td style="padding: 6px 8px; text-align: right; font-family: monospace;">
                    {{ number_format($report->equity['total_cents'] / 100, 2) }}
                </td>
            </tr>
            @foreach($report->equity['accounts'] as $acc)
                <tr style="border-bottom: 1px solid #f1f5f9; font-size: 11px;">
                    <td style="padding: 4px 8px; font-family: monospace; color: #64748b;">{{ $acc['code'] }}</td>
                    <td style="padding: 4px 8px; padding-left: 24px;">{{ $acc['name'] }}</td>
                    <td style="padding: 4px 8px; text-align: right; font-family: monospace;">
                        {{ number_format($acc['amount_cents'] / 100, 2) }}
                    </td>
                </tr>
            @endforeach
            <tr style="border-bottom: 1px solid #f1f5f9; font-size: 11px; font-style: italic;">
                <td style="padding: 4px 8px; font-family: monospace; color: #64748b;">EARNINGS</td>
                <td style="padding: 4px 8px; padding-left: 24px;">Current Period Net Earnings</td>
                <td style="padding: 4px 8px; text-align: right; font-family: monospace;">
                    {{ number_format($report->equity['current_period_earnings_cents'] / 100, 2) }}
                </td>
            </tr>

            <!-- Total Liabilities & Equity -->
            <tr style="background-color: #e2e8f0; font-weight: bold; font-size: 12px; border-top: 2px solid #0f172a;">
                <td colspan="2" style="padding: 8px; text-transform: uppercase;">Total Liabilities & Equity</td>
                <td style="padding: 8px; text-align: right; font-family: monospace;">
                    {{ number_format($report->totalLiabilitiesAndEquityCents / 100, 2) }}
                </td>
            </tr>
        </tbody>
    </table>
@endsection
