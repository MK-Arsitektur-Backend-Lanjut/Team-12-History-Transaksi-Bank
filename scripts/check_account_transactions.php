<?php
require __DIR__ . '/../vendor/autoload.php';

$db = new PDO('sqlite:' . __DIR__ . '/../database/database.sqlite');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$accountId = $argv[1] ?? 2;

// Fetch account
$stmt = $db->prepare('SELECT id, account_number, customer_name, balance FROM accounts WHERE id = ?');
$stmt->execute([$accountId]);
$account = $stmt->fetch(PDO::FETCH_ASSOC);

// Fetch last 20 transactions for the account
$stmt = $db->prepare('SELECT id, account_id, reference_number, type, amount, balance_before, balance_after, transacted_at, created_at FROM transactions WHERE account_id = ? ORDER BY id DESC LIMIT 20');
$stmt->execute([$accountId]);
$transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo json_encode([
    'account' => $account ?: null,
    'transactions' => $transactions,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
