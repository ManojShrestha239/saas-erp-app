<?php

declare(strict_types=1);

namespace Modules\Accounting\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Accounting\Models\JournalEntryLine;

final class JournalEntryLineResource extends JsonResource
{
    /**
     * @param  JournalEntryLine  $this
     */
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'account_id' => $this->account_id,
            'account_code' => $this->account->code ?? null,
            'account_name' => $this->account->name ?? null,
            'line_number' => $this->line_number,
            'debit_cents' => $this->debit_cents,
            'credit_cents' => $this->credit_cents,
            'description' => $this->description,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
