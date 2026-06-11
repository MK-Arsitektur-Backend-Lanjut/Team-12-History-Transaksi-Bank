<?php
require __DIR__ . '/../vendor/autoload.php';
$db = new PDO('sqlite:' . __DIR__ . '/../database/database.sqlite');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$amount = $argv[1] ?? '50.5';
$stmt = $db->prepare('SELECT id, account_id, amount, type, transacted_at, created_at FROM transactions WHERE amount = ? ORDER BY id DESC');
$stmt->execute([$amount]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
echo json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
