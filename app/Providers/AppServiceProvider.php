<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Repositories\StatementRepositoryInterface;
use App\Repositories\EloquentStatementRepository;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(StatementRepositoryInterface::class, EloquentStatementRepository::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
