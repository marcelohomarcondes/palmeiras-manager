<?php
// src/db.php
declare(strict_types=1);

/**
 * Conexão + helpers globais do projeto
 * - db(): PDO (singleton)
 * - $pdo global
 * - helpers: h(), q(), scalar(), redirect(), app_club()
 * - helpers de autenticação: current_user_id(), current_username(), require_user_id()
 * - carrega layout.php (render_header/render_footer)
 */

// -----------------------------------------------------------------------------
// Helpers (HTML)
// -----------------------------------------------------------------------------
if (!function_exists('h')) {
  function h(string|int|float|null|bool $s): string {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
  }
}

// -----------------------------------------------------------------------------
// DB (PDO singleton)
// -----------------------------------------------------------------------------
if (!function_exists('db')) {
  function db(): PDO {
    static $instance = null;
    if ($instance instanceof PDO) {
      return $instance;
    }

    $dbPath = __DIR__ . '/../data/app.sqlite';
    $dsn = 'sqlite:' . $dbPath;

    try {
      $pdo = new PDO($dsn, null, null, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
      ]);

      // PRAGMAs úteis no SQLite
      $pdo->exec("PRAGMA foreign_keys = ON;");
      $pdo->exec("PRAGMA journal_mode = WAL;");
      $pdo->exec("PRAGMA synchronous = NORMAL;");

      $instance = $pdo;

      // Exporta também como $pdo global
      $GLOBALS['pdo'] = $pdo;

      return $pdo;
    } catch (Throwable $e) {
      die(
        "Erro: conexão PDO não disponível. " .
        "Verifique src/db.php e data/app.sqlite. Detalhe: " . $e->getMessage()
      );
    }
  }
}

// garante $pdo global SEMPRE
$pdo = db();

// -----------------------------------------------------------------------------
// Integração com autenticação
// -----------------------------------------------------------------------------
$authFile = __DIR__ . '/auth/auth.php';
if (file_exists($authFile)) {
  require_once $authFile;
}

// -----------------------------------------------------------------------------
// Query helpers (q / scalar)
// -----------------------------------------------------------------------------
if (!function_exists('q')) {
  /**
   * Executa query preparada e retorna PDOStatement
   */
  function q(PDO $pdo, string $sql, array $params = []): PDOStatement {
    $st = $pdo->prepare($sql);
    $st->execute($params);
    return $st;
  }
}

if (!function_exists('scalar')) {
  /**
   * Retorna a 1ª coluna da 1ª linha (ou $default)
   */
  function scalar(PDO $pdo, string $sql, array $params = [], $default = null) {
    $st = q($pdo, $sql, $params);
    $v = $st->fetchColumn();
    return ($v === false) ? $default : $v;
  }
}

// -----------------------------------------------------------------------------
// Redirect helper
// -----------------------------------------------------------------------------
if (!function_exists('redirect')) {
  function redirect(string $url): void {
    header('Location: ' . $url);
    exit;
  }
}

// -----------------------------------------------------------------------------
// Clube atual
// -----------------------------------------------------------------------------
if (!function_exists('app_club')) {
  function app_club(): string {
    return 'PALMEIRAS';
  }
}

// -----------------------------------------------------------------------------
// Helpers do usuário logado
// -----------------------------------------------------------------------------
if (!function_exists('current_user_id')) {
  function current_user_id(): ?int {
    if (function_exists('auth_user_id')) {
      return auth_user_id();
    }

    if (session_status() !== PHP_SESSION_ACTIVE) {
      @session_start();
    }

    if (!isset($_SESSION['user_id'])) {
      return null;
    }

    $userId = (int)$_SESSION['user_id'];
    return $userId > 0 ? $userId : null;
  }
}

if (!function_exists('current_username')) {
  function current_username(): ?string {
    if (function_exists('auth_username')) {
      return auth_username();
    }

    if (session_status() !== PHP_SESSION_ACTIVE) {
      @session_start();
    }

    $username = trim((string)($_SESSION['username'] ?? ''));
    return $username !== '' ? $username : null;
  }
}

if (!function_exists('require_user_id')) {
  function require_user_id(): int {
    $userId = current_user_id();
    if ($userId === null || $userId <= 0) {
      redirect('/login.php');
    }
    return $userId;
  }
}

// -----------------------------------------------------------------------------
// Helpers de segurança para registros por usuário
// -----------------------------------------------------------------------------
if (!function_exists('record_belongs_to_user')) {
  /**
   * Verifica se um registro pertence ao usuário logado.
   *
   * Exemplo:
   *   if (!record_belongs_to_user($pdo, 'players', (int)$id, $userId)) { ... }
   */
  function record_belongs_to_user(PDO $pdo, string $table, int $id, int $userId): bool {
    $allowedTables = [
      'players',
      'matches',
      'match_players',
      'match_player_stats',
      'match_substitutions',
      'opponent_players',
      'opponent_match_player_stats',
      'academy_players',
      'academy_dismissed',
      'lineup_templates',
      'lineup_template_slots',
      'transfers',
      'injuries',
      'trophies',
      'users',
    ];

    if (!in_array($table, $allowedTables, true)) {
      return false;
    }

    $sql = "SELECT COUNT(*) FROM {$table} WHERE id = :id AND user_id = :user_id";
    if ($table === 'users') {
      $sql = "SELECT COUNT(*) FROM users WHERE id = :id";
      return (int)scalar($pdo, $sql, [':id' => $id], 0) > 0 && $id === $userId;
    }

    return (int)scalar($pdo, $sql, [
      ':id' => $id,
      ':user_id' => $userId,
    ], 0) > 0;
  }
}

// -----------------------------------------------------------------------------
// Layout (render_header/render_footer)
// -----------------------------------------------------------------------------
$layoutFile = __DIR__ . '/layout.php';
if (file_exists($layoutFile)) {
  require_once $layoutFile;
}