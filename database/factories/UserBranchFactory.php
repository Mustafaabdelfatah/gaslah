<?php

namespace Database\Factories;

use App\Enum\Tenancy\StaffRoleEnum;
use App\Models\Branch;
use App\Models\User;
use App\Models\UserBranch;
use Illuminate\Database\Eloquent\Factories\Factory;

class UserBranchFactory extends Factory
{
    protected $model = UserBranch::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'branch_id' => Branch::factory(),
            'role' => StaffRoleEnum::Cashier->value,
        ];
    }

    public function role(StaffRoleEnum $role): static
    {
        return $this->state(fn () => ['role' => $role->value]);
    }
}
