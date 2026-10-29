@extends('shared::pdf.layout')

@section('title', 'Statement of Account - ' . $statement['contact']['name'])

@section('content')
    <!-- Top Header -->
    <table class="header-table">
        <tr>
            <td style="width: 55%;">
                <div class="company-title">{{ $statement['company']['name'] ?? 'NexusERP' }}</div>
                <div class="company-meta">
                    @if(!empty($statement['company']['address']))
                        {{ $statement['company']['address'] }}<br>
                    @endif
                    @if(!empty($statement['company']['tax_id']))
                        <strong>PAN/VAT:</strong> {{ $statement['company']['tax_id'] }}<br>
                    @endif
                    @if(!empty($statement['company']['email']))
                        <strong>Email:</strong> {{ $statement['company']['email'] }} &bull;
                    @endif
                    @if(!empty($statement['company']['phone']))
                        <strong>Tel:</strong> {{ $statement['company']['phone'] }}
                    @endif
                </div>
            </td>
            <td class="doc-title-block" style="width: 45%;">
                <div class="doc-type">Statement of Account</div>
                <div class="doc-number">{{ $statement['contact']['code'] }}</div>
                <div style="font-size: 11px; color: #64748b; margin-top: 4px;">
                    <strong>Type:</strong> {{ ucfirst($statement['statement_type']) }} Statement
                </div>
            </td>
        </tr>
    </table>

    <!-- Two-column Meta Grid -->
    <table class="meta-grid">
        <tr>
            <td>
                <div class="card-box card-box-left">
                    <div class="card-label">Statement For</div>
                    <div class="card-name">{{ $statement['contact']['name'] }}</div>
                    <div class="card-text">
                        <strong>Code:</strong> {{ $statement['contact']['code'] }}<br>
                        @if(!empty($statement['contact']['tax_id']))
                            <strong>PAN/VAT:</strong> {{ $statement['contact']['tax_id'] }}<br>
                        @endif
                        @if(!empty($statement['contact']['email']))
                            <strong>Email:</strong> {{ $statement['contact']['email'] }}<br>
                        @endif
                        @if(!empty($statement['contact']['phone']))
                            <strong>Phone:</strong> {{ $statement['contact']['phone'] }}<br>
                        @endif
                        @if(!empty($statement['contact']['billing_address']))
                            {{ is_array($statement['contact']['billing_address']) ? implode(', ', array_filter($statement['contact']['billing_address'])) : $statement['contact']['billing_address'] }}
                        @endif
                    </div>
                </div>
            </td>
            <td>
                <div class="card-box card-box-right">
                    <div class="card-label">Statement Summary</div>
                    <table style="width: 100%; border-collapse: collapse; font-size: 11px; line-height: 1.5;">
                        <tr>
                            <td style="color: #64748b; width: 45%;"><strong>Period:</strong></td>
                            <td style="color: #0f172a;">{{ $statement['start_date'] }} to {{ $statement['end_date'] }}</td>
                        </tr>
                        <tr>
                            <td style="color: #64748b;"><strong>Opening Balance:</strong></td>
                            <td style="color: #0f172a; font-family: monospace;">{{ $statement['currency'] }} {{ number_format($statement['summary']['opening_balance_cents'] / 100, 2) }}</td>
                        </tr>
                        <tr>
                            <td style="color: #64748b;"><strong>Total Debits:</strong></td>
                            <td style="color: #0f172a; font-family: monospace;">{{ $statement['currency'] }} {{ number_format($statement['summary']['total_debits_cents'] / 100, 2) }}</td>
                        </tr>
                        <tr>
                            <td style="color: #64748b;"><strong>Total Credits:</strong></td>
                            <td style="color: #0f172a; font-family: monospace;">{{ $statement['currency'] }} {{ number_format($statement['summary']['total_credits_cents'] / 100, 2) }}</td>
                        </tr>
                        <tr style="border-top: 1px solid #e2e8f0;">
                            <td style="color: #0f172a; font-weight: 700; padding-top: 4px;"><strong>Balance Due:</strong></td>
                            <td style="color: #0f172a; font-weight: 700; font-family: monospace; font-size: 12px; padding-top: 4px;">
                                {{ $statement['currency'] }} {{ number_format($statement['summary']['closing_balance_cents'] / 100, 2) }}
                            </td>
                        </tr>
                    </table>
                </div>
            </td>
        </tr>
    </table>

    <!-- Aging Summary Cards / Table -->
    <div style="margin-bottom: 18px;">
        <div style="font-size: 10px; font-weight: 700; text-transform: uppercase; color: #64748b; margin-bottom: 6px; letter-spacing: 0.5px;">
            Aging Analysis (as of {{ $statement['end_date'] }})
        </div>
        <table class="items-table" style="margin-bottom: 0;">
            <thead>
                <tr>
                    <th style="width: 16%;" class="text-right">Current</th>
                    <th style="width: 16%;" class="text-right">1–30 Days</th>
                    <th style="width: 16%;" class="text-right">31–60 Days</th>
                    <th style="width: 16%;" class="text-right">61–90 Days</th>
                    <th style="width: 16%;" class="text-right">90+ Days</th>
                    <th style="width: 20%;" class="text-right">Total Outstanding</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td class="text-right font-mono" style="font-weight: 600;">
                        {{ number_format($statement['aging']['current_cents'] / 100, 2) }}
                    </td>
                    <td class="text-right font-mono" style="font-weight: 600; color: #475569;">
                        {{ number_format($statement['aging']['days_1_30_cents'] / 100, 2) }}
                    </td>
                    <td class="text-right font-mono" style="font-weight: 600; color: #d97706;">
                        {{ number_format($statement['aging']['days_31_60_cents'] / 100, 2) }}
                    </td>
                    <td class="text-right font-mono" style="font-weight: 600; color: #ea580c;">
                        {{ number_format($statement['aging']['days_61_90_cents'] / 100, 2) }}
                    </td>
                    <td class="text-right font-mono" style="font-weight: 600; color: #dc2626;">
                        {{ number_format($statement['aging']['days_over_90_cents'] / 100, 2) }}
                    </td>
                    <td class="text-right font-mono" style="font-weight: 700; color: #0f172a; background: #f8fafc;">
                        {{ $statement['currency'] }} {{ number_format($statement['aging']['total_due_cents'] / 100, 2) }}
                    </td>
                </tr>
            </tbody>
        </table>
    </div>

    <!-- Chronological Activity Ledger Table -->
    <div style="font-size: 10px; font-weight: 700; text-transform: uppercase; color: #64748b; margin-bottom: 6px; letter-spacing: 0.5px;">
        Account Activity Ledger
    </div>
    <table class="items-table">
        <thead>
            <tr>
                <th style="width: 12%;">Date</th>
                <th style="width: 18%;">Document #</th>
                <th style="width: 34%;">Description</th>
                <th style="width: 12%;" class="text-right">Debit</th>
                <th style="width: 12%;" class="text-right">Credit</th>
                <th style="width: 12%;" class="text-right">Balance</th>
            </tr>
        </thead>
        <tbody>
            <!-- Opening Balance Row -->
            <tr style="background: #f8fafc;">
                <td style="color: #64748b;">{{ $statement['start_date'] }}</td>
                <td style="font-family: monospace; font-size: 10px; color: #64748b;">OPENING</td>
                <td style="font-weight: 600; color: #475569;">Opening Balance Forward</td>
                <td class="text-right text-muted">-</td>
                <td class="text-right text-muted">-</td>
                <td class="text-right font-mono" style="font-weight: 600;">
                    {{ number_format($statement['summary']['opening_balance_cents'] / 100, 2) }}
                </td>
            </tr>

            @forelse($statement['transactions'] as $tx)
                <tr>
                    <td>{{ $tx['date'] }}</td>
                    <td class="font-mono" style="font-weight: 600;">{{ $tx['document_number'] }}</td>
                    <td>
                        <span style="font-weight: 500;">{{ $tx['description'] }}</span>
                        @if(!empty($tx['due_date']))
                            <div style="font-size: 9px; color: #64748b;">Due: {{ $tx['due_date'] }}</div>
                        @endif
                    </td>
                    <td class="text-right font-mono">
                        {{ $tx['debit_cents'] > 0 ? number_format($tx['debit_cents'] / 100, 2) : '-' }}
                    </td>
                    <td class="text-right font-mono">
                        {{ $tx['credit_cents'] > 0 ? number_format($tx['credit_cents'] / 100, 2) : '-' }}
                    </td>
                    <td class="text-right font-mono" style="font-weight: 600;">
                        {{ number_format($tx['running_balance_cents'] / 100, 2) }}
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="6" class="text-center text-muted" style="padding: 16px;">
                        No transactions recorded in this statement period.
                    </td>
                </tr>
            @endforelse

            <!-- Closing Summary Row -->
            <tr style="background: #f1f5f9; font-weight: 700; border-top: 2px solid #cbd5e1;">
                <td colspan="3" style="text-align: right; padding-right: 12px; font-size: 11px;">
                    Totals & Closing Balance:
                </td>
                <td class="text-right font-mono">
                    {{ number_format($statement['summary']['total_debits_cents'] / 100, 2) }}
                </td>
                <td class="text-right font-mono">
                    {{ number_format($statement['summary']['total_credits_cents'] / 100, 2) }}
                </td>
                <td class="text-right font-mono" style="color: #0f172a; font-size: 12px;">
                    {{ $statement['currency'] }} {{ number_format($statement['summary']['closing_balance_cents'] / 100, 2) }}
                </td>
            </tr>
        </tbody>
    </table>

    <!-- Bottom Signatures / Notes -->
    <table class="signatures-table">
        <tr>
            <td style="width: 60%; vertical-align: top;">
                <div style="font-size: 10px; color: #64748b; line-height: 1.5;">
                    <strong>Notice:</strong> Please verify all transactions listed in this statement. If you find any discrepancies, kindly notify us within 7 days of receipt.<br>
                    @if(!empty($statement['company']['bank_details']))
                        <strong>Remittance / Bank Details:</strong> {{ $statement['company']['bank_details'] }}
                    @endif
                </div>
            </td>
            <td style="width: 40%; text-align: right; vertical-align: bottom;">
                <div class="signature-line" style="margin-left: auto;"></div>
                <div class="signature-label">Authorized Signatory / Accounts Dept</div>
            </td>
        </tr>
    </table>
@endsection
