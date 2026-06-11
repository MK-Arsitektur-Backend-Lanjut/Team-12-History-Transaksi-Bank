<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DailyBalancesSummary extends Model
{
    use HasFactory;

    protected $table = 'daily_balances_summary';

    protected $fillable = [
        'account_id',
        'summary_date',
        'total_credit',
        'total_debit',
        'closing_balance',
    ];

    protected $casts = [
        'summary_date' => 'date',
        'total_credit' => 'decimal:2',
        'total_debit' => 'decimal:2',
        'closing_balance' => 'decimal:2',
    ];

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }
}
