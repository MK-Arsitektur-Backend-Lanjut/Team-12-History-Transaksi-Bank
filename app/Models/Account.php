<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Account extends Model
{
    use HasFactory;

    protected $fillable = [
        'account_number',
        'customer_name',
        'email',
        'phone',
        'address',
        'status',
        'balance',
    ];

    protected $casts = [
        'balance' => 'decimal:2',
    ];

    protected static function booted(): void
    {
        static::saved(function ($account) {
            \Illuminate\Support\Facades\Cache::forget("account:id:{$account->id}");
            \Illuminate\Support\Facades\Cache::forget("account:number:{$account->account_number}");
        });

        static::deleted(function ($account) {
            \Illuminate\Support\Facades\Cache::forget("account:id:{$account->id}");
            \Illuminate\Support\Facades\Cache::forget("account:number:{$account->account_number}");
        });
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }
}