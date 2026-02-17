<?php
declare(strict_types=1);
require_once __DIR__ . '/db.php';

$pdo = db();
$pdo->exec("ALTER TABLE transfers ADD COLUMN player_id INTEGER");
echo "OK\n";