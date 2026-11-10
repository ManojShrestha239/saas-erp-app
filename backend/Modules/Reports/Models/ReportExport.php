<?php

declare(strict_types=1);

namespace Modules\Reports\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Modules\Reports\Enums\ReportExportStatus;
use Modules\Reports\Enums\ReportFormat;
use Modules\Reports\Enums\ReportType;
use Shared\Tenancy\UsesTenantConnection;

/**
 * @property string $id
 * @property string $user_id
 * @property ReportType $report_type
 * @property ReportFormat $format
 * @property ReportExportStatus $status
 * @property string|null $file_path
 * @property int|null $file_size_bytes
 * @property string|null $error_message
 * @property array<string, mixed>|null $filters
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class ReportExport extends Model
{
    use HasUlids;
    use UsesTenantConnection;

    protected $table = 'report_exports';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'id',
        'user_id',
        'report_type',
        'format',
        'status',
        'file_path',
        'file_size_bytes',
        'error_message',
        'filters',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'report_type' => ReportType::class,
            'format' => ReportFormat::class,
            'status' => ReportExportStatus::class,
            'filters' => 'array',
            'file_size_bytes' => 'integer',
        ];
    }
}
