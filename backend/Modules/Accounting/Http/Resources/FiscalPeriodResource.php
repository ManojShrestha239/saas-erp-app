<?php

declare(strict_types=1);

namespace Modules\Accounting\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Accounting\Models\FiscalPeriod;

/**
 * @mixin FiscalPeriod
 */
final class FiscalPeriodResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'fiscal_year' => $this->fiscal_year,
            'period_number' => $this->period_number,
            'start_date' => $this->start_date->toDateString(),
            'end_date' => $this->end_date->toDateString(),
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'locked_at' => $this->locked_at?->toIso8601String(),
            'locked_by_user_id' => $this->locked_by_user_id,
            'closed_at' => $this->closed_at?->toIso8601String(),
            'closed_by_user_id' => $this->closed_by_user_id,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function with(Request $request): array
    {
        return [
            'success' => true,
        ];
    }
}
