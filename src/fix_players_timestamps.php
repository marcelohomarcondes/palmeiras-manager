<?php
declare(strict_types=1);

$pdo = new PDO('sqlite:' . __DIR__ . '/../data/app.sqlite');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

echo "== Corrigindo tabela players ==\n";

try {
    $pdo->exec("ALTER TABLE players ADD COLUMN updated_at TEXT");
    echo "OK: coluna updated_at criada\n";
} catch (Throwable $e) {
    echo "updated_at já existe ou erro ignorado\n";
}

try {
    $pdo->exec("ALTER TABLE players ADD COLUMN created_at TEXT");
    echo "OK: coluna created_at criada\n";
} catch (Throwable $e) {
    echo "created_at já existe ou erro ignorado\n";
}

$pdo->exec("UPDATE players SET created_at = COALESCE(created_at, datetime('now'))");
echo "OK: created_at atualizado\n";

echo "Finalizado.\n";
