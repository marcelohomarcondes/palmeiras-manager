<?php
declare(strict_types=1);

$pdo = new PDO('sqlite:' . __DIR__ . '/../data/app.sqlite');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$cols = [];
$stmt = $pdo->query("PRAGMA table_info(transfers)");
while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) $cols[$r['name']] = true;

if (!isset($cols['value_eur_cents'])) {
  $pdo->exec("ALTER TABLE transfers ADD COLUMN value_eur_cents INTEGER NOT NULL DEFAULT 0");
  echo "OK: adicionada coluna transfers.value_eur_cents\n";
} else {
  echo "OK: coluna value_eur_cents já existe\n";
}
