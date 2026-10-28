<?php

declare(strict_types=1);

namespace Shared\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Shared\Enums\CsvDuplicateAction;
use Shared\Enums\CsvImportStatus;
use Shared\Enums\CsvImportType;
use Shared\Tenancy\UsesTenantConnection;

/**
 * @property string $id
 * @property CsvImportType $type
 * @property string $file_name
 * @property string $file_path
 * @property CsvImportStatus $status
 * @property CsvDuplicateAction $duplicate_action
 * @property int $total_rows
 * @property int $processed_rows
 * @property int $successful_rows
 * @property int $failed_rows
 * @property array<int, array{row: int, field?: string, message: string, raw_data?: array<string, mixed>}>|null $errors
 * @property string|null $user_id
 * @property Carbon|null $started_at
 * @property Carbon|null $completed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class CsvImport extends Model
{
    use HasUlids;
    use UsesTenantConnection;

    protected $table = 'csv_imports';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'id',
        'type',
        'file_name',
        'file_path',
        'status',
        'duplicate_action',
        'total_rows',
        'processed_rows',
        'successful_rows',
        'failed_rows',
        'errors',
        'user_id',
        'started_at',
        'completed_at',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'type' => CsvImportType::class,
        'status' => CsvImportStatus::class,
        'duplicate_action' => CsvDuplicateAction::class,
        'total_rows' => 'integer',
        'processed_rows' => 'integer',
        'successful_rows' => 'integer',
        'failed_rows' => 'integer',
        'errors' => 'array',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];
}
