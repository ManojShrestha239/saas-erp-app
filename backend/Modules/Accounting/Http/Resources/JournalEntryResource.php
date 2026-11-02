<?php

declare(strict_types=1);

namespace Modules\Accounting\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Accounting\Models\JournalEntry;

/**
 * @mixin JournalEntry
 */
final class JournalEntryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'entry_number' => $this->entry_number,
            'entry_date' => $this->entry_date->toDateString(),
            'posting_date' => $this->posting_date?->toIso8601String(),
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'description' => $this->description,
            'source_type' => $this->source_type,
            'source_id' => $this->source_id,
            'total_debit_cents' => $this->total_debit_cents,
            'total_credit_cents' => $this->total_credit_cents,
            'currency' => $this->currency,
            'created_by_user_id' => $this->created_by_user_id,
            'submitted_by_user_id' => $this->submitted_by_user_id,
            'submitted_at' => $this->submitted_at?->toIso8601String(),
            'approved_by_user_id' => $this->approved_by_user_id,
            'approved_at' => $this->approved_at?->toIso8601String(),
            'rejected_by_user_id' => $this->rejected_by_user_id,
            'rejected_at' => $this->rejected_at?->toIso8601String(),
            'rejection_reason' => $this->rejection_reason,
            'reversed_by_id' => $this->reversed_by_id,
            'reversal_of_id' => $this->reversal_of_id,
            'reversal_reason' => $this->reversal_reason,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'lines' => JournalEntryLineResource::collection($this->whenLoaded('lines')),
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
