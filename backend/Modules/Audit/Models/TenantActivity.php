<?php

declare(strict_types=1);

namespace Modules\Audit\Models;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Shared\Exceptions\ApiException;
use Shared\Tenancy\UsesTenantConnection;
use Spatie\Activitylog\Models\Activity as SpatieActivity;

/**
 * @property int $id
 * @property string|null $log_name
 * @property string $description
 * @property string|null $subject_type
 * @property string|null $subject_id
 * @property string|null $causer_type
 * @property string|null $causer_id
 * @property string|null $event
 * @property Collection<string, mixed>|null $attribute_changes
 * @property Collection<string, mixed>|null $properties
 * @property string|null $batch_uuid
 * @property string|null $ip_address
 * @property string|null $user_agent
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class TenantActivity extends SpatieActivity
{
    use UsesTenantConnection;

    /**
     * @var string
     */
    protected $table = 'activity_log';

    protected static function booted(): void
    {
        parent::booted();

        static::creating(function (self $activity): void {
            try {
                if ($activity->ip_address === null && request()->ip() !== null) {
                    $activity->ip_address = request()->ip();
                }
                if ($activity->user_agent === null && request()->userAgent() !== null) {
                    $activity->user_agent = request()->userAgent();
                }
            } catch (\Throwable) {
            }
        });

        static::updating(function (): void {
            throw ApiException::badRequest(
                'Audit logs are immutable and cannot be updated.',
                'AUDIT_LOG_IMMUTABLE'
            );
        });

        static::deleting(function (): void {
            throw ApiException::badRequest(
                'Audit logs are immutable and cannot be deleted.',
                'AUDIT_LOG_IMMUTABLE'
            );
        });
    }
}
