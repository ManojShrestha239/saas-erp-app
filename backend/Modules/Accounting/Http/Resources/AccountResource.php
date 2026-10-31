<?php

declare(strict_types=1);

namespace Modules\Accounting\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Accounting\Models\Account;

/**
 * @mixin Account
 */
final class AccountResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'type' => $this->type->value,
            'type_label' => $this->type->label(),
            'normal_balance' => $this->type->normalBalance(),
            'parent_id' => $this->parent_id,
            'is_system' => $this->is_system,
            'system_name' => $this->system_name,
            'is_active' => $this->is_active,
            'currency' => $this->currency,
            'description' => $this->description,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'parent' => new self($this->whenLoaded('parent')),
            'children' => self::collection($this->whenLoaded('children')),
        ];
    }
}
