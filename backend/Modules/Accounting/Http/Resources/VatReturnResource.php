<?php

declare(strict_types=1);

namespace Modules\Accounting\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Accounting\Models\VatReturn;

/**
 * @mixin VatReturn
 */
final class VatReturnResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'return_number' => $this->return_number,
            'fiscal_period_id' => $this->fiscal_period_id,
            'from_date' => $this->from_date->toDateString(),
            'to_date' => $this->to_date->toDateString(),
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'sales_taxable_cents' => $this->sales_taxable_cents,
            'output_vat_cents' => $this->output_vat_cents,
            'purchases_taxable_cents' => $this->purchases_taxable_cents,
            'input_vat_cents' => $this->input_vat_cents,
            'net_vat_cents' => $this->net_vat_cents,
            'rate_breakdown' => $this->rate_breakdown,
            'is_reconciled' => $this->is_reconciled,
            'gl_output_vat_cents' => $this->gl_output_vat_cents,
            'gl_input_vat_cents' => $this->gl_input_vat_cents,
            'gl_discrepancy_cents' => $this->gl_discrepancy_cents,
            'filed_by_user_id' => $this->filed_by_user_id,
            'filed_at' => $this->filed_at?->toIso8601String(),
            'notes' => $this->notes,
            'created_at' => $this->created_at?->toIso8601String(),
            'fiscal_period' => $this->fiscalPeriod !== null ? [
                'id' => (string) $this->fiscalPeriod->id,
                'name' => $this->fiscalPeriod->name,
                'fiscal_year' => $this->fiscalPeriod->fiscal_year,
                'period_number' => $this->fiscalPeriod->period_number,
                'status' => $this->fiscalPeriod->status->value,
            ] : null,
        ];
    }

    /**
     * @return array{success: bool}
     */
    public function with(Request $request): array
    {
        return [
            'success' => true,
        ];
    }
}
