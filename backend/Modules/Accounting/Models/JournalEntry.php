<?php

declare(strict_types=1);

namespace Modules\Accounting\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Modules\Accounting\Enums\JournalEntryStatus;
use Modules\Accounting\Exceptions\ImmutableJournalEntryException;
use Shared\Tenancy\UsesTenantConnection;

/**
 * @property string $id
 * @property string $entry_number
 * @property Carbon $entry_date
 * @property Carbon $posting_date
 * @property JournalEntryStatus $status
 * @property string|null $description
 * @property string|null $source_type
 * @property string|null $source_id
 * @property int $total_debit_cents
 * @property int $total_credit_cents
 * @property string $currency
 * @property string|null $created_by_user_id
 * @property string|null $reversed_by_id
 * @property string|null $reversal_of_id
 * @property string|null $reversal_reason
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, JournalEntryLine> $lines
 * @property-read JournalEntry|null $reversalOf
 * @property-read JournalEntry|null $reversedBy
 */
final class JournalEntry extends Model
{
    use HasUlids;
    use UsesTenantConnection;

    protected $table = 'journal_entries';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'id',
        'entry_number',
        'entry_date',
        'posting_date',
        'status',
        'description',
        'source_type',
        'source_id',
        'total_debit_cents',
        'total_credit_cents',
        'currency',
        'created_by_user_id',
        'reversed_by_id',
        'reversal_of_id',
        'reversal_reason',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'entry_date' => 'date',
        'posting_date' => 'datetime',
        'status' => JournalEntryStatus::class,
        'total_debit_cents' => 'integer',
        'total_credit_cents' => 'integer',
    ];

    protected static function booted(): void
    {
        self::updating(function (self $model): void {
            $dirtyKeys = array_keys($model->getDirty());
            $allowedKeys = ['status', 'reversed_by_id', 'reversal_reason', 'updated_at'];

            $disallowedDirty = array_diff($dirtyKeys, $allowedKeys);
            if (! empty($disallowedDirty)) {
                throw new ImmutableJournalEntryException('Posted journal entries are immutable and cannot be updated directly.');
            }
        });

        self::deleting(function (): void {
            throw new ImmutableJournalEntryException('Posted journal entries are immutable and cannot be deleted.');
        });
    }

    /**
     * @return HasMany<JournalEntryLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(JournalEntryLine::class, 'journal_entry_id')->orderBy('line_number');
    }

    /**
     * @return BelongsTo<self, $this>
     */
    public function reversalOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reversal_of_id');
    }

    /**
     * @return BelongsTo<self, $this>
     */
    public function reversedBy(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reversed_by_id');
    }

    public function isReversed(): bool
    {
        return $this->status === JournalEntryStatus::Reversed;
    }
}
