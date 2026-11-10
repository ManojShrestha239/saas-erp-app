<?php

declare(strict_types=1);

namespace Modules\Audit\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Audit\Models\TenantActivity;

/**
 * @mixin TenantActivity
 */
final class AuditLogResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $props = $this->properties?->toArray() ?? [];
        $changes = $this->attribute_changes?->toArray() ?? [];
        $causer = $this->causer;

        $old = $changes['old'] ?? ($props['old'] ?? null);
        $attributes = $changes['attributes'] ?? ($props['attributes'] ?? null);

        return [
            'id' => $this->id,
            'log_name' => $this->log_name,
            'description' => $this->description,
            'subject_type' => $this->subject_type,
            'subject_id' => $this->subject_id,
            'event' => $this->event,
            'causer_type' => $this->causer_type,
            'causer_id' => $this->causer_id,
            'causer' => $causer ? [
                'id' => $causer->getKey(),
                'name' => (string) ($causer->getAttribute('name') ?? $causer->getAttribute('email') ?? 'User'),
                'email' => $causer->getAttribute('email'),
            ] : null,
            'properties' => $props,
            'attribute_changes' => $changes,
            'changes' => [
                'old' => $old,
                'attributes' => $attributes,
            ],
            'ip_address' => $this->ip_address ?? ($props['ip'] ?? null),
            'user_agent' => $this->user_agent ?? ($props['user_agent'] ?? null),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
