<?php

declare(strict_types=1);

namespace Modules\Contacts\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Modules\Contacts\Http\Requests\StoreContactRequest;
use Modules\Contacts\Http\Requests\UpdateContactRequest;
use Modules\Contacts\Http\Resources\ContactResource;
use Modules\Contacts\Models\Contact;
use Modules\Contacts\Services\ContactService;
use Shared\Exceptions\ApiException;
use Shared\Responses\ApiResponse;

final class ContactController
{
    public function __construct(
        private readonly ContactService $contactService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', Contact::class);

        $perPage = min(max((int) $request->query('per_page', 25), 1), 100);

        /** @var string|null $search */
        $search = $request->query('search');
        /** @var string|null $type */
        $type = $request->query('type');
        /** @var bool|null $isActive */
        $isActive = $request->has('is_active') ? $request->boolean('is_active') : null;

        $sortBy = (string) $request->query('sort_by', 'name');
        $sortDirection = strtolower((string) $request->query('sort_direction', 'asc')) === 'desc' ? 'desc' : 'asc';

        $allowedSorts = ['name', 'code', 'created_at', 'opening_balance_cents', 'credit_limit_cents'];
        if (! in_array($sortBy, $allowedSorts, true)) {
            $sortBy = 'name';
        }

        $paginator = $this->contactService->paginate(
            filters: [
                'search' => $search,
                'type' => $type,
                'is_active' => $isActive,
            ],
            sort: [
                'field' => $sortBy,
                'direction' => $sortDirection,
            ],
            perPage: $perPage,
        );

        $meta = [
            'current_page' => $paginator->currentPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
            'last_page' => $paginator->lastPage(),
        ];

        return ApiResponse::success(
            data: ContactResource::collection($paginator->items()),
            message: 'Contacts retrieved successfully.',
            meta: $meta,
        );
    }

    public function show(string $id): JsonResponse
    {
        $contact = $this->contactService->findById($id);
        if ($contact === null) {
            throw ApiException::notFound("Contact with ID '{$id}' not found.", 'CONTACT_NOT_FOUND');
        }

        Gate::authorize('view', $contact);

        return ApiResponse::success(
            data: new ContactResource($contact),
            message: 'Contact retrieved successfully.',
        );
    }

    public function store(StoreContactRequest $request): JsonResponse
    {
        /** @var string|null $type */
        $type = $request->input('type');
        Gate::authorize('create', [Contact::class, $type]);

        $contact = $this->contactService->create($request->toDto());

        return ApiResponse::created(
            data: new ContactResource($contact),
            message: 'Contact created successfully.',
        );
    }

    public function update(UpdateContactRequest $request, string $id): JsonResponse
    {
        $contact = $this->contactService->findById($id);
        if ($contact === null) {
            throw ApiException::notFound("Contact with ID '{$id}' not found.", 'CONTACT_NOT_FOUND');
        }

        Gate::authorize('update', $contact);

        $updated = $this->contactService->update($contact, $request->toDto());

        return ApiResponse::success(
            data: new ContactResource($updated),
            message: 'Contact updated successfully.',
        );
    }

    public function destroy(string $id): JsonResponse
    {
        $contact = $this->contactService->findById($id);
        if ($contact === null) {
            throw ApiException::notFound("Contact with ID '{$id}' not found.", 'CONTACT_NOT_FOUND');
        }

        Gate::authorize('delete', $contact);

        $this->contactService->delete($contact);

        return ApiResponse::success(
            data: null,
            message: 'Contact deleted successfully.',
        );
    }
}
