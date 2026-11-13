<?php

declare(strict_types=1);

namespace Modules\Auth\Services;

use Illuminate\Database\DatabaseManager;
use Modules\Auth\Models\Setting;

final readonly class TenantSettingsService
{
    /**
     * Known settings group mappings.
     *
     * @var array<string, string>
     */
    private const GROUP_MAP = [
        'company_name' => 'company',
        'company_tax_id' => 'company',
        'tax_number' => 'company',
        'company_address' => 'company',
        'company_email' => 'company',
        'company_phone' => 'company',
        'bank_details' => 'finance',
        'currency' => 'finance',
        'fiscal_year_start' => 'finance',
        'timezone' => 'general',
        'date_format' => 'general',
        'allow_negative_stock' => 'inventory',
        'low_stock_threshold_default' => 'inventory',
        'invoice_prefix' => 'documents',
        'bill_prefix' => 'documents',
        'quotation_prefix' => 'documents',
        'po_prefix' => 'documents',
    ];

    public function __construct(
        private DatabaseManager $db,
    ) {}

    /**
     * Retrieve all tenant settings key-value dictionary.
     *
     * @return array<string, string|null>
     */
    public function getAll(): array
    {
        /** @var array<string, string|null> $settings */
        $settings = Setting::query()->pluck('value', 'key')->all();

        return $settings;
    }

    /**
     * Retrieve a single setting by key.
     */
    public function get(string $key, ?string $default = null): ?string
    {
        $setting = Setting::query()->where('key', $key)->first();

        return $setting !== null ? $setting->value : $default;
    }

    /**
     * Batch update or insert tenant settings.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, string|null>
     */
    public function updateSettings(array $data): array
    {
        $this->db->connection('tenant')->transaction(function () use ($data): void {
            foreach ($data as $key => $value) {
                $group = self::GROUP_MAP[$key] ?? 'general';

                $stringVal = $value === null ? null : (is_bool($value) ? ($value ? '1' : '0') : (string) $value);

                Setting::query()->updateOrCreate(
                    ['key' => $key],
                    [
                        'value' => $stringVal,
                        'group' => $group,
                    ]
                );
            }
        }, 3);

        return $this->getAll();
    }
}
