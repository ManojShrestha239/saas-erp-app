<?php

declare(strict_types=1);

namespace Shared\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Shared\Enums\DocumentType;
use Shared\Tenancy\UsesTenantConnection;

/**
 * @property string $id
 * @property DocumentType|string $type
 * @property string $prefix
 * @property int $year
 * @property int $current_number
 * @property int $padding
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class DocumentSequence extends Model
{
    use HasUlids;
    use UsesTenantConnection;

    protected $table = 'document_sequences';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'id',
        'type',
        'prefix',
        'year',
        'current_number',
        'padding',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => DocumentType::class,
            'year' => 'integer',
            'current_number' => 'integer',
            'padding' => 'integer',
        ];
    }
}
