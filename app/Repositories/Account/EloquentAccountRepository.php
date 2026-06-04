<?php
// filepath: d:\Kuliah\Semester 8\Arsitektur & Pengembangan Backend\modul-account-management\app\Repositories\Account\EloquentAccountRepository.php

namespace App\Repositories\Account;

use App\Models\Account;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class EloquentAccountRepository implements AccountRepositoryInterface
{
    public function paginate(int $perPage = 15): LengthAwarePaginator
    {
        return Account::query()->latest()->paginate($perPage);
    }

    public function findById(int $id): ?Account
    {
        return \Illuminate\Support\Facades\Cache::remember("account:id:{$id}", 3600, function () use ($id) {
            return Account::query()->find($id);
        });
    }

    public function findByAccountNumber(string $accountNumber): ?Account
    {
        return \Illuminate\Support\Facades\Cache::remember("account:number:{$accountNumber}", 3600, function () use ($accountNumber) {
            return Account::query()->where('account_number', $accountNumber)->first();
        });
    }

    public function create(array $data): Account
    {
        return Account::query()->create($data);
    }

    public function update(Account $account, array $data): Account
    {
        $account->update($data);
        return $account->refresh();
    }

    public function updateStatus(Account $account, string $status): Account
    {
        $account->status = $status;
        $account->save();

        return $account->refresh();
    }

    public function adjustBalanceAtomically(int $accountId, string $type, float $amount): Account
    {
        return DB::transaction(function () use ($accountId, $type, $amount) {
            /** @var Account $account */
            $account = Account::query()
                ->whereKey($accountId)
                ->lockForUpdate()
                ->firstOrFail();

            if ($account->status !== 'active') {
                throw ValidationException::withMessages([
                    'status' => 'Account is not active.',
                ]);
            }

            $currentBalance = (float) $account->balance;

            if ($type === 'debit' && $currentBalance < $amount) {
                throw ValidationException::withMessages([
                    'amount' => 'Insufficient balance.',
                ]);
            }

            $newBalance = $type === 'credit'
                ? $currentBalance + $amount
                : $currentBalance - $amount;

            $account->balance = round($newBalance, 2);
            $account->save();

            return $account->refresh();
        });
    }
}