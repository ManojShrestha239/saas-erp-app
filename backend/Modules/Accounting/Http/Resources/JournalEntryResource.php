<?php

declare(strict_types=1);

namespace Modules\Accounting\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Accounting\Models\JournalEntry;

final class JournalEntryResource extends JsonResource
{
    /**
     * @param  JournalEntry  $this
     */
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'entry_number' => $this->entry_number,
            'entry_date' => $this->entry_date?->toDateString(),
            'posting_date' => $this->posting_date?->toIso8601String(),
            'status' => $this->status->value,
            'description' => $this->description,
            'source_type' => $this->source_type,
            'source_id' => $this->source_id,
            'total_debit_cents' => $this->total_debit_cents,
            'total_credit_cents' => $this->total_credit_cents,
            'currency' => $this->currency,
            'created_by_user_id' => $this->created_by_user_id,
            'reversal_reason' => $this->reversal_reason,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'lines' => JournalEntryLineResource::collection($this->whenLoaded('lines')),
        ];
    }
}
