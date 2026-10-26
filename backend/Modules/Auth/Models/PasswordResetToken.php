<?php

declare(strict_types=1);

namespace Modules\Auth\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Shared\Tenancy\UsesTenantConnection;

/**
 * @property string $email
 * @property string $token
 * @property Carbon|null $created_at
 * @property Carbon|null $expires_at
 */
final class PasswordResetToken extends Model
{
    use UsesTenantConnection;

    protected $table = 'password_reset_tokens';

    protected $primaryKey = 'email';

    protected $keyType = 'string';

    public $timestamps = false;

    public $incrementing = false;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'email',
        'token',
        'created_at',
        'expires_at',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'created_at' => 'datetime',
        'expires_at' => 'datetime',
    ];
}
