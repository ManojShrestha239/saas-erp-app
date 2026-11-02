<?php

declare(strict_types=1);

namespace Modules\SuperAdmin\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $key
 * @property string $name
 * @property string|null $description
 * @property bool $is_enabled
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class FeatureFlag extends Model
{
    /**
     * @var string
     */
    protected $connection = 'central';

    /**
     * @var string
     */
    protected $table = 'feature_flags';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'key',
        'name',
        'description',
        'is_enabled',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
        ];
    }
}
