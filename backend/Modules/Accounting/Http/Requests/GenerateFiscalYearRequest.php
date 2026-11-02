<?php

declare(strict_types=1);

namespace Modules\Accounting\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class GenerateFiscalYearRequest extends FormRequest
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
            'fiscal_year' => ['required', 'integer', 'min:1900', 'max:2100'],
            'start_date' => ['nullable', 'date'],
        ];
    }
}
