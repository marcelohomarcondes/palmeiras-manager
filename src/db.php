<?php
// src/db.php
declare(strict_types=1);

/**
 * Conexão + helpers globais do projeto
 * - db(): PDO (singleton)
 * - $pdo global
 * - helpers: h(), q(), scalar(), redirect(), app_club()
 * - carrega layout.php (render_header/render_footer)
 */

// -----------------------------------------------------------------------------
// Helpers (HTML)
// -----------------------------------------------------------------------------
if (!function_exists('h')) {
  function h(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
  }
}

// -----------------------------------------------------------------------------
// DB (PDO singleton)
// -----------------------------------------------------------------------------
if (!function_exists('db')) {
  function db(): PDO {
    static $instance = null;
    if ($instance instanceof PDO) return $instance;

    // Ajuste padrão do seu projeto: data/app.sqlite
    $dbPath = __DIR__ . '/../data/app.sqlite';
    $dsn = 'sqlite:' . $dbPath;

    try {
      $pdo = new PDO($dsn, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
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
      // Mantém mensagem clara (sem “tela branca”)
      die("Erro: conexão PDO não disponível. Verifique src/db.php e data/app.sqlite. Detalhe: " . $e->getMessage());
    }
  }
}

// garante $pdo global SEMPRE
$pdo = db();

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
// Clube atual (case-insensitive no SQL você já faz via UPPER/TRIM)
// -----------------------------------------------------------------------------
if (!function_exists('app_club')) {
  function app_club(): string {
    /**
     * Se você tiver algum lugar no projeto que defina um clube dinâmico,
     * dá pra trocar aqui depois (ex: config, sessão, tabela settings).
     * Por agora, mantém padrão do seu app:
     */
    return 'PALMEIRAS';
  }
}

// -----------------------------------------------------------------------------
// Layout (render_header/render_footer)
// -----------------------------------------------------------------------------
$layoutFile = __DIR__ . '/layout.php';
if (file_exists($layoutFile)) {
  require_once $layoutFile;
}
