@extends('shared::pdf.layout')

@section('title', 'Trial Balance - ' . $asOfDate)

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
                <div class="doc-type">TRIAL BALANCE</div>
                <div style="font-size: 11px; color: #64748b; margin-top: 4px;">
                    <strong>As of Date:</strong> {{ $asOfDate }}<br>
                    <strong>Status:</strong> {{ $report->isBalanced ? 'Balanced' : 'Imbalanced' }}
                </div>
            </td>
        </tr>
    </table>

    <table class="line-items-table" style="width: 100%; border-collapse: collapse; margin-top: 16px;">
        <thead>
            <tr style="background-color: #f8fafc; border-bottom: 2px solid #cbd5e1; font-size: 10px; text-transform: uppercase;">
                <th style="padding: 8px; text-align: left; width: 15%;">Code</th>
                <th style="padding: 8px; text-align: left;">Account Name</th>
                <th style="padding: 8px; text-align: left; width: 15%;">Type</th>
                <th style="padding: 8px; text-align: right; width: 20%;">Debit</th>
                <th style="padding: 8px; text-align: right; width: 20%;">Credit</th>
            </tr>
        </thead>
        <tbody>
            @foreach($report->accounts as $acc)
                <tr style="border-bottom: 1px solid #e2e8f0; font-size: 11px;">
                    <td style="padding: 6px 8px; font-family: monospace; font-weight: 600;">{{ $acc['code'] }}</td>
                    <td style="padding: 6px 8px;">{{ $acc['name'] }}</td>
                    <td style="padding: 6px 8px; text-transform: capitalize; color: #64748b;">{{ $acc['type'] }}</td>
                    <td style="padding: 6px 8px; text-align: right; font-family: monospace;">
                        {{ $acc['debit_balance_cents'] > 0 ? number_format($acc['debit_balance_cents'] / 100, 2) : '—' }}
                    </td>
                    <td style="padding: 6px 8px; text-align: right; font-family: monospace;">
                        {{ $acc['credit_balance_cents'] > 0 ? number_format($acc['credit_balance_cents'] / 100, 2) : '—' }}
                    </td>
                </tr>
            @endforeach
        </tbody>
        <tfoot>
            <tr style="background-color: #f1f5f9; font-weight: bold; font-size: 11px; border-top: 2px solid #0f172a;">
                <td colspan="3" style="padding: 8px; text-align: right; text-transform: uppercase;">Total</td>
                <td style="padding: 8px; text-align: right; font-family: monospace;">
                    {{ number_format($report->totalDebitsCents / 100, 2) }}
                </td>
                <td style="padding: 8px; text-align: right; font-family: monospace;">
                    {{ number_format($report->totalCreditsCents / 100, 2) }}
                </td>
            </tr>
        </tfoot>
    </table>
@endsection
