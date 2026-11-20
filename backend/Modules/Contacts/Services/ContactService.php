<?php

declare(strict_types=1);

namespace Modules\Contacts\Services;

use Illuminate\Database\DatabaseManager;
use Illuminate\Pagination\LengthAwarePaginator;
use Modules\Contacts\DTOs\CreateContactData;
use Modules\Contacts\DTOs\UpdateContactData;
use Modules\Contacts\Enums\ContactType;
use Modules\Contacts\Models\Contact;
use Shared\Exceptions\ApiException;

final class ContactService
{
    public function __construct(
        private readonly DatabaseManager $db,
    ) {}

    public function create(CreateContactData $data): Contact
    {
        return $this->db->connection('tenant')->transaction(function () use ($data): Contact {
            if ($data->creditLimitCents < 0) {
                throw ApiException::unprocessable('Credit limit cannot be negative.', 'INVALID_CREDIT_LIMIT');
            }

            $code = $data->code !== null && trim($data->code) !== ''
                ? trim($data->code)
                : $this->generateSequentialCode($data->type);

            $existing = Contact::where('code', $code)->first();
            if ($existing !== null) {
                throw ApiException::conflict("A contact with code '{$code}' already exists.", 'CODE_ALREADY_EXISTS');
            }

            $attributes = $data->toArray();
            $attributes['code'] = $code;

            return Contact::create($attributes);
        }, 3);
    }

    public function update(Contact $contact, UpdateContactData $data): Contact
    {
        return $this->db->connection('tenant')->transaction(function () use ($contact, $data): Contact {
            /** @var Contact $lockedContact */
            $lockedContact = Contact::where('id', $contact->id)->lockForUpdate()->firstOrFail();

            if ($data->creditLimitCents !== null && $data->creditLimitCents < 0) {
                throw ApiException::unprocessable('Credit limit cannot be negative.', 'INVALID_CREDIT_LIMIT');
            }

            if ($data->code !== null && $data->code !== $lockedContact->code) {
                $trimmedCode = trim($data->code);
                $existing = Contact::where('code', $trimmedCode)
                    ->where('id', '<>', $lockedContact->id)
                    ->first();

                if ($existing !== null) {
                    throw ApiException::conflict("A contact with code '{$trimmedCode}' already exists.", 'CODE_ALREADY_EXISTS');
                }
            }

            $lockedContact->update($data->toFilteredArray());

            return $lockedContact->fresh() ?? $lockedContact;
        }, 3);
    }

    public function delete(Contact $contact): void
    {
        $this->db->connection('tenant')->transaction(function () use ($contact): void {
            /** @var Contact $lockedContact */
            $lockedContact = Contact::where('id', $contact->id)->lockForUpdate()->firstOrFail();
            $lockedContact->delete();
        }, 3);
    }

    public function findById(string $id): ?Contact
    {
        return Contact::find($id);
    }

    /**
     * @return array{
     *     id: string,
     *     code: string,
     *     name: string,
     *     type: string,
     *     email: ?string,
     *     phone: ?string,
     *     tax_id: ?string,
     *     billing_address: ?array<string, mixed>,
     *     shipping_address: ?array<string, mixed>,
     *     payment_terms_days: int
     * }|null
     */
    public function getDetails(string $id): ?array
    {
        $contact = $this->findById($id);
        if ($contact === null) {
            return null;
        }

        return [
            'id' => (string) $contact->id,
            'code' => $contact->code,
            'name' => $contact->name,
            'type' => $contact->type->value,
            'email' => $contact->email,
            'phone' => $contact->phone,
            'tax_id' => $contact->tax_id,
            'billing_address' => $contact->billing_address,
            'shipping_address' => $contact->shipping_address,
            'payment_terms_days' => $contact->payment_terms_days,
        ];
    }

    public function findByCode(string $code): ?Contact
    {
        return Contact::where('code', $code)->first();
    }

    /**
     * @param  list<string>  $ids
     * @return array<string, array{id: string, code: string, name: string, type: string}>
     */
    public function getSummariesByIds(array $ids): array
    {
        if (empty($ids)) {
            return [];
        }

        $contacts = Contact::whereIn('id', $ids)->get();
        $result = [];
        foreach ($contacts as $contact) {
            $result[(string) $contact->id] = [
                'id' => (string) $contact->id,
                'code' => $contact->code,
                'name' => $contact->name,
                'type' => $contact->type->value,
            ];
        }

        return $result;
    }

    /**
     * @param  array{search?: ?string, type?: ?string, is_active?: ?bool}  $filters
     * @param  array{field: string, direction: 'asc'|'desc'}  $sort
     * @return LengthAwarePaginator<int, Contact>
     */
    public function paginate(array $filters, array $sort, int $perPage = 25): LengthAwarePaginator
    {
        $query = Contact::query();

        if (! empty($filters['search'])) {
            $term = '%'.$filters['search'].'%';
            $query->where(function ($q) use ($term): void {
                $q->where('name', 'like', $term)
                    ->orWhere('code', 'like', $term)
                    ->orWhere('tax_id', 'like', $term)
                    ->orWhere('email', 'like', $term)
                    ->orWhere('phone', 'like', $term);
            });
        }

        if (! empty($filters['type'])) {
            $type = $filters['type'];
            if ($type === ContactType::Customer->value) {
                $query->customers();
            } elseif ($type === ContactType::Supplier->value) {
                $query->suppliers();
            } else {
                $query->where('type', $type);
            }
        }

        if (isset($filters['is_active'])) {
            $query->where('is_active', $filters['is_active']);
        }

        $query->orderBy($sort['field'], $sort['direction']);

        return $query->paginate($perPage);
    }

    private function generateSequentialCode(ContactType $type): string
    {
        $prefix = match ($type) {
            ContactType::Customer => 'CUST',
            ContactType::Supplier => 'SUPP',
            ContactType::Both => 'CONT',
        };

        $count = Contact::where('code', 'like', "{$prefix}-%")->count() + 1;
        $code = sprintf('%s-%06d', $prefix, $count);

        while (Contact::where('code', $code)->exists()) {
            $count++;
            $code = sprintf('%s-%06d', $prefix, $count);
        }

        return $code;
    }
}
