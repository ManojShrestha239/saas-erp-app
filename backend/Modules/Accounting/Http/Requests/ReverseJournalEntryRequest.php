<?php

declare(strict_types=1);

namespace Modules\Accounting\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Shared\Exceptions\ApiException;

final class ReverseJournalEntryRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Policy will handle permissions
        return true;
    }

    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:5', 'max:500'],
            'user_id' => ['nullable', 'string', 'max:36'],
        ];
    }

    protected function failedValidation($validator)
    {
        $errors = $validator->errors()->toArray();
        throw ApiException::unprocessable(
            message: 'Validation failed for journal reversal',
            errorCode: 'VALIDATION_FAILED',
            errors: $errors,
        );
    }
}
