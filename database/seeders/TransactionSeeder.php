<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Transaction;
use App\Models\Account;
use Illuminate\Support\Facades\DB;

class TransactionSeeder extends Seeder
{
    public function run(): void
    {
        // Hapus data lama
        Transaction::truncate();

        $totalPerAccount = 50000;
        $accounts = range(1, 10);
        $batchSize = 1000;

        foreach ($accounts as $accountId) {
            $this->command->info("Seeding account placeholder: $accountId...");

            // ensure an account record exists for this placeholder bucket
            $account = Account::firstOrCreate(
                ['account_number' => 'SEED-ACC-' . $accountId],
                [
                    'customer_name' => "Seed User {$accountId}",
                    'email' => "seed+{$accountId}@example.test",
                    'status' => 'active',
                    'balance' => 10000000,
                ]
            );

            $targetAccountId = $account->id;
            
            for ($batch = 0; $batch < $totalPerAccount / $batchSize; $batch++) {
                $transactions = [];
                $baseDate = now()->subYears(2);
                
                for ($i = 0; $i < $batchSize; $i++) {
                    $type = (rand(0, 1) === 1) ? 'debit' : 'credit';
                    $amount = rand(1000, 1000000);
                    $idx = $batch * $batchSize + $i;
                    $balanceAfter = ($type === 'debit') 
                        ? 10000000 - $idx * 5000 
                        : 10000000 + $idx * 5000;
                    
                    // derive a plausible balance_before based on type
                    $createdAt = (clone $baseDate)->addSeconds(rand(0, 63072000));
                    $balanceBefore = $type === 'debit' ? ($balanceAfter + $amount) : ($balanceAfter - $amount);

                    $transactions[] = [
                        'account_id' => $targetAccountId,
                        'reference_number' => 'TXN' . strtoupper(bin2hex(random_bytes(6))) . $idx,
                        'type' => $type,
                        'amount' => $amount,
                        'balance_before' => $balanceBefore,
                        'balance_after' => $balanceAfter,
                        'transacted_at' => $createdAt,
                        'created_at' => $createdAt,
                        'updated_at' => now(),
                    ];
                }
                
                DB::table('transactions')->insert($transactions);
            }
            $this->command->info("  Account $accountId done!");
        }
        
        $this->command->info("Seeding completed! Total: " . Transaction::count());
    }
}