<?php

declare(strict_types=1);

namespace Modules\Sales\Commands;

use Illuminate\Console\Command;
use Modules\Sales\Services\OverdueInvoiceAlertService;
use Modules\Tenancy\Services\TenantManagementService;
use Shared\Tenancy\TenantManager;

final class SendOverdueInvoicesFcmAlertCommand extends Command
{
    protected $signature = 'alerts:send-overdue-invoices
        {--tenant= : Optional tenant slug or ID}';

    protected $description = 'Send FCM push alerts for overdue sales invoices to authorized tenant users';

    public function handle(
        TenantManagementService $tenantManagementService,
        TenantManager $tenantManager,
        OverdueInvoiceAlertService $alertService,
    ): int {
        /** @var string|null $tenantOption */
        $tenantOption = $this->option('tenant');

        if (! empty($tenantOption)) {
            $context = $tenantManagementService->findTenantContext($tenantOption);
            if ($context === null) {
                $this->error("Tenant '{$tenantOption}' not found.");

                return self::FAILURE;
            }
            $contexts = [$context];
        } else {
            $contexts = $tenantManagementService->getActiveTenantContexts();
        }

        if (empty($contexts)) {
            $this->info('No active tenants to process.');

            return self::SUCCESS;
        }

        $totalSent = 0;
        $totalInvoices = 0;

        foreach ($contexts as $context) {
            $tenantManager->switchTo($context);

            try {
                $result = $alertService->sendOverdueAlerts();

                if ($result['sent']) {
                    $totalSent++;
                    $totalInvoices += $result['overdue_count'];
                    $this->info(sprintf(
                        '[%s] Sent overdue alert for %d invoice(s) totaling $%.2f',
                        $context->slug,
                        $result['overdue_count'],
                        $result['total_overdue_cents'] / 100,
                    ));
                } else {
                    $this->line(sprintf(
                        '[%s] Skipped (%s)',
                        $context->slug,
                        $result['reason'] ?? 'unknown',
                    ));
                }
            } catch (\Throwable $e) {
                $this->error(sprintf(
                    '[%s] Error processing overdue alert: %s',
                    $context->slug,
                    $e->getMessage(),
                ));
            } finally {
                $tenantManager->reset();
            }
        }

        $this->newLine();
        $this->info(sprintf(
            'Overdue invoice alerts completed: %d tenant(s) notified, %d overdue invoice(s) reported.',
            $totalSent,
            $totalInvoices,
        ));

        return self::SUCCESS;
    }
}
