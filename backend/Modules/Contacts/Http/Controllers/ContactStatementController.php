<?php

declare(strict_types=1);

namespace Modules\Contacts\Http\Controllers;

use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Modules\Contacts\Services\ContactService;
use Modules\Contacts\Services\ContactStatementService;
use Shared\Exceptions\ApiException;
use Shared\Responses\ApiResponse;

final class ContactStatementController
{
    public function __construct(
        private readonly ContactService $contactService,
        private readonly ContactStatementService $statementService,
    ) {}

    public function aging(Request $request, string $id): JsonResponse
    {
        $contact = $this->contactService->findById($id);
        if ($contact === null) {
            throw ApiException::notFound("Contact '{$id}' not found.", 'CONTACT_NOT_FOUND');
        }

        Gate::authorize('viewStatement', $contact);

        $request->validate([
            'as_of_date' => ['nullable', 'date'],
            'type' => ['nullable', 'string', 'in:customer,supplier'],
        ]);

        $asOfDate = $request->filled('as_of_date')
            ? Carbon::parse((string) $request->query('as_of_date'))
            : null;
        $type = $request->filled('type') ? (string) $request->query('type') : null;

        $aging = $this->statementService->getAging($id, $asOfDate, $type);

        return ApiResponse::success(
            data: $aging,
            message: 'Aging buckets calculated successfully.'
        );
    }

    public function statement(Request $request, string $id): JsonResponse
    {
        $contact = $this->contactService->findById($id);
        if ($contact === null) {
            throw ApiException::notFound("Contact '{$id}' not found.", 'CONTACT_NOT_FOUND');
        }

        Gate::authorize('viewStatement', $contact);

        $request->validate([
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date'],
            'statement_type' => ['nullable', 'string', 'in:customer,supplier'],
        ]);

        $startDate = $request->filled('start_date')
            ? Carbon::parse((string) $request->query('start_date'))
            : null;
        $endDate = $request->filled('end_date')
            ? Carbon::parse((string) $request->query('end_date'))
            : null;
        $statementType = $request->filled('statement_type')
            ? (string) $request->query('statement_type')
            : null;

        $statement = $this->statementService->getStatement($id, $startDate, $endDate, $statementType);

        return ApiResponse::success(
            data: $statement,
            message: 'Contact statement generated successfully.'
        );
    }

    public function pdf(Request $request, string $id): Response
    {
        $contact = $this->contactService->findById($id);
        if ($contact === null) {
            throw ApiException::notFound("Contact '{$id}' not found.", 'CONTACT_NOT_FOUND');
        }

        Gate::authorize('viewStatement', $contact);

        $request->validate([
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date'],
            'statement_type' => ['nullable', 'string', 'in:customer,supplier'],
            'download' => ['nullable', 'boolean'],
        ]);

        $startDate = $request->filled('start_date')
            ? Carbon::parse((string) $request->query('start_date'))
            : null;
        $endDate = $request->filled('end_date')
            ? Carbon::parse((string) $request->query('end_date'))
            : null;
        $statementType = $request->filled('statement_type')
            ? (string) $request->query('statement_type')
            : null;
        $download = $request->boolean('download');

        return $this->statementService->renderPdf($id, $startDate, $endDate, $statementType, $download);
    }
}
