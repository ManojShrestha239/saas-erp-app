<?php

declare(strict_types=1);

namespace Modules\Auth\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;
use Modules\Auth\Mail\ResetPasswordMail;
use Shared\Tenancy\TenantContext;
use Shared\Tenancy\TenantManager;

final class SendPasswordResetEmail implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public readonly string $tenantId,
        public readonly string $tenantSlug,
        public readonly string $tenantDatabaseName,
        public readonly string $email,
        public readonly string $token,
    ) {}

    public function handle(TenantManager $tenantManager): void
    {
        $context = new TenantContext(
            id: $this->tenantId,
            slug: $this->tenantSlug,
            databaseName: $this->tenantDatabaseName,
        );

        $tenantManager->switchTo($context);

        try {
            Mail::to($this->email)->send(new ResetPasswordMail(
                token: $this->token,
                email: $this->email,
                tenantSlug: $this->tenantSlug,
            ));
        } finally {
            $tenantManager->reset();
        }
    }
}
