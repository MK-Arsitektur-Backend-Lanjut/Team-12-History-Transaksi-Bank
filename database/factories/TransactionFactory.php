<?php

namespace Database\Factories;

use App\Models\Transaction;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class TransactionFactory extends Factory
{
    protected $model = Transaction::class;

    public function definition(): array
    {
        $type = $this->faker->randomElement(['debit', 'kredit']);
        $amount = $this->faker->numberBetween(1000, 1000000);
        return [
            'account_id' => $this->faker->numberBetween(1, 10), // ganti sesuai jumlah rekening
            'reference_number' => strtoupper(Str::random(16)),
            'type' => $type,
            'amount' => $amount,
            'balance_after' => $type === 'debit' ? 10000000 - $amount : 10000000 + $amount,
            'created_at' => $this->faker->dateTimeBetween('-2 years', 'now'),
            'updated_at' => now(),
        ];
    }
}
