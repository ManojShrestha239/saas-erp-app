<?php

declare(strict_types=1);

namespace Modules\Inventory\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Modules\Inventory\DTOs\CreateCategoryData;

final class StoreCategoryRequest extends FormRequest
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
        return [
            'name' => ['required', 'string', 'max:100'],
            'slug' => ['nullable', 'string', 'max:100', Rule::unique('tenant.categories', 'slug')],
            'description' => ['nullable', 'string'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }

    public function toDto(): CreateCategoryData
    {
        $validated = $this->validated();
        /** @var string $name */
        $name = $validated['name'];
        $slug = ! empty($validated['slug']) ? (string) $validated['slug'] : Str::slug($name);

        return new CreateCategoryData(
            name: $name,
            slug: $slug,
            description: isset($validated['description']) ? (string) $validated['description'] : null,
            isActive: isset($validated['is_active']) ? (bool) $validated['is_active'] : true,
        );
    }
}
