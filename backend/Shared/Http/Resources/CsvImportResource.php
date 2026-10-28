<?php

declare(strict_types=1);

namespace Shared\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Shared\Models\CsvImport;

/**
 * @mixin CsvImport
 */
final class CsvImportResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $percentage = $this->total_rows > 0
            ? (int) min(100, round(($this->processed_rows / $this->total_rows) * 100))
            : ($this->status->isFinished() ? 100 : 0);

        return [
            'id' => $this->id,
            'type' => [
                'value' => $this->type->value,
                'label' => $this->type->label(),
            ],
            'file_name' => $this->file_name,
            'status' => [
                'value' => $this->status->value,
                'label' => $this->status->label(),
            ],
            'duplicate_action' => [
                'value' => $this->duplicate_action->value,
                'label' => $this->duplicate_action->label(),
            ],
            'total_rows' => $this->total_rows,
            'processed_rows' => $this->processed_rows,
            'successful_rows' => $this->successful_rows,
            'failed_rows' => $this->failed_rows,
            'progress_percentage' => $percentage,
            'errors' => $this->errors ?? [],
            'started_at' => $this->started_at?->toISOString(),
            'completed_at' => $this->completed_at?->toISOString(),
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
