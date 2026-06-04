<?php

namespace Database\Factories;

use App\Models\Account;
use Illuminate\Database\Eloquent\Factories\Factory;

class AccountFactory extends Factory
{
    protected $model = Account::class;

    public function definition(): array
    {
        return [
            'account_number' => 'ACC' . $this->faker->unique()->numberBetween(1000000000, 9999999999),
            'customer_name' => $this->faker->name(),
            'email' => $this->faker->unique()->safeEmail(),
            'phone' => substr($this->faker->phoneNumber(), 0, 30),
            'address' => $this->faker->address(),
            'status' => 'active',
            'balance' => 0,
        ];
    }
}
