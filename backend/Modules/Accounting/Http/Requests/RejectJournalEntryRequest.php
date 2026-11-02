<?php

declare(strict_types=1);

namespace Modules\Accounting\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class RejectJournalEntryRequest extends FormRequest
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
            'rejection_reason' => ['required', 'string', 'max:1000'],
        ];
    }
}
