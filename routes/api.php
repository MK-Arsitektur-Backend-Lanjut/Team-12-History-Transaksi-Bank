<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\StatementController;

Route::get('/statements', [StatementController::class, 'index']);
Route::get('/statements/export', [StatementController::class, 'export']);
