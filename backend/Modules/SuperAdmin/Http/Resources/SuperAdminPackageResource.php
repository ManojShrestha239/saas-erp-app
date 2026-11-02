<?php

declare(strict_types=1);

namespace Modules\SuperAdmin\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property int $max_users
 * @property int $max_warehouses
 * @property int $max_invoices_per_month
 * @property array<string, mixed> $features
 * @property int $price_cents
 * @property int|null $subscriptions_count
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class SuperAdminPackageResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'max_users' => $this->max_users,
            'max_warehouses' => $this->max_warehouses,
            'max_invoices_per_month' => $this->max_invoices_per_month,
            'features' => $this->features,
            'price_cents' => $this->price_cents,
            'price_formatted' => number_format($this->price_cents / 100, 2),
            'subscriptions_count' => $this->subscriptions_count ?? 0,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
