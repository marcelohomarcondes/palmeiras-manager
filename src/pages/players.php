<?php
declare(strict_types=1);

$pdo = db();
$userId = require_user_id();

/**
 * Opções fixas de posição (conforme requisito)
 */
$POSITIONS = ['GOL','ZAG','LD','LE','ALD','ALE','VOL','MC','ME','MD','MEI','PD','PE','SA','ATA'];

/**
 * Ordem customizada para ordenar por POS
 * (GOL, ALE, LE, ZAG, LD, ALD, VOL, ME, MC, MD, MEI, PE, PD, SA, ATA)
 */
$PRIMARY_POS_ORDER_CASE = "CASE UPPER(primary_position)
  WHEN 'GOL' THEN 1
  WHEN 'ALE' THEN 2
  WHEN 'LE'  THEN 3
  WHEN 'ZAG' THEN 4
  WHEN 'LD'  THEN 5
  WHEN 'ALD' THEN 6
  WHEN 'VOL' THEN 7
  WHEN 'ME'  THEN 8
  WHEN 'MC'  THEN 9
  WHEN 'MD'  THEN 10
  WHEN 'MEI' THEN 11
  WHEN 'PE'  THEN 12
  WHEN 'PD'  THEN 13
  WHEN 'SA'  THEN 14
  WHEN 'ATA' THEN 15
  ELSE 999 END";

/**
 * Helpers
 */
function parse_positions(string $raw): array
{
  $raw = trim($raw);
  if ($raw === '') return [];

  $raw = str_replace(';', ',', $raw);
  $parts = array_map('trim', explode(',', $raw));
  $parts = array_values(array_unique(array_filter($parts, fn($v) => $v !== '')));

  return $parts;
}

/**
 * Determina temporada padrão para registros gerados via players.php (aposentadoria).
 * Preferência: última season registrada em matches do usuário; fallback: ano atual.
 */
function current_season(PDO $pdo, int $userId): string
{
  try {
    $r = q(
      $pdo,
      "SELECT season
       FROM matches
       WHERE user_id = :user_id
         AND season IS NOT NULL
         AND TRIM(season) <> ''
       ORDER BY match_date DESC, id DESC
       LIMIT 1",
      [':user_id' => $userId]
    )->fetch();

    if ($r && isset($r['season']) && trim((string)$r['season']) !== '') {
      return (string)$r['season'];
    }
  } catch (Throwable $e) {
    // ignore
  }

  return date('Y');
}

$err = '';
$edit = null;

/**
 * Aposentar (GET)
 * - Registra em transfers como APOSENTADORIA
 * - Seta is_active=0
 * - Mantém histórico (não apaga jogador)
 */
if (isset($_GET['retire'])) {
  $pid = (int)$_GET['retire'];

  $p = q(
    $pdo,
    "SELECT id, name
     FROM players
     WHERE id = :id
       AND user_id = :user_id
       AND club_name = :club_name COLLATE NOCASE
     LIMIT 1",
    [
      ':id'        => $pid,
      ':user_id'   => $userId,
      ':club_name' => app_club(),
    ]
  )->fetch();

  if ($p) {
    $season = current_season($pdo, $userId);
    $today = date('Y-m-d');

    q(
      $pdo,
      "INSERT INTO transfers(
        user_id,
        season,
        type,
        player_id,
        athlete_name,
        club_origin,
        club_destination,
        value,
        term,
        grade,
        extra_player_name,
        shirt_number_assigned,
        transaction_date,
        notes
      ) VALUES (
        :user_id,
        :season,
        :type,
        :player_id,
        :athlete_name,
        :club_origin,
        :club_destination,
        :value,
        :term,
        :grade,
        :extra_player_name,
        :shirt_number_assigned,
        :transaction_date,
        :notes
      )",
      [
        ':user_id'               => $userId,
        ':season'                => $season,
        ':type'                  => 'APOSENTADORIA',
        ':player_id'             => (int)$p['id'],
        ':athlete_name'          => (string)$p['name'],
        ':club_origin'           => app_club(),
        ':club_destination'      => 'APOSENTADORIA',
        ':value'                 => null,
        ':term'                  => null,
        ':grade'                 => null,
        ':extra_player_name'     => null,
        ':shirt_number_assigned' => null,
        ':transaction_date'      => $today,
        ':notes'                 => 'Aposentado via players.php',
      ]
    );

    q(
      $pdo,
      "UPDATE players
       SET is_active = 0,
           updated_at = datetime('now')
       WHERE id = :id
         AND user_id = :user_id
         AND club_name = :club_name COLLATE NOCASE",
      [
        ':id'        => (int)$p['id'],
        ':user_id'   => $userId,
        ':club_name' => app_club(),
      ]
    );
  }

  redirect('/?page=players');
}

/** Edit (GET) */
if (isset($_GET['edit'])) {
  $edit = q(
    $pdo,
    "SELECT *
     FROM players
     WHERE id = :id
       AND user_id = :user_id
       AND club_name = :club_name COLLATE NOCASE",
    [
      ':id'        => (int)$_GET['edit'],
      ':user_id'   => $userId,
      ':club_name' => app_club(),
    ]
  )->fetch() ?: null;
}

/** Delete (GET) */
if (isset($_GET['del'])) {
  $idDel = (int)$_GET['del'];

  try {
    q(
      $pdo,
      "DELETE FROM players
       WHERE id = :id
         AND user_id = :user_id
         AND club_name = :club_name COLLATE NOCASE",
      [
        ':id'        => $idDel,
        ':user_id'   => $userId,
        ':club_name' => app_club(),
      ]
    );
  } catch (Throwable $e) {
    $err = 'Não foi possível excluir este atleta (há histórico vinculado).';
  }

  if ($err === '') {
    redirect('/?page=players');
  }
}

/** Save (POST) */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $id = (int)($_POST['id'] ?? 0);
  $name = trim((string)($_POST['name'] ?? ''));
  $shirt = ($_POST['shirt_number'] ?? '') === '' ? null : (int)$_POST['shirt_number'];

  $prim = trim((string)($_POST['primary_position'] ?? ''));

  $secArr = $_POST['secondary_positions'] ?? [];
  if (!is_array($secArr)) $secArr = [];

  $secArr = array_values(array_unique(array_filter(
    array_map(fn($v) => trim((string)$v), $secArr),
    fn($v) => $v !== ''
  )));

  $secArr = array_values(array_filter($secArr, fn($v) => in_array($v, $POSITIONS, true)));
  $sec = implode(',', $secArr);

  $active = isset($_POST['is_active']) ? 1 : 0;

  if ($name === '') {
    $err = 'Informe o nome do atleta.';
  } elseif (!in_array($prim, $POSITIONS, true)) {
    $err = 'Selecione uma posição primária válida.';
  }

  if ($err !== '') {
    $edit = [
      'id' => $id,
      'name' => $name,
      'shirt_number' => $shirt,
      'primary_position' => $prim,
      'secondary_positions' => $sec,
      'is_active' => $active,
    ];
  } else {
    if ($id > 0) {
      q(
        $pdo,
        "UPDATE players
         SET name = :name,
             shirt_number = :shirt_number,
             primary_position = :primary_position,
             secondary_positions = :secondary_positions,
             is_active = :is_active,
             updated_at = datetime('now')
         WHERE id = :id
           AND user_id = :user_id
           AND club_name = :club_name COLLATE NOCASE",
        [
          ':name'                => $name,
          ':shirt_number'        => $shirt,
          ':primary_position'    => $prim,
          ':secondary_positions' => $sec,
          ':is_active'           => $active,
          ':id'                  => $id,
          ':user_id'             => $userId,
          ':club_name'           => app_club(),
        ]
      );
    } else {
      q(
        $pdo,
        "INSERT INTO players(
          user_id,
          name,
          shirt_number,
          primary_position,
          secondary_positions,
          is_active,
          club_name
        ) VALUES (
          :user_id,
          :name,
          :shirt_number,
          :primary_position,
          :secondary_positions,
          :is_active,
          :club_name
        )",
        [
          ':user_id'             => $userId,
          ':name'                => $name,
          ':shirt_number'        => $shirt,
          ':primary_position'    => $prim,
          ':secondary_positions' => $sec,
          ':is_active'           => $active,
          ':club_name'           => app_club(),
        ]
      );
    }

    redirect('/?page=players');
  }
}

/**
 * SORT POR COLUNA
 */
$allowedDirs = ['ASC', 'DESC'];
$allowedSortKeys = ['number', 'name', 'primary', 'secondary', 'status'];

$sortKey = null;
$sortDir = null;

$getSortKey = trim((string)($_GET['sort_key'] ?? ''));
$getSortDir = strtoupper(trim((string)($_GET['sort_dir'] ?? '')));

if (in_array($getSortKey, $allowedSortKeys, true) && in_array($getSortDir, $allowedDirs, true)) {
  $sortKey = $getSortKey;
  $sortDir = $getSortDir;
}

$defaultOrderBy = "p.is_active DESC, {$PRIMARY_POS_ORDER_CASE} ASC, p.shirt_number ASC, p.name ASC";

$orderBy = $defaultOrderBy;
if ($sortKey !== null && $sortDir !== null) {
  if ($sortKey === 'number') {
    $orderBy = "p.shirt_number IS NULL ASC, p.shirt_number {$sortDir}, p.name ASC";
  } elseif ($sortKey === 'name') {
    $orderBy = "p.name {$sortDir}";
  } elseif ($sortKey === 'primary') {
    $orderBy = "{$PRIMARY_POS_ORDER_CASE} {$sortDir}, p.name ASC";
  } elseif ($sortKey === 'secondary') {
    $orderBy = "p.secondary_positions {$sortDir}, p.name ASC";
  } elseif ($sortKey === 'status') {
    $orderBy = "p.is_active {$sortDir}, p.name ASC";
  }
}

/**
 * Busca jogadores + último tipo de transferência, filtrando por user_id
 */
$rows = q(
  $pdo,
  "
  SELECT
    p.*,
    (
      SELECT t.type
      FROM transfers t
      WHERE t.user_id = :user_id_sub_1
        AND t.player_id = p.id
      ORDER BY t.transaction_date DESC, t.id DESC
      LIMIT 1
    ) AS last_transfer_type,
    (
      SELECT t.transaction_date
      FROM transfers t
      WHERE t.user_id = :user_id_sub_2
        AND t.player_id = p.id
      ORDER BY t.transaction_date DESC, t.id DESC
      LIMIT 1
    ) AS last_transfer_date
  FROM players p
  WHERE p.user_id = :user_id
    AND p.club_name = :club_name COLLATE NOCASE
  ORDER BY {$orderBy}
  ",
  [
    ':user_id'       => $userId,
    ':user_id_sub_1' => $userId,
    ':user_id_sub_2' => $userId,
    ':club_name'     => app_club(),
  ]
)->fetchAll();

/**
 * Apartar por grupos
 */
$ativos = [];
$emprestados = [];
$vendidos = [];
$aposentados = [];
$inativosOutros = [];

foreach ($rows as $r) {
  $isActive = (int)($r['is_active'] ?? 0) === 1;
  $lt = strtoupper(trim((string)($r['last_transfer_type'] ?? '')));

  if ($isActive) {
    $ativos[] = $r;
  } else {
    if ($lt === 'APOSENTADORIA') {
      $aposentados[] = $r;
    } elseif ($lt === 'SAIU POR EMPRÉSTIMO') {
      $emprestados[] = $r;
    } elseif ($lt === 'VENDIDO') {
      $vendidos[] = $r;
    } else {
      $inativosOutros[] = $r;
    }
  }
}

render_header('Elenco');

echo '<div class="row">';

/** FORM (lado esquerdo) */
echo '<div class="col-lg-3">';
echo '<div class="card card-soft mb-3">';
echo '<div class="card-header">' . ($edit ? 'Editar atleta' : 'Novo atleta') . '</div>';
echo '<div class="card-body">';

if ($err !== '') {
  echo '<div class="alert alert-danger py-2 small mb-3">' . h($err) . '</div>';
}

echo '<form method="post">';
if ($edit && isset($edit['id']) && (int)$edit['id'] > 0) {
  echo '<input type="hidden" name="id" value="' . h((string)$edit['id']) . '">';
}

echo '<div class="mb-3">';
echo '<label class="form-label">Nome</label>';
echo '<input type="text" class="form-control" name="name" value="' . h((string)($edit['name'] ?? '')) . '">';
echo '</div>';

echo '<div class="mb-3">';
echo '<label class="form-label">Número</label>';
echo '<input type="number" class="form-control" name="shirt_number" value="' . h((string)($edit['shirt_number'] ?? '')) . '">';
echo '</div>';

$primVal = (string)($edit['primary_position'] ?? '');
echo '<div class="mb-3">';
echo '<label class="form-label">Posição primária</label>';
echo '<select class="form-select" name="primary_position">';
echo '<option value="">-- selecione --</option>';
foreach ($POSITIONS as $p) {
  $sel = ($p === $primVal) ? ' selected' : '';
  echo '<option value="' . h($p) . '"' . $sel . '>' . h($p) . '</option>';
}
echo '</select>';
echo '</div>';

$secSelected = parse_positions((string)($edit['secondary_positions'] ?? ''));
echo '<div class="mb-3">';
echo '<label class="form-label">Posições secundárias</label>';
echo '<div class="row g-2">';
foreach ($POSITIONS as $p) {
  $checked = in_array($p, $secSelected, true) ? ' checked' : '';
  $idcb = 'sec_' . $p;
  echo '<div class="col-6">';
  echo '<div class="form-check">';
  echo '<input class="form-check-input" type="checkbox" name="secondary_positions[]" id="' . h($idcb) . '" value="' . h($p) . '"' . $checked . '>';
  echo '<label class="form-check-label" for="' . h($idcb) . '">' . h($p) . '</label>';
  echo '</div>';
  echo '</div>';
}
echo '</div>';
echo '<div class="text-muted small mt-2">Marque 0 ou mais posições secundárias.</div>';
echo '</div>';

$checked = (!$edit || (int)($edit['is_active'] ?? 1) === 1) ? 'checked' : '';
echo '<div class="form-check form-switch mb-3">';
echo '<input class="form-check-input" type="checkbox" name="is_active" id="is_active" ' . $checked . '>';
echo '<label class="form-check-label" for="is_active">Ativo no elenco</label>';
echo '</div>';

echo '<button class="btn btn-primary">Salvar</button>';
if ($edit) {
  echo ' <a class="btn btn-secondary" href="/?page=players">Cancelar</a>';
}
echo '</form>';

echo '<div class="text-muted small mt-3">Dica: transferências podem ativar/inativar atletas automaticamente.</div>';

echo '</div>';
echo '</div>';
echo '</div>';

/** LISTA (lado direito) */
echo '<div class="col-lg-9">';
echo '<div class="card card-soft">';
echo '<div class="card-header d-flex justify-content-between align-items-center">';
echo '<div>Lista</div>';
echo '<div class="text-muted small">Total: ' . count($rows) . '</div>';
echo '</div>';

echo '<div class="table-responsive">';

echo '<form method="get" id="sortFormPlayers">';
echo '<input type="hidden" name="page" value="players">';
echo '<input type="hidden" name="sort_key" id="sort_key" value="' . h((string)($sortKey ?? '')) . '">';
echo '<input type="hidden" name="sort_dir" id="sort_dir" value="' . h((string)($sortDir ?? '')) . '">';

function sortSelect(?string $activeKey, ?string $activeDir, string $thisKey): string
{
  $isActive = ($activeKey === $thisKey) ? ($activeDir ?? '') : '';
  $selNone = ($isActive === '') ? ' selected' : '';
  $selAsc  = ($isActive === 'ASC') ? ' selected' : '';
  $selDesc = ($isActive === 'DESC') ? ' selected' : '';

  return '<select class="form-select form-select-sm js-sortcol" data-sort-key="' . h($thisKey) . '">' .
           '<option value=""' . $selNone . '>—</option>' .
           '<option value="ASC"' . $selAsc . '>↑</option>' .
           '<option value="DESC"' . $selDesc . '>↓</option>' .
         '</select>';
}

function badge_for_row(array $r): string
{
  $isActive = (int)($r['is_active'] ?? 0) === 1;
  $lt = strtoupper(trim((string)($r['last_transfer_type'] ?? '')));

  if ($isActive) return 'Ativo';
  if ($lt === 'SAIU POR EMPRÉSTIMO') return 'Emprestado';
  if ($lt === 'VENDIDO') return 'Vendido';
  if ($lt === 'APOSENTADORIA') return 'Aposentado';

  return 'Inativo';
}

function render_section(string $title, array $list, ?string $emptyText = null): void
{
  global $sortKey, $sortDir;

  echo '<div class="p-3 border-top">';
  echo '<div class="d-flex justify-content-between align-items-center mb-2">';
  echo '<div class="fw-bold">' . h($title) . '</div>';
  echo '<div class="text-muted small">Total: ' . count($list) . '</div>';
  echo '</div>';

  echo '<table class="table table-sm align-middle mb-0">';
  echo '<thead>';
  echo '<tr>';
  echo '<th style="width:80px">#' . sortSelect($sortKey, $sortDir, 'number') . '</th>';
  echo '<th>Atleta' . sortSelect($sortKey, $sortDir, 'name') . '</th>';
  echo '<th style="width:140px">Posição' . sortSelect($sortKey, $sortDir, 'primary') . '</th>';
  echo '<th style="width:180px">Pos. Sec.' . sortSelect($sortKey, $sortDir, 'secondary') . '</th>';
  echo '<th style="width:140px">Status' . sortSelect($sortKey, $sortDir, 'status') . '</th>';
  echo '<th style="width:220px" class="text-end">Ações</th>';
  echo '</tr>';
  echo '</thead>';
  echo '<tbody>';

  if (!$list) {
    $txt = $emptyText ?? 'Nenhum atleta.';
    echo '<tr><td colspan="6" class="text-muted small py-3">' . h($txt) . '</td></tr>';
  } else {
    foreach ($list as $r) {
      echo '<tr>';
      echo '<td class="text-nowrap">' . h((string)($r['shirt_number'] ?? '')) . '</td>';
      echo '<td class="text-nowrap">' . h((string)$r['name']) . '</td>';
      echo '<td class="text-nowrap">' . h((string)$r['primary_position']) . '</td>';
      echo '<td class="text-nowrap">' . h((string)$r['secondary_positions']) . '</td>';
      echo '<td class="text-nowrap"><span class="badge bg-success-subtle text-success">' . badge_for_row($r) . '</span></td>';

      echo '<td class="text-end text-nowrap">';
      echo '<a class="btn btn-sm btn-primary" href="/?page=players&edit=' . (int)$r['id'] . '">Editar</a> ';

      $lt = strtoupper(trim((string)($r['last_transfer_type'] ?? '')));
      if ($lt !== 'APOSENTADORIA') {
        echo '<a class="btn btn-warning btn-sm" href="/?page=players&retire=' . (int)$r['id'] . '" onclick="return confirm(\'Confirmar aposentadoria?\')">Aposentar</a> ';
      }

      echo '<a class="btn btn-sm btn-danger" href="/?page=players&del=' . (int)$r['id'] . '" onclick="return confirm(\'Confirmar exclusão?\')">Excluir</a>';
      echo '</td>';

      echo '</tr>';
    }
  }

  echo '</tbody>';
  echo '</table>';
  echo '</div>';
}

render_section('Elenco Ativo', $ativos, 'Nenhum atleta ativo.');
render_section('Emprestados', $emprestados, 'Nenhum atleta emprestado.');
render_section('Vendidos', $vendidos, 'Nenhum atleta vendido.');
render_section('Aposentados', $aposentados, 'Nenhum atleta aposentado.');
render_section('Outros Inativos', $inativosOutros, 'Nenhum outro inativo.');

echo '</form>';
echo '</div>';

echo '<script>
(function () {
  var form = document.getElementById("sortFormPlayers");
  var inputKey = document.getElementById("sort_key");
  var inputDir = document.getElementById("sort_dir");
  if (!form || !inputKey || !inputDir) return;

  var selects = form.querySelectorAll(".js-sortcol");

  function clearOthers(active) {
    selects.forEach(function (s) {
      if (s !== active) s.value = "";
    });
  }

  selects.forEach(function (sel) {
    sel.addEventListener("change", function () {
      clearOthers(sel);

      var dir = (sel.value || "").trim();
      var key = (sel.getAttribute("data-sort-key") || "").trim();

      if (!dir) {
        inputKey.value = "";
        inputDir.value = "";
      } else {
        inputKey.value = key;
        inputDir.value = dir;
      }

      form.submit();
    });
  });
})();
</script>';

echo '</div>';
echo '</div>';

echo '</div>';

render_footer();