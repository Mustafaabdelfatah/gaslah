<?php

namespace Database\Factories;

use App\Enum\Accounting\AccountTypeEnum;
use App\Models\Account;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

class AccountFactory extends Factory
{
    protected $model = Account::class;

    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'code' => (string) $this->faker->unique()->numberBetween(6000, 9999),
            'name' => $this->faker->words(2, true),
            'type' => AccountTypeEnum::Expense->value,
            'is_system' => false,
            'is_active' => true,
        ];
    }

    public function type(AccountTypeEnum $type): static
    {
        return $this->state(fn () => ['type' => $type->value]);
    }
}
