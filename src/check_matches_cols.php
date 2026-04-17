<?php
declare(strict_types=1);

$pdo = new PDO('sqlite:' . __DIR__ . '/../data/app.sqlite');
foreach ($pdo->query('PRAGMA table_info(matches)') as $r) {
    echo $r['name'], PHP_EOL;
}
