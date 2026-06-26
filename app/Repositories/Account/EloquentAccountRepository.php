<?php

namespace App\Repositories\Account;

use App\Models\Account;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class EloquentAccountRepository implements AccountRepositoryInterface
{
    private const CACHE_TTL_SECONDS = 3600;

    public function paginate(int $perPage = 15): LengthAwarePaginator
    {
        return Account::query()->latest()->paginate($perPage);
    }

    public function findById(int $id): ?Account
    {
        return $this->rememberAccount("account:id:{$id}", function () use ($id) {
            return Account::query()->find($id);
        });
    }

    public function findByAccountNumber(string $accountNumber): ?Account
    {
        return $this->rememberAccount("account:number:{$accountNumber}", function () use ($accountNumber) {
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

        try {
            Cache::store('redis')->forget("account:profile:{$account->id}");
        } catch (\Throwable $e) {}
        return $account->refresh();
    }

    public function updateStatus(Account $account, string $status): Account
    {
        $account->status = $status;
        $account->save();
        try {
            Cache::store('redis')->forget("account:profile:{$account->id}");
        } catch (\Throwable $e) {}

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

            try {
                Cache::store('redis')->forget("account:profile:{$accountId}");
            } catch (\Throwable $e) {}

            return $account->refresh();
        });
    }

    private function rememberAccount(string $key, callable $resolver): ?Account
    {
        $cached = Cache::get($key);

        if ($cached instanceof Account) {
            return $cached;
        }

        if (is_array($cached)) {
            return (new Account)->newFromBuilder($cached);
        }

        if ($cached !== null) {
            Cache::forget($key);
        }

        $account = $resolver();

        if ($account !== null) {
            Cache::put($key, $account->getAttributes(), self::CACHE_TTL_SECONDS);
        }

        return $account;
    }
}
