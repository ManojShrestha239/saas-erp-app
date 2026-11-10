<?php

declare(strict_types=1);

namespace Modules\Audit\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Auth\Enums\TenantPermission;

final class ListAuditLogsRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        if ($user === null) {
            return false;
        }

        return $user->can(TenantPermission::AuditView->value);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:100'],
            'event' => ['nullable', 'string', 'max:50'],
            'subject_type' => ['nullable', 'string', 'max:100'],
            'causer_id' => ['nullable', 'string', 'max:50'],
            'log_name' => ['nullable', 'string', 'max:50'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'sort_by' => ['nullable', 'string', 'in:id,created_at,event,subject_type,description'],
            'sort_direction' => ['nullable', 'string', 'in:asc,desc'],
        ];
    }
}
