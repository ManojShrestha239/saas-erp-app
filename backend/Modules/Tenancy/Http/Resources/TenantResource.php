<?php

declare(strict_types=1);

namespace Modules\Tenancy\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property string $id
 * @property string $name
 * @property string $slug
 * @property string $database_name
 * @property string $status
 * @property string $created_at
 */
final class TenantResource extends JsonResource
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
            'database_name' => $this->database_name,
            'status' => $this->status,
            'created_at' => $this->created_at,
        ];
    }
}
