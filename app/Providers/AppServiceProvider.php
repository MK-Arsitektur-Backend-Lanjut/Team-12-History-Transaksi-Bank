<?php

namespace App\Providers;

use App\Repositories\Account\AccountRepositoryInterface;
use App\Repositories\Account\EloquentAccountRepository;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(AccountRepositoryInterface::class, EloquentAccountRepository::class);
    }

    public function boot(): void
    {
        //
    }
}
