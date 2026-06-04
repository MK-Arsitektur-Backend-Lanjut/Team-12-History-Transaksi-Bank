<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Transaction extends Model
{
    use HasFactory;

    protected $table = 'transactions';

    protected $fillable = [
        'account_id',
        'reference_number',
        'type',
        'amount',
        'balance_before',
        'balance_after',
        'description',
        'transaction_date',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'balance_before' => 'decimal:2',
        'balance_after' => 'decimal:2',
        'transaction_date' => 'datetime',
        'balance_before' => 'decimal:2',
        'transaction_date' => 'datetime',
        'transacted_at' => 'datetime',
    ];

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /**
     * Unified accessor for transaction date used by Statement module.
     * Falls back to `transaction_date`, then `transacted_at`, then `created_at`.
     */
    public function getTransactionDateAttribute()
    {
        if (!empty($this->attributes['transaction_date'])) {
            return \Illuminate\Support\Carbon::parse($this->attributes['transaction_date']);
        }

        if (!empty($this->attributes['transacted_at'])) {
            return \Illuminate\Support\Carbon::parse($this->attributes['transacted_at']);
        }

        return $this->created_at;
    }
}
