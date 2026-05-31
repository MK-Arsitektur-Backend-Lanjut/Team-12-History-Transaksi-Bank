<?php
$db = new SQLite3(__DIR__ . '/../database/database.sqlite');
$count = $db->querySingle('SELECT COUNT(*) FROM transactions');
echo $count . PHP_EOL;
