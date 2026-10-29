@extends('shared::pdf.layout')

@section('title', 'Quotation ' . $quotation->quotation_number)

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
                <div class="doc-type">Sales Quotation</div>
                <div class="doc-number">{{ $quotation->quotation_number }}</div>
                <div>
                    <span class="status-badge badge-{{ strtolower($quotation->status->value) }}">
                        {{ $quotation->status->label() }}
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
                    <div class="card-label">Quotation For</div>
                    <div class="card-name">{{ $customer['name'] ?? 'Prospective Client' }}</div>
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
                    <div class="card-label">Quotation Details</div>
                    <table style="width: 100%; border-collapse: collapse; font-size: 11px; line-height: 1.5;">
                        <tr>
                            <td style="color: #64748b; width: 45%;"><strong>Issue Date:</strong></td>
                            <td style="color: #0f172a;">{{ $quotation->issue_date->format('Y-m-d') }}</td>
                        </tr>
                        <tr>
                            <td style="color: #64748b;"><strong>Expiry Date:</strong></td>
                            <td style="color: #0f172a;">{{ $quotation->expiry_date ? $quotation->expiry_date->format('Y-m-d') : '30 days from issue' }}</td>
                        </tr>
                        <tr>
                            <td style="color: #64748b;"><strong>Currency:</strong></td>
                            <td style="color: #0f172a; font-weight: 600;">{{ $quotation->currency }}</td>
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
                    <td class="text-right">{{ $quotation->currency }} {{ number_format($item['unit_price_cents'] / 100, 2) }}</td>
                    <td class="text-right">{{ $quotation->currency }} {{ number_format($item['tax_cents'] / 100, 2) }} ({{ $item['tax_rate_percent'] }}%)</td>
                    <td class="text-right" style="font-weight: 600;">{{ $quotation->currency }} {{ number_format($item['total_cents'] / 100, 2) }}</td>
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
                    <strong>Terms & Conditions:</strong><br>
                    {{ $quotation->notes ?? 'This quotation is valid until the stated expiry date. Prices are subject to general terms of business.' }}
                    <br><br>
                    <strong>Delivery Timeline:</strong><br>
                    Standard delivery within 7-14 business days from confirmation of purchase order.
                </div>
            </td>
            <td class="totals-column">
                <div class="totals-card">
                    <div class="totals-row">
                        <span>Subtotal</span>
                        <span>{{ $quotation->currency }} {{ number_format($quotation->subtotal_cents / 100, 2) }}</span>
                    </div>
                    <div class="totals-row">
                        <span>Tax / VAT</span>
                        <span>{{ $quotation->currency }} {{ number_format($quotation->tax_cents / 100, 2) }}</span>
                    </div>
                    <div class="totals-row-due">
                        <span>Total Quotation</span>
                        <span>{{ $quotation->currency }} {{ number_format($quotation->total_cents / 100, 2) }}</span>
                    </div>
                </div>
            </td>
        </tr>
    </table>

    <!-- Acceptance Block -->
    <table class="signature-grid">
        <tr>
            <td>
                <div class="signature-line">Customer Acceptance & Signature</div>
                <div class="signature-sub">Date & Authorized Signature</div>
            </td>
            <td>
                <div class="signature-line">Prepared By: {{ $company['name'] ?? 'NexusERP' }}</div>
                <div class="signature-sub">Sales Department</div>
            </td>
        </tr>
    </table>
@endsection
