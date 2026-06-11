<?php
require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Account;

// Hindari duplikat
$exists = Account::where('account_number', 'TEST-1')->first();
if (!$exists) {
    $acc = Account::create([
        'account_number' => 'TEST-1',
        'customer_name' => 'Test User',
        'email' => 'test1@example.com',
        'phone' => '08123456789',
        'address' => 'Test address',
        'status' => 'active',
        'balance' => 0,
    ]);
    echo json_encode($acc->toArray());
} else {
    echo json_encode($exists->toArray());
}
