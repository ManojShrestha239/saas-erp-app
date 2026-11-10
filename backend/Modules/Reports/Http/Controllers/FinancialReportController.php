<?php

declare(strict_types=1);

namespace Modules\Reports\Http\Controllers;

use App\Http\Controllers\Controller;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Accounting\Services\FinancialReportService;
use Shared\Responses\ApiResponse;

final class FinancialReportController extends Controller
{
    public function __construct(
        private readonly FinancialReportService $financialReportService,
    ) {}

    public function trialBalance(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'as_of_date' => ['nullable', 'date'],
            'include_zero_balances' => ['nullable', 'boolean'],
        ]);

        $asOfDate = isset($validated['as_of_date']) ? (string) $validated['as_of_date'] : null;
        $includeZeros = isset($validated['include_zero_balances']) ? (bool) $validated['include_zero_balances'] : false;

        $report = $this->financialReportService->getTrialBalance($asOfDate, $includeZeros);

        return ApiResponse::success(
            data: $report->toArray(),
            message: 'Trial balance generated successfully.'
        );
    }

    public function profitAndLoss(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
        ]);

        $startDate = isset($validated['start_date'])
            ? (string) $validated['start_date']
            : Carbon::now()->startOfYear()->toDateString();

        $endDate = isset($validated['end_date'])
            ? (string) $validated['end_date']
            : Carbon::now()->toDateString();

        $report = $this->financialReportService->getProfitAndLoss($startDate, $endDate);

        return ApiResponse::success(
            data: $report->toArray(),
            message: 'Profit and loss statement generated successfully.'
        );
    }

    public function balanceSheet(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'as_of_date' => ['nullable', 'date'],
        ]);

        $asOfDate = isset($validated['as_of_date']) ? (string) $validated['as_of_date'] : null;

        $report = $this->financialReportService->getBalanceSheet($asOfDate);

        return ApiResponse::success(
            data: $report->toArray(),
            message: 'Balance sheet generated successfully.'
        );
    }
}
