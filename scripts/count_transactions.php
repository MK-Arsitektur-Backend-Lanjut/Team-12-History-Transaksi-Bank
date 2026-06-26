<?php
require __DIR__ . '/../vendor/autoload.php';
$db = new PDO('sqlite:' . __DIR__ . '/../database/database.sqlite');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$accountId = $argv[1] ?? 1;
$stmt = $db->prepare('SELECT COUNT(*) AS c FROM transactions WHERE account_id = ?');
$stmt->execute([$accountId]);
echo $stmt->fetchColumn() . PHP_EOL;
