<?php

namespace Database\Seeders;

use App\Models\Account;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class TransactionLoadSeeder extends Seeder
{
    public function run(): void
    {
        $accountsCount = (int) env('SEED_ACCOUNTS_COUNT', 1);
        $transactionsPerAccount = max(50000, (int) env('SEED_TRANSACTIONS_PER_ACCOUNT', 50000));
        $chunkSize = 1000;

        $accounts = $this->ensureAccounts($accountsCount);

        foreach ($accounts as $account) {
            DB::table('transactions')->where('account_id', $account->id)->delete();

            $currentBalance = 1000000.00;
            $rows = [];

            for ($sequence = 1; $sequence <= $transactionsPerAccount; $sequence++) {
                $amount = random_int(1000, 1000000) / 100;
                $type = random_int(0, 1) === 0 ? 'debit' : 'credit';

                if ($type === 'debit' && $currentBalance < $amount) {
                    $type = 'credit';
                }

                $balanceBefore = $currentBalance;
                $currentBalance = $type === 'credit'
                    ? $currentBalance + $amount
                    : $currentBalance - $amount;

                $rows[] = [
                    'account_id' => $account->id,
                    'reference_number' => sprintf('TXN-%d-%06d', $account->id, $sequence),
                    'type' => $type,
                    'amount' => round($amount, 2),
                    'balance_before' => round($balanceBefore, 2),
                    'balance_after' => round($currentBalance, 2),
                    'description' => Str::title($type) . ' transaction seed #' . $sequence,
                    'transaction_date' => now()->subSeconds($transactionsPerAccount - $sequence),
                    'created_at' => now(),
                    'updated_at' => now(),
                ];

                if (count($rows) === $chunkSize) {
                    DB::table('transactions')->insert($rows);
                    $rows = [];
                }
            }

            if ($rows !== []) {
                DB::table('transactions')->insert($rows);
            }

            $account->update(['balance' => round($currentBalance, 2)]);
        }
    }

    private function ensureAccounts(int $accountsCount)
    {
        $accounts = Account::query()->orderBy('id')->limit($accountsCount)->get();

        if ($accounts->count() >= $accountsCount) {
            return $accounts;
        }

        for ($index = $accounts->count() + 1; $index <= $accountsCount; $index++) {
            Account::query()->create([
                'account_number' => sprintf('ACCSEED%06d', $index),
                'customer_name' => 'Seeded Account ' . $index,
                'email' => 'seeded-account-' . $index . '@example.com',
                'phone' => '081200000' . str_pad((string) $index, 3, '0', STR_PAD_LEFT),
                'address' => 'Seed Address ' . $index,
                'status' => 'active',
                'balance' => 1000000,
            ]);
        }

        return Account::query()->orderBy('id')->limit($accountsCount)->get();
    }
}
