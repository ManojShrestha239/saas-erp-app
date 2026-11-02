<?php

declare(strict_types=1);

namespace Modules\Accounting\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Modules\Accounting\DTOs\FileVatReturnData;

final class FileVatReturnRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'fiscal_period_id' => ['nullable', 'string'],
            'from_date' => ['required_without:fiscal_period_id', 'nullable', 'date'],
            'to_date' => ['required_without:fiscal_period_id', 'nullable', 'date', 'after_or_equal:from_date'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function toDto(): FileVatReturnData
    {
        $periodId = $this->filled('fiscal_period_id') ? (string) $this->input('fiscal_period_id') : null;

        $fromInput = $this->input('from_date');
        $toInput = $this->input('to_date');

        $fromDate = $fromInput !== null ? Carbon::parse((string) $fromInput) : Carbon::today()->startOfMonth();
        $toDate = $toInput !== null ? Carbon::parse((string) $toInput) : Carbon::today()->endOfMonth();

        return new FileVatReturnData(
            fromDate: $fromDate,
            toDate: $toDate,
            periodId: $periodId,
            notes: $this->filled('notes') ? (string) $this->input('notes') : null,
        );
    }
}
