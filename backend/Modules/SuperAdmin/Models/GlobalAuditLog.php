<?php

declare(strict_types=1);

namespace Modules\SuperAdmin\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Shared\Exceptions\ApiException;

/**
 * @property int $id
 * @property string|null $tenant_id
 * @property string|null $user_id
 * @property string $event
 * @property string|null $auditable_type
 * @property string|null $auditable_id
 * @property array<string, mixed>|null $old_values
 * @property array<string, mixed>|null $new_values
 * @property string|null $ip_address
 * @property string|null $user_agent
 * @property Carbon|null $created_at
 */
final class GlobalAuditLog extends Model
{
    /**
     * @var string
     */
    protected $connection = 'central';

    /**
     * @var string
     */
    protected $table = 'global_audit_logs';

    /**
     * @var bool
     */
    public $timestamps = false;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'user_id',
        'event',
        'auditable_type',
        'auditable_id',
        'old_values',
        'new_values',
        'ip_address',
        'user_agent',
        'created_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'old_values' => 'array',
            'new_values' => 'array',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        self::creating(function (self $log): void {
            if ($log->created_at === null) {
                $log->created_at = Carbon::now();
            }

            try {
                if ($log->ip_address === null && request()->ip() !== null) {
                    $log->ip_address = request()->ip();
                }
                if ($log->user_agent === null && request()->userAgent() !== null) {
                    $log->user_agent = request()->userAgent();
                }
            } catch (\Throwable) {
            }
        });

        self::updating(function (): void {
            throw ApiException::badRequest(
                'Audit logs are immutable and cannot be updated.',
                'AUDIT_LOG_IMMUTABLE'
            );
        });

        self::deleting(function (): void {
            throw ApiException::badRequest(
                'Audit logs are immutable and cannot be deleted.',
                'AUDIT_LOG_IMMUTABLE'
            );
        });
    }
}
