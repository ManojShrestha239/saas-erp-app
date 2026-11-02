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
 * @property string|null $entry_number
 * @property Carbon $entry_date
 * @property Carbon|null $posting_date
 * @property JournalEntryStatus $status
 * @property string|null $description
 * @property string|null $source_type
 * @property string|null $source_id
 * @property int $total_debit_cents
 * @property int $total_credit_cents
 * @property string $currency
 * @property string|null $created_by_user_id
 * @property string|null $submitted_by_user_id
 * @property Carbon|null $submitted_at
 * @property string|null $approved_by_user_id
 * @property Carbon|null $approved_at
 * @property string|null $rejected_by_user_id
 * @property Carbon|null $rejected_at
 * @property string|null $rejection_reason
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
        'submitted_by_user_id',
        'submitted_at',
        'approved_by_user_id',
        'approved_at',
        'rejected_by_user_id',
        'rejected_at',
        'rejection_reason',
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
        'submitted_at' => 'datetime',
        'approved_at' => 'datetime',
        'rejected_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        self::updating(function (self $model): void {
            $originalStatus = $model->getOriginal('status');
            $originalStatusValue = $originalStatus instanceof JournalEntryStatus
                ? $originalStatus->value
                : (string) $originalStatus;

            if (in_array($originalStatusValue, [JournalEntryStatus::Posted->value, JournalEntryStatus::Reversed->value], true)) {
                $dirtyKeys = array_keys($model->getDirty());
                $allowedKeys = ['status', 'reversed_by_id', 'reversal_reason', 'updated_at'];

                $disallowedDirty = array_diff($dirtyKeys, $allowedKeys);
                if (! empty($disallowedDirty)) {
                    throw new ImmutableJournalEntryException('Posted journal entries are immutable and cannot be updated directly.');
                }
            }
        });

        self::deleting(function (self $model): void {
            $originalStatus = $model->getOriginal('status');
            $originalStatusValue = $originalStatus instanceof JournalEntryStatus
                ? $originalStatus->value
                : (string) $originalStatus;

            if (in_array($originalStatusValue, [JournalEntryStatus::Posted->value, JournalEntryStatus::Reversed->value], true)) {
                throw new ImmutableJournalEntryException('Posted journal entries are immutable and cannot be deleted.');
            }
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

    public function isPosted(): bool
    {
        return $this->status === JournalEntryStatus::Posted;
    }

    public function isDraft(): bool
    {
        return $this->status === JournalEntryStatus::Draft;
    }

    public function isPendingApproval(): bool
    {
        return $this->status === JournalEntryStatus::PendingApproval;
    }

    public function isRejected(): bool
    {
        return $this->status === JournalEntryStatus::Rejected;
    }
}
