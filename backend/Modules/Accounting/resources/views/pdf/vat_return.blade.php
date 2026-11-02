@extends('shared::pdf.layout')

@section('title', 'VAT Return - ' . ($data['fiscal_period']['name'] ?? ($data['from_date'] . ' to ' . $data['to_date'])))

@section('content')
    <!-- Top Header -->
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
                    @if(!empty($company['email']))
                        <strong>Email:</strong> {{ $company['email'] }} &bull;
                    @endif
                    @if(!empty($company['phone']))
                        <strong>Tel:</strong> {{ $company['phone'] }}
                    @endif
                </div>
            </td>
            <td class="doc-title-block" style="width: 45%;">
                <div class="doc-type">VAT RETURN</div>
                <div class="doc-number">{{ $returnNumber ?? 'INTERIM CALCULATION' }}</div>
                <div style="font-size: 11px; color: #64748b; margin-top: 4px;">
                    <strong>Period:</strong> {{ $data['from_date'] }} to {{ $data['to_date'] }}
                    @if(!empty($data['fiscal_period']))
                        <br><strong>Fiscal Period:</strong> {{ $data['fiscal_period']['name'] }}
                    @endif
                </div>
            </td>
        </tr>
    </table>

    <!-- Net Position Summary -->
    <div style="margin-top: 20px; margin-bottom: 24px; padding: 14px 18px; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px;">
        <table style="width: 100%; border-collapse: collapse;">
            <tr>
                <td style="width: 25%; vertical-align: top;">
                    <div style="font-size: 10px; text-transform: uppercase; color: #64748b; font-weight: 600;">Total Output VAT (Sales)</div>
                    <div style="font-size: 15px; font-weight: 700; color: #0f172a; margin-top: 4px;">
                        {{ $company['currency'] ?? 'USD' }} {{ number_format($data['summary']['total_output_vat_cents'] / 100, 2) }}
                    </div>
                    <div style="font-size: 10px; color: #64748b; margin-top: 2px;">
                        Base: {{ $company['currency'] ?? 'USD' }} {{ number_format($data['summary']['total_sales_taxable_cents'] / 100, 2) }}
                    </div>
                </td>
                <td style="width: 25%; vertical-align: top;">
                    <div style="font-size: 10px; text-transform: uppercase; color: #64748b; font-weight: 600;">Total Input VAT (Purchases)</div>
                    <div style="font-size: 15px; font-weight: 700; color: #0f172a; margin-top: 4px;">
                        {{ $company['currency'] ?? 'USD' }} {{ number_format($data['summary']['total_input_vat_cents'] / 100, 2) }}
                    </div>
                    <div style="font-size: 10px; color: #64748b; margin-top: 2px;">
                        Base: {{ $company['currency'] ?? 'USD' }} {{ number_format($data['summary']['total_purchases_taxable_cents'] / 100, 2) }}
                    </div>
                </td>
                <td style="width: 25%; vertical-align: top;">
                    <div style="font-size: 10px; text-transform: uppercase; color: #64748b; font-weight: 600;">Net VAT Payable / (Refund)</div>
                    <div style="font-size: 16px; font-weight: 700; color: {{ $data['summary']['net_vat_payable_cents'] >= 0 ? '#b45309' : '#047857' }}; margin-top: 4px;">
                        {{ $company['currency'] ?? 'USD' }} {{ number_format($data['summary']['net_vat_payable_cents'] / 100, 2) }}
                    </div>
                    <div style="font-size: 10px; color: #64748b; margin-top: 2px;">
                        {{ $data['summary']['net_vat_payable_cents'] >= 0 ? 'Due to Tax Authority' : 'Refundable / Carry Forward' }}
                    </div>
                </td>
                <td style="width: 25%; vertical-align: top;">
                    <div style="font-size: 10px; text-transform: uppercase; color: #64748b; font-weight: 600;">Ledger Reconciliation</div>
                    <div style="font-size: 13px; font-weight: 700; color: {{ $data['ledger_reconciliation']['is_reconciled'] ? '#059669' : '#dc2626' }}; margin-top: 4px;">
                        {{ $data['ledger_reconciliation']['is_reconciled'] ? '100% Reconciled' : 'Discrepancy' }}
                    </div>
                    <div style="font-size: 10px; color: #64748b; margin-top: 2px;">
                        GL Net: {{ $company['currency'] ?? 'USD' }} {{ number_format($data['ledger_reconciliation']['gl_net_vat_cents'] / 100, 2) }}
                    </div>
                </td>
            </tr>
        </table>
    </div>

    <!-- Rate Breakdown Table -->
    <div style="font-size: 12px; font-weight: 700; color: #1e293b; margin-bottom: 8px; text-transform: uppercase; letter-spacing: 0.05em;">
        VAT Rate Breakdown
    </div>
    <table class="items-table" style="margin-bottom: 24px;">
        <thead>
            <tr>
                <th style="width: 12%; text-align: left;">Rate</th>
                <th style="width: 22%; text-align: right;">Sales Taxable</th>
                <th style="width: 18%; text-align: right;">Output VAT</th>
                <th style="width: 22%; text-align: right;">Purchases Taxable</th>
                <th style="width: 18%; text-align: right;">Input VAT</th>
                <th style="width: 18%; text-align: right;">Net VAT</th>
            </tr>
        </thead>
        <tbody>
            @forelse($data['rate_breakdown'] as $row)
                <tr>
                    <td style="font-weight: 600;">{{ $row['tax_rate_percent'] }}%</td>
                    <td style="text-align: right;">{{ number_format($row['sales_taxable_cents'] / 100, 2) }}</td>
                    <td style="text-align: right; font-weight: 600;">{{ number_format($row['output_vat_cents'] / 100, 2) }}</td>
                    <td style="text-align: right;">{{ number_format($row['purchases_taxable_cents'] / 100, 2) }}</td>
                    <td style="text-align: right; font-weight: 600;">{{ number_format($row['input_vat_cents'] / 100, 2) }}</td>
                    <td style="text-align: right; font-weight: 700; color: {{ $row['net_vat_cents'] >= 0 ? '#0f172a' : '#047857' }};">
                        {{ number_format($row['net_vat_cents'] / 100, 2) }}
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="6" style="text-align: center; color: #94a3b8; padding: 12px;">No taxable activity in this period.</td>
                </tr>
            @endforelse
            @if(count($data['rate_breakdown']) > 0)
                <tr style="background: #f8fafc; font-weight: 700;">
                    <td>Total</td>
                    <td style="text-align: right;">{{ number_format($data['summary']['total_sales_taxable_cents'] / 100, 2) }}</td>
                    <td style="text-align: right;">{{ number_format($data['summary']['total_output_vat_cents'] / 100, 2) }}</td>
                    <td style="text-align: right;">{{ number_format($data['summary']['total_purchases_taxable_cents'] / 100, 2) }}</td>
                    <td style="text-align: right;">{{ number_format($data['summary']['total_input_vat_cents'] / 100, 2) }}</td>
                    <td style="text-align: right;">{{ number_format($data['summary']['net_vat_payable_cents'] / 100, 2) }}</td>
                </tr>
            @endif
        </tbody>
    </table>

    <!-- Document Drilldown Table -->
    <div style="font-size: 12px; font-weight: 700; color: #1e293b; margin-bottom: 8px; text-transform: uppercase; letter-spacing: 0.05em;">
        Participating Tax Documents ({{ count($data['documents']) }})
    </div>
    <table class="items-table" style="font-size: 9.5px;">
        <thead>
            <tr>
                <th style="width: 10%; text-align: left;">Date</th>
                <th style="width: 14%; text-align: left;">Type</th>
                <th style="width: 16%; text-align: left;">Doc Number</th>
                <th style="width: 24%; text-align: left;">Contact & PAN/VAT</th>
                <th style="width: 12%; text-align: right;">Taxable Base</th>
                <th style="width: 12%; text-align: right;">VAT Amount</th>
                <th style="width: 12%; text-align: right;">Gross Total</th>
            </tr>
        </thead>
        <tbody>
            @forelse($data['documents'] as $doc)
                <tr>
                    <td>{{ $doc['date'] }}</td>
                    <td>
                        <span style="font-weight: 600; text-transform: capitalize;">
                            {{ str_replace('_', ' ', $doc['document_type']) }}
                        </span>
                    </td>
                    <td style="font-family: monospace;">{{ $doc['document_number'] }}</td>
                    <td>
                        <strong>{{ $doc['contact_name'] }}</strong>
                        @if(!empty($doc['contact_tax_id']))
                            <br><span style="color: #64748b;">Tax ID: {{ $doc['contact_tax_id'] }}</span>
                        @endif
                    </td>
                    <td style="text-align: right;">{{ number_format($doc['subtotal_cents'] / 100, 2) }}</td>
                    <td style="text-align: right; font-weight: 600; color: {{ $doc['tax_cents'] < 0 ? '#dc2626' : '#0f172a' }};">
                        {{ number_format($doc['tax_cents'] / 100, 2) }}
                    </td>
                    <td style="text-align: right;">{{ number_format($doc['total_cents'] / 100, 2) }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="7" style="text-align: center; color: #94a3b8; padding: 12px;">No document records found.</td>
                </tr>
            @endforelse
        </tbody>
    </table>

    <!-- Signature / Declaration Block -->
    <div style="margin-top: 40px; page-break-inside: avoid;">
        <table style="width: 100%; border-collapse: collapse;">
            <tr>
                <td style="width: 50%; vertical-align: top; padding-right: 20px;">
                    <div style="font-size: 10px; color: #64748b; line-height: 1.5;">
                        <strong>Declaration:</strong> I hereby certify that the information provided in this VAT return is true, correct, and completely reconciles with the company general ledger and statutory books of account.
                    </div>
                </td>
                <td style="width: 50%; vertical-align: top; text-align: right;">
                    <div style="display: inline-block; width: 220px; border-top: 1px solid #94a3b8; padding-top: 6px; text-align: center; font-size: 11px; color: #475569;">
                        Authorized Finance Signatory<br>
                        <span style="font-size: 9px; color: #94a3b8;">Generated: {{ $generatedAt ?? date('Y-m-d H:i:s') }}</span>
                    </div>
                </td>
            </tr>
        </table>
    </div>
@endsection
