<?php

declare(strict_types=1);

namespace Modules\Accounting\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Shared\Exceptions\ApiException;

final class PostManualJournalRequest extends FormRequest
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
            'entry_date' => ['required', 'date'],
            'status' => ['nullable', 'string', 'in:draft,pending_approval,posted'],
            'description' => ['nullable', 'string', 'max:1000'],
            'source_type' => ['nullable', 'string', 'max:255'],
            'source_id' => ['nullable', 'string', 'max:255'],
            'currency' => ['nullable', 'string', 'size:3'],
            'user_id' => ['nullable', 'string', 'max:36'],
            'lines' => ['required', 'array', 'min:2'],
            'lines.*.account_id' => ['required', 'string', 'max:36'],
            'lines.*.debit_cents' => ['nullable', 'integer', 'min:0'],
            'lines.*.credit_cents' => ['nullable', 'integer', 'min:0'],
            'lines.*.description' => ['nullable', 'string', 'max:500'],
        ];
    }

    protected function failedValidation($validator)
    {
        $errors = $validator->errors()->toArray();
        throw ApiException::unprocessable(
            message: 'Validation failed for journal entry',
            errorCode: 'VALIDATION_FAILED',
            errors: $errors,
        );
    }
}
