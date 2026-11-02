<?php

declare(strict_types=1);

namespace Modules\SuperAdmin\Http\Resources;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;

/**
 * @property Model $resource
 * @property string $id
 * @property string $name
 * @property string $slug
 * @property string $database_name
 * @property string|null $owner_email
 * @property string $status
 * @property string|null $provisioning_error
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Collection<int, Model>|null $domains
 */
final class SuperAdminTenantResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $domainList = [];
        $primaryDomain = null;

        if ($this->resource->relationLoaded('domains') && $this->domains !== null) {
            foreach ($this->domains as $d) {
                /** @var string $host */
                $host = $d->getAttribute('host');
                /** @var bool $isPrimary */
                $isPrimary = (bool) $d->getAttribute('is_primary');
                $domainList[] = $host;
                if ($isPrimary || $primaryDomain === null) {
                    $primaryDomain = $host;
                }
            }
        }

        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'database_name' => $this->database_name,
            'owner_email' => $this->owner_email,
            'status' => $this->status,
            'status_label' => ucfirst(str_replace('_', ' ', $this->status)),
            'provisioning_error' => $this->provisioning_error,
            'domains' => $domainList,
            'primary_domain' => $primaryDomain,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
