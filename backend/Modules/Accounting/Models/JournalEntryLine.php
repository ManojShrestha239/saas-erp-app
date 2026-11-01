<?php

declare(strict_types=1);

namespace Modules\Accounting\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Shared\Tenancy\UsesTenantConnection;

/**
 * @property string $id
 * @property string $journal_entry_id
 * @property string $account_id
 * @property int $line_number
 * @property int $debit_cents
 * @property int $credit_cents
 * @property string|null $description
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read JournalEntry $journalEntry
 * @property-read Account $account
 */
final class JournalEntryLine extends Model
{
    use HasUlids;
    use UsesTenantConnection;

    protected $table = 'journal_entry_lines';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'id',
        'journal_entry_id',
        'account_id',
        'line_number',
        'debit_cents',
        'credit_cents',
        'description',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'line_number' => 'integer',
        'debit_cents' => 'integer',
        'credit_cents' => 'integer',
    ];

    /**
     * @return BelongsTo<JournalEntry, $this>
     */
    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'journal_entry_id');
    }

    /**
     * @return BelongsTo<Account, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'account_id');
    }
}
