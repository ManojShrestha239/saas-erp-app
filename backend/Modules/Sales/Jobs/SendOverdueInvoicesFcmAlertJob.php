<?php

declare(strict_types=1);

namespace Modules\Sales\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Modules\Sales\Services\OverdueInvoiceAlertService;
use Shared\Tenancy\TenantContext;
use Shared\Tenancy\TenantManager;

final class SendOverdueInvoicesFcmAlertJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public readonly string $tenantId,
        public readonly string $tenantSlug,
        public readonly string $tenantDatabaseName,
    ) {}

    public function handle(
        TenantManager $tenantManager,
        OverdueInvoiceAlertService $alertService,
    ): void {
        $context = new TenantContext(
            id: $this->tenantId,
            slug: $this->tenantSlug,
            databaseName: $this->tenantDatabaseName,
        );

        $tenantManager->switchTo($context);

        try {
            $result = $alertService->sendOverdueAlerts();

            Log::info("SendOverdueInvoicesFcmAlertJob processed for tenant {$this->tenantSlug}", [
                'tenant_id' => $this->tenantId,
                'sent' => $result['sent'],
                'overdue_count' => $result['overdue_count'],
            ]);
        } finally {
            $tenantManager->reset();
        }
    }
}
