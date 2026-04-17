<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

$pdo = db();

// Adiciona colunas se não existirem (SQLite não tem IF NOT EXISTS p/ coluna; vamos checar via PRAGMA)
$cols = [];
$stmt = $pdo->query("PRAGMA table_info(matches)");
while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
  $cols[$r['name']] = true;
}

$add = function(string $name, string $type) use ($pdo, $cols) {
  if (isset($cols[$name])) return;
  $pdo->exec("ALTER TABLE matches ADD COLUMN $name $type");
  echo "OK: coluna adicionada: $name\n";
};

$add('phase', 'TEXT');
$add('round', 'TEXT');
$add('match_time', 'TEXT');
$add('stadium', 'TEXT');
$add('referee', 'TEXT');
$add('kit_used', 'TEXT');
$add('weather', 'TEXT');

echo "OK: migrate_v2 finalizado.\n";
