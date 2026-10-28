<?php

declare(strict_types=1);

namespace Modules\Contacts\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class ImportContactCsvRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user !== null && ($user->can('customers.manage') || $user->can('suppliers.manage'));
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:10240'],
            'duplicate_action' => ['sometimes', 'string', 'in:skip,update'],
        ];
    }
}
