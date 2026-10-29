@extends('shared::pdf.layout')

@section('title', 'Invoice ' . $invoice->invoice_number)

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
                        <strong>PAN/VAT:</strong> {{ $company['tax_id'] }}<br>
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
                <div class="doc-type">Tax Invoice</div>
                <div class="doc-number">{{ $invoice->invoice_number }}</div>
                <div>
                    <span class="status-badge badge-{{ strtolower($invoice->status->value) }}">
                        {{ $invoice->status->label() }}
                    </span>
                </div>
            </td>
        </tr>
    </table>

    <!-- Two-column Meta Grid -->
    <table class="meta-grid">
        <tr>
            <td>
                <div class="card-box card-box-left">
                    <div class="card-label">Billed To</div>
                    <div class="card-name">{{ $customer['name'] ?? 'Customer' }}</div>
                    <div class="card-text">
                        @if(!empty($customer['code']))
                            <strong>Code:</strong> {{ $customer['code'] }}<br>
                        @endif
                        @if(!empty($customer['tax_id']))
                            <strong>PAN/VAT:</strong> {{ $customer['tax_id'] }}<br>
                        @endif
                        @if(!empty($customer['email']))
                            {{ $customer['email'] }}<br>
                        @endif
                        @if(!empty($customer['billing_address']))
                            {{ is_array($customer['billing_address']) ? implode(', ', array_filter($customer['billing_address'])) : $customer['billing_address'] }}
                        @endif
                    </div>
                </div>
            </td>
            <td>
                <div class="card-box card-box-right">
                    <div class="card-label">Invoice Details</div>
                    <table style="width: 100%; border-collapse: collapse; font-size: 11px; line-height: 1.5;">
                        <tr>
                            <td style="color: #64748b; width: 45%;"><strong>Issue Date:</strong></td>
                            <td style="color: #0f172a;">{{ $invoice->issue_date->format('Y-m-d') }}</td>
                        </tr>
                        <tr>
                            <td style="color: #64748b;"><strong>Due Date:</strong></td>
                            <td style="color: #0f172a;">{{ $invoice->due_date->format('Y-m-d') }}</td>
                        </tr>
                        @if($invoice->sales_quotation_id && $invoice->quotation)
                            <tr>
                                <td style="color: #64748b;"><strong>Quotation Ref:</strong></td>
                                <td style="color: #0f172a;">{{ $invoice->quotation->quotation_number }}</td>
                            </tr>
                        @endif
                        <tr>
                            <td style="color: #64748b;"><strong>Currency:</strong></td>
                            <td style="color: #0f172a; font-weight: 600;">{{ $invoice->currency }}</td>
                        </tr>
                    </table>
                </div>
            </td>
        </tr>
    </table>

    <!-- Items Table -->
    <table class="items-table">
        <thead>
            <tr>
                <th style="width: 5%;" class="text-center">#</th>
                <th style="width: 45%;">Item & Description</th>
                <th style="width: 10%;" class="text-right">Qty</th>
                <th style="width: 13%;" class="text-right">Unit Price</th>
                <th style="width: 12%;" class="text-right">Tax</th>
                <th style="width: 15%;" class="text-right">Total</th>
            </tr>
        </thead>
        <tbody>
            @forelse($items as $idx => $item)
                <tr>
                    <td class="text-center" style="color: #94a3b8;">{{ $idx + 1 }}</td>
                    <td>
                        <strong style="color: #0f172a;">{{ $item['product_name'] }}</strong>
                        <div style="color: #64748b; font-size: 10px; margin-top: 2px;">
                            SKU: {{ $item['sku'] }}
                            @if(!empty($item['description']))
                                &bull; {{ $item['description'] }}
                            @endif
                        </div>
                    </td>
                    <td class="text-right">{{ number_format($item['quantity']) }}</td>
                    <td class="text-right">{{ $invoice->currency }} {{ number_format($item['unit_price_cents'] / 100, 2) }}</td>
                    <td class="text-right">{{ $invoice->currency }} {{ number_format($item['tax_cents'] / 100, 2) }} ({{ $item['tax_rate_percent'] }}%)</td>
                    <td class="text-right" style="font-weight: 600;">{{ $invoice->currency }} {{ number_format($item['total_cents'] / 100, 2) }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="6" class="text-center" style="padding: 20px; color: #94a3b8;">No line items found.</td>
                </tr>
            @endforelse
        </tbody>
    </table>

    <!-- Summary & Notes -->
    <table class="summary-table">
        <tr>
            <td class="notes-column">
                <div class="notes-card">
                    <strong>Payment Terms & Notes:</strong><br>
                    {{ $invoice->notes ?? 'Payment is due within ' . $invoice->issue_date->diffInDays($invoice->due_date) . ' days of issue date.' }}
                    <br><br>
                    <strong>Bank Remittance:</strong><br>
                    {{ $company['bank_details'] ?? 'Please transfer to our designated company bank account referencing ' . $invoice->invoice_number . '.' }}
                </div>
            </td>
            <td class="totals-column">
                <div class="totals-card">
                    <div class="totals-row">
                        <span>Subtotal</span>
                        <span>{{ $invoice->currency }} {{ number_format($invoice->subtotal_cents / 100, 2) }}</span>
                    </div>
                    <div class="totals-row">
                        <span>Tax / VAT</span>
                        <span>{{ $invoice->currency }} {{ number_format($invoice->tax_cents / 100, 2) }}</span>
                    </div>
                    <div class="totals-row-grand">
                        <span>Grand Total</span>
                        <span>{{ $invoice->currency }} {{ number_format($invoice->total_cents / 100, 2) }}</span>
                    </div>
                    @if($invoice->amount_paid_cents > 0)
                        <div class="totals-row">
                            <span style="color: #047857;">Amount Paid</span>
                            <span style="color: #047857;">- {{ $invoice->currency }} {{ number_format($invoice->amount_paid_cents / 100, 2) }}</span>
                        </div>
                    @endif
                    <div class="totals-row-due">
                        <span>Balance Due</span>
                        <span>{{ $invoice->currency }} {{ number_format($invoice->amount_due_cents / 100, 2) }}</span>
                    </div>
                </div>
            </td>
        </tr>
    </table>

    <!-- Signature Block -->
    <table class="signature-grid">
        <tr>
            <td>
                <div class="signature-line">Customer Signature & Stamp</div>
                <div class="signature-sub">Accepted by authorized representative</div>
            </td>
            <td>
                <div class="signature-line">For {{ $company['name'] ?? 'NexusERP' }}</div>
                <div class="signature-sub">Authorized Signatory</div>
            </td>
        </tr>
    </table>
@endsection
