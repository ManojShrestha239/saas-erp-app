@extends('shared::pdf.layout')

@section('title', 'Purchase Order ' . $order->order_number)

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
                <div class="doc-type">Purchase Order</div>
                <div class="doc-number">{{ $order->order_number }}</div>
                <div>
                    <span class="status-badge badge-{{ strtolower($order->status->value) }}">
                        {{ $order->status->label() }}
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
                    <div class="card-label">Vendor / Supplier</div>
                    <div class="card-name">{{ $supplier['name'] ?? 'Vendor' }}</div>
                    <div class="card-text">
                        @if(!empty($supplier['code']))
                            <strong>Code:</strong> {{ $supplier['code'] }}<br>
                        @endif
                        @if(!empty($supplier['tax_id']))
                            <strong>PAN/VAT:</strong> {{ $supplier['tax_id'] }}<br>
                        @endif
                        @if(!empty($supplier['email']))
                            {{ $supplier['email'] }}<br>
                        @endif
                        @if(!empty($supplier['billing_address']))
                            {{ is_array($supplier['billing_address']) ? implode(', ', array_filter($supplier['billing_address'])) : $supplier['billing_address'] }}
                        @endif
                    </div>
                </div>
            </td>
            <td>
                <div class="card-box card-box-right">
                    <div class="card-label">Order & Ship-To Details</div>
                    <table style="width: 100%; border-collapse: collapse; font-size: 11px; line-height: 1.5;">
                        <tr>
                            <td style="color: #64748b; width: 45%;"><strong>Order Date:</strong></td>
                            <td style="color: #0f172a;">{{ $order->order_date->format('Y-m-d') }}</td>
                        </tr>
                        <tr>
                            <td style="color: #64748b;"><strong>Delivery Date:</strong></td>
                            <td style="color: #0f172a;">{{ $order->expected_delivery_date ? $order->expected_delivery_date->format('Y-m-d') : 'As specified' }}</td>
                        </tr>
                        @if($warehouse)
                            <tr>
                                <td style="color: #64748b;"><strong>Ship-To WH:</strong></td>
                                <td style="color: #0f172a; font-weight: 600;">{{ $warehouse['name'] }} ({{ $warehouse['code'] }})</td>
                            </tr>
                        @endif
                        <tr>
                            <td style="color: #64748b;"><strong>Currency:</strong></td>
                            <td style="color: #0f172a; font-weight: 600;">{{ $order->currency }}</td>
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
                <th style="width: 45%;">Item & Specifications</th>
                <th style="width: 10%;" class="text-right">Qty</th>
                <th style="width: 13%;" class="text-right">Unit Cost</th>
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
                    <td class="text-right">{{ $order->currency }} {{ number_format($item['unit_cost_cents'] / 100, 2) }}</td>
                    <td class="text-right">{{ $order->currency }} {{ number_format($item['tax_cents'] / 100, 2) }} ({{ $item['tax_rate_percent'] }}%)</td>
                    <td class="text-right" style="font-weight: 600;">{{ $order->currency }} {{ number_format($item['total_cents'] / 100, 2) }}</td>
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
                    <strong>Shipping & Purchase Instructions:</strong><br>
                    {{ $order->notes ?? 'Please deliver goods with corresponding Delivery Note and Reference PO Number on all packages.' }}
                    <br><br>
                    <strong>Delivery Location:</strong><br>
                    {{ $warehouse ? $warehouse['name'] . ' - ' . ($warehouse['address'] ?? 'Receiving Bay') : 'Central Receiving Facility' }}
                </div>
            </td>
            <td class="totals-column">
                <div class="totals-card">
                    <div class="totals-row">
                        <span>Subtotal</span>
                        <span>{{ $order->currency }} {{ number_format($order->subtotal_cents / 100, 2) }}</span>
                    </div>
                    <div class="totals-row">
                        <span>Tax / VAT</span>
                        <span>{{ $order->currency }} {{ number_format($order->tax_cents / 100, 2) }}</span>
                    </div>
                    <div class="totals-row-due">
                        <span>Total PO Value</span>
                        <span>{{ $order->currency }} {{ number_format($order->total_cents / 100, 2) }}</span>
                    </div>
                </div>
            </td>
        </tr>
    </table>

    <!-- Signature Block -->
    <table class="signature-grid">
        <tr>
            <td>
                <div class="signature-line">Vendor Confirmation & Acceptance</div>
                <div class="signature-sub">Acknowledged & Accepted</div>
            </td>
            <td>
                <div class="signature-line">Authorized Purchasing Signatory</div>
                <div class="signature-sub">For {{ $company['name'] ?? 'NexusERP' }}</div>
            </td>
        </tr>
    </table>
@endsection
