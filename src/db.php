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

      $pdo->exec("PRAGMA foreign_keys = ON;");
      $pdo->exec("PRAGMA journal_mode = WAL;");
      $pdo->exec("PRAGMA synchronous = NORMAL;");

      $instance = $pdo;
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
// Query helpers (q / scalar)
// -----------------------------------------------------------------------------
if (!function_exists('q')) {
  function q(PDO $pdo, string $sql, array $params = []): PDOStatement {
    $st = $pdo->prepare($sql);
    $st->execute($params);
    return $st;
  }
}

if (!function_exists('scalar')) {
  function scalar(PDO $pdo, string $sql, array $params = [], $default = null) {
    $st = q($pdo, $sql, $params);
    $v = $st->fetchColumn();
    return ($v === false) ? $default : $v;
  }
}

if (!function_exists('pm_table_has_column')) {
  function pm_table_has_column(PDO $pdo, string $table, string $column): bool {
    try {
      $rows = $pdo->query("PRAGMA table_info($table)")->fetchAll(PDO::FETCH_ASSOC) ?: [];
      foreach ($rows as $row) {
        if ((string)($row['name'] ?? '') === $column) {
          return true;
        }
      }
    } catch (Throwable $e) {
    }
    return false;
  }
}

if (!function_exists('pm_table_exists')) {
  function pm_table_exists(PDO $pdo, string $table): bool {
    try {
      $st = $pdo->prepare("SELECT name FROM sqlite_master WHERE type = 'table' AND name = :name LIMIT 1");
      $st->execute([':name' => $table]);
      return (bool)$st->fetchColumn();
    } catch (Throwable $e) {
      return false;
    }
  }
}

if (!function_exists('pm_ensure_shirt_history_schema')) {
  function pm_ensure_shirt_history_schema(PDO $pdo): void {
    static $done = false;
    if ($done) {
      return;
    }
    $done = true;

    // cria a tabela se não existir
    $pdo->exec("
      CREATE TABLE IF NOT EXISTS player_shirt_history (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER,
        player_id INTEGER NOT NULL REFERENCES players(id) ON DELETE CASCADE,
        shirt_number INTEGER,
        start_date TEXT NOT NULL DEFAULT '',
        end_date TEXT DEFAULT NULL,
        notes TEXT NOT NULL DEFAULT '',
        created_at TEXT NOT NULL DEFAULT (datetime('now'))
      )
    ");

    // migração defensiva para bancos já existentes
    if (!pm_table_has_column($pdo, 'player_shirt_history', 'user_id')) {
      $pdo->exec("ALTER TABLE player_shirt_history ADD COLUMN user_id INTEGER");
    }
    if (!pm_table_has_column($pdo, 'player_shirt_history', 'player_id')) {
      $pdo->exec("ALTER TABLE player_shirt_history ADD COLUMN player_id INTEGER");
    }
    if (!pm_table_has_column($pdo, 'player_shirt_history', 'shirt_number')) {
      $pdo->exec("ALTER TABLE player_shirt_history ADD COLUMN shirt_number INTEGER");
    }
    if (!pm_table_has_column($pdo, 'player_shirt_history', 'start_date')) {
      $pdo->exec("ALTER TABLE player_shirt_history ADD COLUMN start_date TEXT NOT NULL DEFAULT ''");
    }
    if (!pm_table_has_column($pdo, 'player_shirt_history', 'end_date')) {
      $pdo->exec("ALTER TABLE player_shirt_history ADD COLUMN end_date TEXT DEFAULT NULL");
    }
    if (!pm_table_has_column($pdo, 'player_shirt_history', 'notes')) {
      $pdo->exec("ALTER TABLE player_shirt_history ADD COLUMN notes TEXT NOT NULL DEFAULT ''");
    }
    if (!pm_table_has_column($pdo, 'player_shirt_history', 'created_at')) {
      $pdo->exec("ALTER TABLE player_shirt_history ADD COLUMN created_at TEXT NOT NULL DEFAULT ''");
    }

    // normaliza campos vazios em bases antigas
    $pdo->exec("
      UPDATE player_shirt_history
         SET created_at = datetime('now')
       WHERE COALESCE(TRIM(created_at), '') = ''
    ");

    if (pm_table_has_column($pdo, 'players', 'user_id') && pm_table_has_column($pdo, 'player_shirt_history', 'user_id')) {
      $pdo->exec("
        UPDATE player_shirt_history
           SET user_id = (
             SELECT p.user_id
               FROM players p
              WHERE p.id = player_shirt_history.player_id
              LIMIT 1
           )
         WHERE user_id IS NULL
           AND player_id IS NOT NULL
      ");
    }

    $pdo->exec("
      UPDATE player_shirt_history
         SET start_date = substr(created_at, 1, 10)
       WHERE COALESCE(TRIM(start_date), '') = ''
    ");

    // índices só após garantir as colunas
    if (pm_table_has_column($pdo, 'player_shirt_history', 'player_id') && pm_table_has_column($pdo, 'player_shirt_history', 'start_date')) {
      $pdo->exec("CREATE INDEX IF NOT EXISTS idx_player_shirt_history_player ON player_shirt_history(player_id, start_date)");
    }

    if (pm_table_has_column($pdo, 'player_shirt_history', 'user_id') && pm_table_has_column($pdo, 'player_shirt_history', 'player_id')) {
      $pdo->exec("CREATE INDEX IF NOT EXISTS idx_player_shirt_history_user ON player_shirt_history(user_id, player_id)");
    }

    // snapshot da camisa por partida
    if (pm_table_exists($pdo, 'match_players') && !pm_table_has_column($pdo, 'match_players', 'shirt_number_snapshot')) {
      $pdo->exec("ALTER TABLE match_players ADD COLUMN shirt_number_snapshot INTEGER");
    }

    if (pm_table_exists($pdo, 'match_players') && pm_table_has_column($pdo, 'match_players', 'player_id')) {
      if (pm_table_has_column($pdo, 'players', 'user_id') && pm_table_has_column($pdo, 'match_players', 'user_id')) {
        $pdo->exec("
          UPDATE match_players
             SET shirt_number_snapshot = (
               SELECT p.shirt_number
                 FROM players p
                WHERE p.id = match_players.player_id
                  AND p.user_id = match_players.user_id
                LIMIT 1
             )
           WHERE player_id IS NOT NULL
             AND shirt_number_snapshot IS NULL
        ");
      } else {
        $pdo->exec("
          UPDATE match_players
             SET shirt_number_snapshot = (
               SELECT p.shirt_number
                 FROM players p
                WHERE p.id = match_players.player_id
                LIMIT 1
             )
           WHERE player_id IS NOT NULL
             AND shirt_number_snapshot IS NULL
        ");
      }
    }

    // cria registro inicial para jogadores que ainda não têm histórico
    $playerSql = "SELECT id, user_id, shirt_number, created_at FROM players";
    $players = $pdo->query($playerSql)->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $checkSt = $pdo->prepare("
      SELECT id
        FROM player_shirt_history
       WHERE player_id = :player_id
         AND (
           (:user_id IS NULL AND user_id IS NULL)
           OR user_id = :user_id
         )
       LIMIT 1
    ");

    $insertSt = $pdo->prepare("
      INSERT INTO player_shirt_history (user_id, player_id, shirt_number, start_date, end_date, notes, created_at)
      VALUES (:user_id, :player_id, :shirt_number, :start_date, NULL, :notes, datetime('now'))
    ");

    foreach ($players as $player) {
      $playerId = (int)($player['id'] ?? 0);
      if ($playerId <= 0) {
        continue;
      }

      $userId = isset($player['user_id']) ? (int)$player['user_id'] : null;
      if ($userId !== null && $userId <= 0) {
        $userId = null;
      }

      $checkSt->execute([
        ':player_id' => $playerId,
        ':user_id' => $userId,
      ]);
      $exists = $checkSt->fetchColumn();
      if ($exists) {
        continue;
      }

      $startDate = date('Y-m-d');
      $createdAt = trim((string)($player['created_at'] ?? ''));
      if ($createdAt !== '') {
        $ts = strtotime($createdAt);
        if ($ts !== false) {
          $startDate = date('Y-m-d', $ts);
        }
      }

      $insertSt->execute([
        ':user_id' => $userId,
        ':player_id' => $playerId,
        ':shirt_number' => (($player['shirt_number'] ?? null) === '' ? null : $player['shirt_number']),
        ':start_date' => $startDate,
        ':notes' => 'Registro inicial gerado automaticamente',
      ]);
    }
  }
}

if (!function_exists('pm_sync_player_shirt_history')) {
  function pm_sync_player_shirt_history(PDO $pdo, int $userId, int $playerId, $shirtNumber, ?string $startDate = null, string $notes = ''): void {
    if ($playerId <= 0) {
      return;
    }

    pm_ensure_shirt_history_schema($pdo);

    $shirtValue = ($shirtNumber === '' ? null : $shirtNumber);
    if ($shirtValue !== null) {
      $shirtValue = (int)$shirtValue;
    }

    $normStart = trim((string)($startDate ?? ''));
    if ($normStart === '') {
      $normStart = date('Y-m-d');
    } else {
      $ts = strtotime($normStart);
      $normStart = ($ts !== false) ? date('Y-m-d', $ts) : date('Y-m-d');
    }

    $open = q(
      $pdo,
      "SELECT *
         FROM player_shirt_history
        WHERE player_id = :player_id
          AND (
            (:user_id <= 0 AND user_id IS NULL)
            OR user_id = :user_id
          )
          AND end_date IS NULL
        ORDER BY date(start_date) DESC, id DESC
        LIMIT 1",
      [
        ':player_id' => $playerId,
        ':user_id' => $userId,
      ]
    )->fetch(PDO::FETCH_ASSOC);

    $sameNumber = false;
    if ($open) {
      $openNum = $open['shirt_number'];
      if ($openNum === null || $openNum === '') {
        $sameNumber = ($shirtValue === null);
      } else {
        $sameNumber = ((int)$openNum === (int)$shirtValue);
      }
    }

    if ($open && $sameNumber) {
      if ($normStart < (string)$open['start_date']) {
        q(
          $pdo,
          "UPDATE player_shirt_history
              SET start_date = :start_date,
                  notes = CASE
                    WHEN TRIM(COALESCE(:notes, '')) = '' THEN notes
                    ELSE :notes
                  END
            WHERE id = :id",
          [
            ':start_date' => $normStart,
            ':notes' => $notes,
            ':id' => (int)$open['id'],
          ]
        );
      }
      return;
    }

    if ($open) {
      $endDate = $normStart;
      $ts = strtotime($normStart);
      if ($ts !== false) {
        $endDate = date('Y-m-d', strtotime('-1 day', $ts));
      }
      if ($endDate < (string)$open['start_date']) {
        $endDate = (string)$open['start_date'];
      }

      q(
        $pdo,
        "UPDATE player_shirt_history
            SET end_date = :end_date
          WHERE id = :id",
        [
          ':end_date' => $endDate,
          ':id' => (int)$open['id'],
        ]
      );
    }

    q(
      $pdo,
      "INSERT INTO player_shirt_history (
          user_id,
          player_id,
          shirt_number,
          start_date,
          end_date,
          notes,
          created_at
        ) VALUES (
          :user_id,
          :player_id,
          :shirt_number,
          :start_date,
          NULL,
          :notes,
          datetime('now')
        )",
      [
        ':user_id' => ($userId > 0 ? $userId : null),
        ':player_id' => $playerId,
        ':shirt_number' => $shirtValue,
        ':start_date' => $normStart,
        ':notes' => $notes,
      ]
    );
  }
}

pm_ensure_shirt_history_schema($pdo);

// -----------------------------------------------------------------------------
// Integração com autenticação
// -----------------------------------------------------------------------------
$authFile = __DIR__ . '/auth/auth.php';
if (file_exists($authFile)) {
  require_once $authFile;
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