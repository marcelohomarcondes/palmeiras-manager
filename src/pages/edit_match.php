<?php
declare(strict_types=1);

require_once __DIR__ . '/../db.php';

$pdo    = db();
$userId = require_user_id();

$matchId = (int)($_GET['id'] ?? 0);

if ($matchId <= 0) {
  redirect('/?page=matches&err=not_found');
}

/**
 * Helper local:
 * verifica se a tabela possui a coluna user_id
 * sem depender de funções declaradas em create_match.php
 */
function edit_match_has_user_id(PDO $pdo, string $table): bool
{
  try {
    $rows = $pdo->query("PRAGMA table_info($table)")->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($rows as $row) {
      if (($row['name'] ?? '') === 'user_id') {
        return true;
      }
    }
  } catch (Throwable $e) {
    return false;
  }

  return false;
}

/**
 * Garante que a partida existe e, se aplicável,
 * pertence ao usuário logado.
 */
$sql = "SELECT id FROM matches WHERE id = :id";
$params = [
  ':id' => $matchId,
];

if (edit_match_has_user_id($pdo, 'matches')) {
  $sql .= " AND user_id = :user_id";
  $params[':user_id'] = $userId;
}

$sql .= " LIMIT 1";

$exists = q($pdo, $sql, $params)->fetchColumn();

if (!$exists) {
  redirect('/?page=matches&err=not_found');
}

/**
 * Força o contexto correto de edição.
 * O create_match.php usa essas infos para:
 * - carregar os dados da partida
 * - manter redirects para edit_match
 */
$_GET['page'] = 'edit_match';
$_GET['id']   = (string)$matchId;

/**
 * Reaproveita toda a lógica do formulário e do salvamento.
 */
require __DIR__ . '/create_match.php';