<?php

declare(strict_types=1);

namespace Modules\Inventory\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Inventory\DTOs\UpdateCategoryData;

final class UpdateCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('products.manage') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var string|null $categoryId */
        $categoryId = $this->route('category') ?? $this->route('id');

        return [
            'name' => ['sometimes', 'required', 'string', 'max:100'],
            'slug' => [
                'sometimes',
                'required',
                'string',
                'max:100',
                Rule::unique('tenant.categories', 'slug')->ignore($categoryId),
            ],
            'description' => ['nullable', 'string'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }

    public function toDto(): UpdateCategoryData
    {
        $validated = $this->validated();

        return new UpdateCategoryData(
            name: isset($validated['name']) ? (string) $validated['name'] : null,
            slug: isset($validated['slug']) ? (string) $validated['slug'] : null,
            description: array_key_exists('description', $validated) ? ($validated['description'] !== null ? (string) $validated['description'] : null) : null,
            isActive: isset($validated['is_active']) ? (bool) $validated['is_active'] : null,
        );
    }
}
