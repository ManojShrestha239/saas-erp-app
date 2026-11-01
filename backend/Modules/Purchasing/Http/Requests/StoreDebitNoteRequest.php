<?php

declare(strict_types=1);

namespace Modules\Purchasing\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Modules\Purchasing\DTOs\CreateDebitNoteData;
use Modules\Purchasing\Models\DebitNote;

final class StoreDebitNoteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('create', DebitNote::class);
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'supplier_id' => ['required', 'string', 'size:26'],
            'warehouse_id' => ['required', 'string', 'size:26'],
            'supplier_bill_id' => ['nullable', 'string', 'size:26'],
            'issue_date' => ['required', 'date'],
            'currency' => ['nullable', 'string', 'size:3'],
            'return_stock' => ['nullable', 'boolean'],
            'reason' => ['nullable', 'string', 'max:64'],
            'notes' => ['nullable', 'string'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'string', 'size:26'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'items.*.unit_cost_cents' => ['required', 'integer', 'min:0'],
            'items.*.tax_rate_percent' => ['nullable', 'integer', 'min:0', 'max:100'],
            'items.*.tax_category' => ['nullable', 'string', 'max:32'],
            'items.*.description' => ['nullable', 'string', 'max:255'],
            'items.*.notes' => ['nullable', 'string'],
        ];
    }

    public function toDto(): CreateDebitNoteData
    {
        /** @var array<string, mixed> $validated */
        $validated = $this->validated();

        return CreateDebitNoteData::fromArray($validated);
    }
}
