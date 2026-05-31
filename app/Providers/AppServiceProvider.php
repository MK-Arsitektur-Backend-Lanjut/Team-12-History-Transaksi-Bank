<?php

namespace App\Providers;

use App\Repositories\Account\AccountRepositoryInterface;
use App\Repositories\Account\EloquentAccountRepository;
use Illuminate\Support\ServiceProvider;
use App\Repositories\StatementRepositoryInterface;
use App\Repositories\EloquentStatementRepository;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(AccountRepositoryInterface::class, EloquentAccountRepository::class);
        $this->app->bind(StatementRepositoryInterface::class, EloquentStatementRepository::class);
    }

    public function boot(): void
    {
        //
    }
}
