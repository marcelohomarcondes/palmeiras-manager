<?php
declare(strict_types=1);

function app_club(): string {
  return 'PALMEIRAS';
}
# REMOCAÇÃO TEMPORÁRIA PARA VALIDAR QUAL DB ESTÁ EM USO
#function db_path(): string {
#  return __DIR__ . '/../data/app.sqlite';
#}

function db_path(): string {
  $p = __DIR__ . '/../data/app.sqlite';
  error_log('DB PATH => ' . $p . ' | real => ' . (realpath($p) ?: 'REALPATH_FAIL'));
  return $p;
}

function db(): PDO {
  static $pdo = null;
  if ($pdo instanceof PDO) return $pdo;

  $pdo = new PDO('sqlite:' . db_path());
  $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
  $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

  $pdo->exec('PRAGMA foreign_keys = ON;');
  $pdo->exec('PRAGMA journal_mode = WAL;');

  return $pdo;
}

function redirect(string $to): never {
  header('Location: ' . $to);
  exit;
}

function h(?string $s): string {
  return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function q(PDO $pdo, string $sql, array $params = []): PDOStatement {
  $st = $pdo->prepare($sql);
  $st->execute($params);
  return $st;
}

function scalar(PDO $pdo, string $sql, array $params = []) {
  $st = q($pdo, $sql, $params);
  $row = $st->fetch(PDO::FETCH_NUM);
  return $row ? $row[0] : null;
}
