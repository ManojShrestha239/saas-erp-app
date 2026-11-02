<?php

declare(strict_types=1);

namespace Modules\SuperAdmin\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Modules\SuperAdmin\DTOs\AssignSubscriptionData;

final class AssignSubscriptionRequest extends FormRequest
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
            'tenant_id' => ['required', 'string', 'exists:central.tenants,id'],
            'package_id' => ['required', 'integer', 'exists:central.packages,id'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after:starts_at'],
        ];
    }

    public function toDto(): AssignSubscriptionData
    {
        $startsAt = $this->filled('starts_at')
            ? Carbon::parse((string) $this->input('starts_at'))
            : Carbon::now();

        $endsAt = $this->filled('ends_at')
            ? Carbon::parse((string) $this->input('ends_at'))
            : null;

        return new AssignSubscriptionData(
            tenantId: (string) $this->input('tenant_id'),
            packageId: (int) $this->input('package_id'),
            startsAt: $startsAt,
            endsAt: $endsAt,
        );
    }
}
