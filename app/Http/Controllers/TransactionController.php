<?php

namespace App\Http\Controllers;

use App\Models\Transaction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Http\JsonResponse;

class TransactionController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'account_id' => 'required|integer',
            'type' => 'required|in:debit,kredit',
            'amount' => 'required|numeric|min:1',
        ]);

        $cacheKey = $this->getBalanceCacheKey($validated['account_id']);
        $lockKey = "{$cacheKey}:lock";

        return Cache::store('redis')->lock($lockKey, 10)->block(5, function () use ($validated, $cacheKey) {
            return DB::transaction(function () use ($validated, $cacheKey) {
                $lastBalance = $this->getCachedLastBalance($validated['account_id'], $cacheKey);

                if ($validated['type'] === 'debit' && $lastBalance < $validated['amount']) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Saldo tidak cukup.'
                    ], 422);
                }

                $newBalance = $validated['type'] === 'debit'
                    ? $lastBalance - $validated['amount']
                    : $lastBalance + $validated['amount'];

                $transaction = Transaction::create([
                    'account_id' => $validated['account_id'],
                    'reference_number' => strtoupper(Str::uuid()),
                    'type' => $validated['type'],
                    'amount' => $validated['amount'],
                    'balance_after' => $newBalance,
                ]);

                Cache::store('redis')->put($cacheKey, $newBalance, now()->addMinutes(10));

                return response()->json([
                    'success' => true,
                    'transaction' => $transaction
                ]);
            });
        });
    }

    private function getCachedLastBalance(int $accountId, string $cacheKey): float
    {
        return Cache::store('redis')->remember($cacheKey, now()->addMinutes(10), function () use ($accountId) {
            $last = Transaction::where('account_id', $accountId)
                ->orderByDesc('id')
                ->first();

            return $last ? $last->balance_after : 0;
        });
    }

    private function getBalanceCacheKey(int $accountId): string
    {
        return "account_balance:{$accountId}";
    }
}
