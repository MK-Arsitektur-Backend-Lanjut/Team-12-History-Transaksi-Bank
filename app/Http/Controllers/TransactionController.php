<?php

namespace App\Http\Controllers;

use App\Models\Transaction;
use Illuminate\Http\Request;
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

        return DB::transaction(function () use ($validated) {
            // Ambil saldo terakhir
            $last = Transaction::where('account_id', $validated['account_id'])
                ->orderByDesc('id')->first();
            $lastBalance = $last ? $last->balance_after : 0;

            // Validasi saldo cukup jika debit
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

            return response()->json([
                'success' => true,
                'transaction' => $transaction
            ]);
        });
    }
}
