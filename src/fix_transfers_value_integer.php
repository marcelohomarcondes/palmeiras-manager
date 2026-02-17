<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

echo "== Iniciando correção transfers.value -> INTEGER (centavos EUR)\n";

$cols = $pdo->query("PRAGMA table_info(transfers)")->fetchAll(PDO::FETCH_ASSOC);
if (!$cols) {
  throw new RuntimeException("Tabela transfers não encontrada.");
}

$names = array_map(fn($c) => $c['name'], $cols);
if (!in_array('value', $names, true)) {
  throw new RuntimeException("Coluna 'value' não existe em transfers.");
}

// 1) Criar transfers_new com mesmo layout, mas value INTEGER NOT NULL
// OBS: Mantém a ordem e tipos dos outros campos; só força value -> INTEGER
$createCols = [];
foreach ($cols as $c) {
  $name = $c['name'];
  $type = (string)$c['type'];
  $notnull = ((int)$c['notnull'] === 1) ? ' NOT NULL' : '';
  $dflt = ($c['dflt_value'] !== null) ? ' DEFAULT ' . $c['dflt_value'] : '';
  $pk = ((int)$c['pk'] === 1) ? ' PRIMARY KEY' : '';

  if ($name === 'value') {
    $type = 'INTEGER';
    $notnull = ' NOT NULL';
    $dflt = '';
  }

  $createCols[] = '"' . $name . '" ' . ($type ?: 'TEXT') . $notnull . $dflt . $pk;
}

$pdo->exec("BEGIN");
try {
  $pdo->exec("DROP TABLE IF EXISTS transfers_new");
  $pdo->exec("CREATE TABLE transfers_new (" . implode(", ", $createCols) . ")");

  // 2) Copiar dados
  // Se value já estiver em centavos numéricos => copia direto.
  // Se value estiver em texto com separadores (ex: 1.234.567,89) => converte para centavos.
  // Heurística:
  // - remove '.' (milhar)
  // - troca ',' por '.'
  // - multiplica por 100
  //
  // Se já for número inteiro grande (centavos), a conversão ainda funcionaria,
  // mas para evitar dobrar, usamos um CASE:
  // - se contém ',' ou '.' => trata como decimal formatado
  // - senão => cast direto para integer

  $colList = implode(", ", array_map(fn($n) => '"' . $n . '"', $names));

  $selectList = [];
  foreach ($names as $n) {
    if ($n === 'value') {
      $selectList[] =
        "CASE
          WHEN CAST(value AS TEXT) LIKE '%.%' OR CAST(value AS TEXT) LIKE '%,%'
            THEN CAST(ROUND(CAST(REPLACE(REPLACE(CAST(value AS TEXT), '.', ''), ',', '.') AS REAL) * 100.0) AS INTEGER)
          ELSE CAST(value AS INTEGER)
        END AS value";
    } else {
      $selectList[] = '"' . $n . '"';
    }
  }
  $selectExpr = implode(", ", $selectList);

  $pdo->exec("INSERT INTO transfers_new ($colList) SELECT $selectExpr FROM transfers");

  // 3) Substituir tabela
  $pdo->exec("DROP TABLE transfers");
  $pdo->exec("ALTER TABLE transfers_new RENAME TO transfers");

  $pdo->exec("COMMIT");
  echo "OK: transfers.value agora é INTEGER (centavos).\n";
} catch (Throwable $e) {
  $pdo->exec("ROLLBACK");
  throw $e;
}
