<?php
// filepath: d:\Kuliah\Semester 8\Arsitektur & Pengembangan Backend\modul-account-management\app\Repositories\Account\AccountRepositoryInterface.php

namespace App\Repositories\Account;

use App\Models\Account;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface AccountRepositoryInterface
{
    public function paginate(int $perPage = 15): LengthAwarePaginator;
    public function findById(int $id): ?Account;
    public function findByAccountNumber(string $accountNumber): ?Account;
    public function create(array $data): Account;
    public function update(Account $account, array $data): Account;
    public function updateStatus(Account $account, string $status): Account;
    public function adjustBalanceAtomically(int $accountId, string $type, float $amount): Account;
}