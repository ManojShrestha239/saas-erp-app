<?php

declare(strict_types=1);

namespace Modules\Sales\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Modules\Sales\DTOs\CreateCreditNoteData;
use Modules\Sales\Models\CreditNote;

final class StoreCreditNoteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('create', CreditNote::class);
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'customer_id' => ['required', 'string', 'size:26'],
            'warehouse_id' => ['required', 'string', 'size:26'],
            'sales_invoice_id' => ['nullable', 'string', 'size:26'],
            'issue_date' => ['required', 'date'],
            'currency' => ['nullable', 'string', 'size:3'],
            'restock' => ['nullable', 'boolean'],
            'reason' => ['nullable', 'string', 'max:64'],
            'notes' => ['nullable', 'string'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'string', 'size:26'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'items.*.unit_price_cents' => ['required', 'integer', 'min:0'],
            'items.*.tax_rate_percent' => ['nullable', 'integer', 'min:0', 'max:100'],
            'items.*.tax_category' => ['nullable', 'string', 'max:32'],
            'items.*.description' => ['nullable', 'string', 'max:255'],
            'items.*.notes' => ['nullable', 'string'],
            'items.*.cogs_unit_cost_cents' => ['nullable', 'integer', 'min:0'],
        ];
    }

    public function toDto(): CreateCreditNoteData
    {
        /** @var array<string, mixed> $validated */
        $validated = $this->validated();

        return CreateCreditNoteData::fromArray($validated);
    }
}
