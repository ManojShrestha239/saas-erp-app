<?php

declare(strict_types=1);

namespace Modules\SuperAdmin\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\SuperAdmin\Models\Subscription;

/**
 * @mixin Subscription
 *
 * @property Subscription $resource
 */
final class SuperAdminSubscriptionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'tenant_id' => $this->tenant_id,
            'package_id' => $this->package_id,
            'status' => $this->status,
            'status_label' => ucfirst($this->status),
            'starts_at' => $this->starts_at->toISOString(),
            'ends_at' => $this->ends_at?->toISOString(),
            'package' => $this->resource->relationLoaded('package') && $this->package !== null
                ? new SuperAdminPackageResource($this->package)
                : null,
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
