<?php


use App\Http\Controllers\Api\AccountController;
use Illuminate\Support\Facades\Route;

Route::prefix('accounts')->group(function () {
    Route::get('/', [AccountController::class, 'index']);
    Route::post('/', [AccountController::class, 'store']);
    Route::get('/{account}', [AccountController::class, 'show']);
    Route::patch('/{account}', [AccountController::class, 'update']);
    Route::patch('/{account}/status', [AccountController::class, 'updateStatus']);
    Route::post('/{account}/balance/adjust', [AccountController::class, 'adjustBalance']);
});
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\StatementController;

Route::get('/statements', [StatementController::class, 'index']);
Route::get('/statements/export', [StatementController::class, 'export']);
