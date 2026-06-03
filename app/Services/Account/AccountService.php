<?php

namespace App\Services\Account;

use App\Models\Account;
use App\Repositories\Account\AccountRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class AccountService
{
    public function __construct(
        private readonly AccountRepositoryInterface $accounts
    ) {
    }

    public function list(int $perPage = 15): LengthAwarePaginator
    {
        return $this->accounts->paginate($perPage);
    }

    public function find(int $id): ?Account
    {
        return $this->accounts->findById($id);
    }

    public function create(array $data): Account
    {
        $data['account_number'] = $this->generateAccountNumber();
        $data['status'] = $data['status'] ?? 'active';
        $data['balance'] = $data['balance'] ?? 0;

        return $this->accounts->create($data);
    }

    public function updateProfile(Account $account, array $data): Account
    {
        return $this->accounts->update($account, $data);
    }

    public function updateStatus(Account $account, string $status): Account
    {
        return $this->accounts->updateStatus($account, $status);
    }

    public function adjustBalance(Account $account, string $type, float $amount): Account
    {
        return $this->accounts->adjustBalanceAtomically($account->id, $type, $amount);
    }

    private function generateAccountNumber(): string
    {
        do {
            $candidate = 'ACC' . now()->format('YmdHis') . random_int(1000, 9999);
        } while ($this->accounts->findByAccountNumber($candidate));

        return $candidate;
    }
}