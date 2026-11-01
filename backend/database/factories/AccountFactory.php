<?php

declare(strict_types=1);

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\Accounting\Enums\AccountType;
use Modules\Accounting\Enums\SystemAccount;
use Modules\Accounting\Models\Account;

/** @extends Factory<Account> */
final class AccountFactory extends Factory
{
    protected $model = Account::class;

    public function definition(): array
    {
        $type = $this->faker->randomElement(AccountType::cases());
        $isSystem = $this->faker->boolean(10); // 10% system accounts
        $systemName = $isSystem ? $this->faker->unique()->randomElement(SystemAccount::cases())->name : null;

        return [
            'id' => Str::ulid(),
            'code' => $this->faker->unique()->bothify('###???'),
            'name' => $this->faker->words(3, true),
            'type' => $type,
            'parent_id' => null,
            'is_system' => $isSystem,
            'system_name' => $systemName,
            'is_active' => true,
            'currency' => 'USD',
            'description' => $this->faker->sentence(),
        ];
    }

    /**
     * State for inactive accounts.
     */
    public function inactive(): self
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }
}
