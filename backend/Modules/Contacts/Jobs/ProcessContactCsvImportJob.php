<?php

declare(strict_types=1);

namespace Modules\Contacts\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Modules\Contacts\Services\ContactCsvImportService;
use Shared\Enums\CsvImportStatus;
use Shared\Models\CsvImport;
use Throwable;

final class ProcessContactCsvImportJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $timeout = 300;

    public int $tries = 1;

    public function __construct(
        public readonly CsvImport $import,
        public readonly string $tenantId,
        public readonly string $databaseName,
    ) {
        $this->onQueue('default');
    }

    public function handle(ContactCsvImportService $service): void
    {
        $service->process($this->import, $this->tenantId, $this->databaseName);
    }

    public function failed(Throwable $exception): void
    {
        $this->import->update([
            'status' => CsvImportStatus::Failed,
            'errors' => [
                [
                    'row' => 0,
                    'message' => 'Job processing failed: '.$exception->getMessage(),
                ],
            ],
            'completed_at' => now(),
        ]);
    }
}
