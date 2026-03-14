<?php
declare(strict_types=1);

$pdo = db();
$userId = require_user_id();

/**
 * Mapeamento das imagens dos troféus
 */
$trophyImages = [
  'Paulistão Casas Bahia' => [
    'file' => 'paulista.png',
    'fallback' => 'https://www.verdazzo.com.br/wp-content/uploads/2016/11/sp2024-44x44.png',
  ],
  'Brasileirão Betano' => [
    'file' => 'brasileiro.png',
    'fallback' => 'https://www.verdazzo.com.br/wp-content/uploads/2020/06/2016-brasileiro-44x44.png',
  ],
  'Copa Betano do Brasil' => [
    'file' => 'copa-do-brasil.png',
    'fallback' => 'https://www.verdazzo.com.br/wp-content/uploads/2020/06/2015-copa-do-brasil-44x44.png',
  ],
  'CONMEBOL Libertadores' => [
    'file' => 'libertadores.png',
    'fallback' => 'https://www.verdazzo.com.br/wp-content/uploads/2020/06/1999-libertadores-44x44.png',
  ],
  'CONMEBOL Sul-Americana' => [
    'file' => 'sul-americana.png',
    'fallback' => 'https://www.verdazzo.com.br/wp-content/uploads/2016/09/tr_sulamericana1-44x44.png',
  ],
  'CONMEBOL Recopa' => [
    'file' => 'recopa.png',
    'fallback' => 'https://www.verdazzo.com.br/wp-content/uploads/2021/01/recopa-44x44.png',
  ],
  'Supercopa do Brasil' => [
    'file' => 'supercopa.png',
    'fallback' => 'https://www.verdazzo.com.br/wp-content/uploads/2021/03/supercopa2020-44x44.png',
  ],
  'Intercontinental FIFA' => [
    'file' => 'intercontinental.png',
    'fallback' => 'https://www.verdazzo.com.br/wp-content/uploads/2016/09/mundial-44x44.png',
  ],
  'Copa do Mundo de Clubes da FIFA' => [
    'file' => 'mundial-de-clubes.png',
    'fallback' => 'https://www.verdazzo.com.br/wp-content/uploads/2025/06/clubwc-44x44.png',
  ],
];

$competitions = array_keys($trophyImages);

/**
 * Helpers
 */
function pm_table_columns(PDO $pdo, string $table): array
{
  $cols = [];
  $rows = $pdo->query("PRAGMA table_info($table)")->fetchAll(PDO::FETCH_ASSOC);
  foreach ($rows as $row) {
    if (isset($row['name'])) {
      $cols[] = (string)$row['name'];
    }
  }
  return $cols;
}

function pm_ensure_trophies_schema(PDO $pdo, int $userId): void
{
  $cols = pm_table_columns($pdo, 'trophies');

  if (!in_array('user_id', $cols, true)) {
    $pdo->exec("ALTER TABLE trophies ADD COLUMN user_id INTEGER NOT NULL DEFAULT 1");
  }

  if (!in_array('notes', $cols, true)) {
    $pdo->exec("ALTER TABLE trophies ADD COLUMN notes TEXT");
  }

  /**
   * Compatibilidade:
   * versão antiga -> competition_name / achieved_at
   * versão nova   -> competition / title_date
   *
   * Garantimos que a tabela sempre termine com competition_name e achieved_at,
   * copiando os dados caso venham da estrutura nova.
   */
  $cols = pm_table_columns($pdo, 'trophies');

  if (!in_array('competition_name', $cols, true)) {
    $pdo->exec("ALTER TABLE trophies ADD COLUMN competition_name TEXT");
    $cols = pm_table_columns($pdo, 'trophies');
    if (in_array('competition', $cols, true)) {
      $pdo->exec("
        UPDATE trophies
           SET competition_name = competition
         WHERE (competition_name IS NULL OR competition_name = '')
           AND competition IS NOT NULL
           AND competition <> ''
      ");
    }
  }

  $cols = pm_table_columns($pdo, 'trophies');

  if (!in_array('achieved_at', $cols, true)) {
    $pdo->exec("ALTER TABLE trophies ADD COLUMN achieved_at TEXT");
    $cols = pm_table_columns($pdo, 'trophies');
    if (in_array('title_date', $cols, true)) {
      $pdo->exec("
        UPDATE trophies
           SET achieved_at = title_date
         WHERE (achieved_at IS NULL OR achieved_at = '')
           AND title_date IS NOT NULL
           AND title_date <> ''
      ");
    }
  }

  /**
   * Se existir estrutura nova, também sincroniza nas próximas execuções.
   */
  $cols = pm_table_columns($pdo, 'trophies');

  if (in_array('competition', $cols, true)) {
    $pdo->exec("
      UPDATE trophies
         SET competition_name = competition
       WHERE (competition_name IS NULL OR competition_name = '')
         AND competition IS NOT NULL
         AND competition <> ''
    ");
  }

  if (in_array('title_date', $cols, true)) {
    $pdo->exec("
      UPDATE trophies
         SET achieved_at = title_date
       WHERE (achieved_at IS NULL OR achieved_at = '')
         AND title_date IS NOT NULL
         AND title_date <> ''
    ");
  }

  /**
   * Garante que registros antigos fiquem vinculados ao usuário atual
   * quando ainda estiverem zerados / nulos.
   */
  $pdo->prepare("
    UPDATE trophies
       SET user_id = :user_id
     WHERE user_id IS NULL OR user_id = 0
  ")->execute([
    ':user_id' => $userId,
  ]);
}

function trophy_img(array $trophyImages, string $competition): array
{
  $local = '/assets/trophies/trophy.png';
  $fallback = '/assets/trophies/trophy.png';

  if (isset($trophyImages[$competition])) {
    $local = '/assets/trophies/' . $trophyImages[$competition]['file'];
    $fallback = $trophyImages[$competition]['fallback'] ?: $local;
  }

  return [$local, $fallback];
}

function season_to_year(string $season): ?int
{
  if (preg_match('/(19\d{2}|20\d{2})/', $season, $m)) {
    return (int)$m[1];
  }
  if (preg_match('/^\d{4}$/', $season)) {
    return (int)$season;
  }
  return null;
}

function decade_label(int $year): string
{
  $d = (int)(floor($year / 10) * 10);
  return $d . 's';
}

pm_ensure_trophies_schema($pdo, $userId);

$err = '';
$edit = null;

/**
 * Cadastro / edição
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $id = (int)($_POST['id'] ?? 0);
  $comp = trim((string)($_POST['competition_name'] ?? ''));
  $season = trim((string)($_POST['season'] ?? ''));
  $ach = trim((string)($_POST['achieved_at'] ?? ''));
  $ach = $ach === '' ? null : $ach;
  $notes = trim((string)($_POST['notes'] ?? ''));

  if ($comp === '' || $season === '') {
    $err = 'Preencha Campeonato e Temporada.';
  } else {
    $cols = pm_table_columns($pdo, 'trophies');
    $hasCompetition = in_array('competition', $cols, true);
    $hasTitleDate   = in_array('title_date', $cols, true);

    if ($id > 0) {
      if ($hasCompetition && $hasTitleDate) {
        q(
          $pdo,
          "UPDATE trophies
              SET competition_name = :competition_name,
                  season = :season,
                  achieved_at = :achieved_at,
                  notes = :notes,
                  competition = :competition,
                  title_date = :title_date
            WHERE id = :id
              AND user_id = :user_id",
          [
            ':competition_name' => $comp,
            ':season'           => $season,
            ':achieved_at'      => $ach,
            ':notes'            => $notes,
            ':competition'      => $comp,
            ':title_date'       => $ach,
            ':id'               => $id,
            ':user_id'          => $userId,
          ]
        );
      } else {
        q(
          $pdo,
          "UPDATE trophies
              SET competition_name = :competition_name,
                  season = :season,
                  achieved_at = :achieved_at,
                  notes = :notes
            WHERE id = :id
              AND user_id = :user_id",
          [
            ':competition_name' => $comp,
            ':season'           => $season,
            ':achieved_at'      => $ach,
            ':notes'            => $notes,
            ':id'               => $id,
            ':user_id'          => $userId,
          ]
        );
      }
    } else {
      if ($hasCompetition && $hasTitleDate) {
        q(
          $pdo,
          "INSERT INTO trophies (
             user_id,
             competition_name,
             season,
             achieved_at,
             notes,
             competition,
             title_date
           ) VALUES (
             :user_id,
             :competition_name,
             :season,
             :achieved_at,
             :notes,
             :competition,
             :title_date
           )",
          [
            ':user_id'          => $userId,
            ':competition_name' => $comp,
            ':season'           => $season,
            ':achieved_at'      => $ach,
            ':notes'            => $notes,
            ':competition'      => $comp,
            ':title_date'       => $ach,
          ]
        );
      } else {
        q(
          $pdo,
          "INSERT INTO trophies (
             user_id,
             competition_name,
             season,
             achieved_at,
             notes
           ) VALUES (
             :user_id,
             :competition_name,
             :season,
             :achieved_at,
             :notes
           )",
          [
            ':user_id'          => $userId,
            ':competition_name' => $comp,
            ':season'           => $season,
            ':achieved_at'      => $ach,
            ':notes'            => $notes,
          ]
        );
      }
    }

    redirect('/?page=trophies');
  }
}

/**
 * Edição
 */
if (isset($_GET['edit'])) {
  $edit = q(
    $pdo,
    "SELECT *
       FROM trophies
      WHERE id = :id
        AND user_id = :user_id
      LIMIT 1",
    [
      ':id'      => (int)$_GET['edit'],
      ':user_id' => $userId,
    ]
  )->fetch() ?: null;
}

/**
 * Exclusão
 */
if (isset($_GET['del'])) {
  q(
    $pdo,
    "DELETE FROM trophies
      WHERE id = :id
        AND user_id = :user_id",
    [
      ':id'      => (int)$_GET['del'],
      ':user_id' => $userId,
    ]
  );

  redirect('/?page=trophies');
}

/**
 * Filtro
 */
$filterComp = trim((string)($_GET['comp'] ?? ''));

/**
 * Listagem
 */
if ($filterComp !== '') {
  $rows = q(
    $pdo,
    "SELECT *
       FROM trophies
      WHERE user_id = :user_id
        AND competition_name = :comp
      ORDER BY season DESC, achieved_at DESC, id DESC",
    [
      ':user_id' => $userId,
      ':comp'    => $filterComp,
    ]
  )->fetchAll();
} else {
  $rows = q(
    $pdo,
    "SELECT *
       FROM trophies
      WHERE user_id = :user_id
      ORDER BY season DESC, achieved_at DESC, id DESC",
    [
      ':user_id' => $userId,
    ]
  )->fetchAll();
}

/**
 * Agregações
 */
$totalTitles = count($rows);
$grouped = [];
$yearsByCompetition = [];
$allYears = [];
$latestTitle = null;

foreach ($rows as $r) {
  $c = (string)($r['competition_name'] ?? '');
  if ($c === '') {
    continue;
  }

  $grouped[$c] = $grouped[$c] ?? [];
  $grouped[$c][] = $r;

  $y = season_to_year((string)($r['season'] ?? ''));
  if ($y !== null) {
    $yearsByCompetition[$c] = $yearsByCompetition[$c] ?? [];
    $yearsByCompetition[$c][] = $y;

    $allYears[] = [
      'year'        => $y,
      'competition' => $c,
      'season'      => (string)($r['season'] ?? ''),
      'achieved_at' => (string)($r['achieved_at'] ?? ''),
      'notes'       => (string)($r['notes'] ?? ''),
      'id'          => (int)$r['id'],
    ];
  }

  $candidateScore = 0;
  if (!empty($r['achieved_at'])) {
    $candidateScore = (int)strtotime((string)$r['achieved_at']);
  } elseif ($y !== null) {
    $candidateScore = (int)strtotime($y . '-12-31');
  }

  if ($latestTitle === null || $candidateScore > $latestTitle['_score']) {
    $latestTitle = [
      '_score'      => $candidateScore,
      'competition' => $c,
      'season'      => (string)($r['season'] ?? ''),
      'achieved_at' => (string)($r['achieved_at'] ?? ''),
    ];
  }
}

foreach ($yearsByCompetition as $c => $ys) {
  $ys = array_values(array_unique($ys));
  sort($ys);
  $yearsByCompetition[$c] = $ys;
}

$timeline = [];
usort($allYears, fn($a, $b) => $b['year'] <=> $a['year']);

foreach ($allYears as $it) {
  $label = decade_label($it['year']);
  $timeline[$label] = $timeline[$label] ?? [];
  $timeline[$label][] = $it;
}

render_header('Troféus');

echo '<div class="row g-3">';

/**
 * Coluna esquerda
 */
echo '<div class="col-lg-4 col-xl-3">';
echo '<div class="card card-soft p-3">';
echo '<div class="d-flex justify-content-between align-items-start mb-3">';
echo '<div class="fw-bold">' . ($edit ? 'Editar troféu' : 'Novo troféu') . '</div>';
echo '<span class="badge text-bg-success">Total: ' . (int)$totalTitles . '</span>';
echo '</div>';

if ($latestTitle && $totalTitles > 0) {
  $ltDate = $latestTitle['achieved_at'] !== ''
    ? $latestTitle['achieved_at']
    : ($latestTitle['season'] !== '' ? $latestTitle['season'] : '-');

  echo '<div class="border rounded-3 p-3 mb-3">';
  echo '<div class="small text-muted mb-1">Último título</div>';
  echo '<div class="fw-semibold">' . h($latestTitle['competition']) . '</div>';
  echo '<div class="small text-muted">' . h($ltDate) . '</div>';
  echo '</div>';
}

if ($err !== '') {
  echo '<div class="alert alert-danger py-2 mb-3">' . h($err) . '</div>';
}

echo '<form method="post" class="vstack gap-3">';
if ($edit) {
  echo '<input type="hidden" name="id" value="' . (int)$edit['id'] . '">';
}

echo '<div>';
echo '<label class="form-label">Campeonato</label>';
echo '<select class="form-select" name="competition_name" required>';
echo '<option value="">-- selecione --</option>';
foreach ($competitions as $c) {
  $sel = ((string)($edit['competition_name'] ?? '') === $c) ? 'selected' : '';
  echo '<option value="' . h($c) . '" ' . $sel . '>' . h($c) . '</option>';
}
echo '</select>';
echo '</div>';

echo '<div>';
echo '<label class="form-label">Temporada</label>';
echo '<input class="form-control" name="season" required value="' . h((string)($edit['season'] ?? '')) . '" placeholder="ex: 2026">';
echo '</div>';

echo '<div>';
echo '<label class="form-label">Data (opcional)</label>';
echo '<input class="form-control" type="date" name="achieved_at" value="' . h((string)($edit['achieved_at'] ?? '')) . '">';
echo '</div>';

echo '<div>';
echo '<label class="form-label">Notas</label>';
echo '<textarea class="form-control" rows="3" name="notes" placeholder="Observações adicionais...">' . h((string)($edit['notes'] ?? '')) . '</textarea>';
echo '</div>';

echo '<div class="d-flex gap-2">';
echo '<button class="btn btn-primary">Salvar</button>';
if ($edit) {
  echo '<a class="btn btn-secondary" href="/?page=trophies">Cancelar</a>';
}
echo '</div>';

echo '</form>';
echo '</div>';
echo '</div>';

/**
 * Coluna direita
 */
echo '<div class="col-lg-8 col-xl-9">';
echo '<div class="card card-soft p-3">';

/**
 * Filtros
 */
echo '<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">';
echo '<div class="fw-bold">Galeria</div>';

echo '<form method="get" class="d-flex flex-wrap gap-2 align-items-center">';
echo '<input type="hidden" name="page" value="trophies">';
echo '<select class="form-select" name="comp" style="min-width:240px;">';
echo '<option value="">(todos)</option>';
foreach ($competitions as $c) {
  $sel = ($filterComp === $c) ? 'selected' : '';
  echo '<option value="' . h($c) . '" ' . $sel . '>' . h($c) . '</option>';
}
echo '</select>';
echo '<button class="btn btn-primary" type="submit">Aplicar</button>';
echo '<a class="btn btn-secondary" href="/?page=trophies">Limpar</a>';
echo '</form>';
echo '</div>';

/**
 * Histórico em tabela
 */
echo '<div class="table-responsive mb-4">';
echo '<table class="table table-sm align-middle mb-0">';
echo '<thead>';
echo '<tr>';
echo '<th>Competição</th>';
echo '<th>Temporada</th>';
echo '<th>Data</th>';
echo '<th>Notas</th>';
echo '<th class="text-end">Ações</th>';
echo '</tr>';
echo '</thead>';
echo '<tbody>';

if (!$rows) {
  echo '<tr><td colspan="5" class="text-muted">Nenhum troféu cadastrado.</td></tr>';
} else {
  foreach ($rows as $r) {
    echo '<tr>';
    echo '<td>' . h((string)($r['competition_name'] ?? '')) . '</td>';
    echo '<td>' . h((string)($r['season'] ?? '')) . '</td>';
    echo '<td>' . h((string)($r['achieved_at'] ?? '')) . '</td>';
    echo '<td>' . h((string)($r['notes'] ?? '')) . '</td>';
    echo '<td class="text-end">';
    echo '<a class="btn btn-sm btn-primary" href="/?page=trophies&edit=' . (int)$r['id'] . '">Editar</a> ';
    echo '<a class="btn btn-sm btn-danger" href="/?page=trophies&del=' . (int)$r['id'] . '" onclick="return confirm(\'Excluir troféu?\')">Excluir</a>';
    echo '</td>';
    echo '</tr>';
  }
}

echo '</tbody>';
echo '</table>';
echo '</div>';

/**
 * Galeria visual
 */
echo '<hr class="my-4">';

echo '<div class="mb-2">';
echo '<div class="fw-bold">Galeria visual</div>';
echo '<div class="text-muted small">Clique em um troféu para ver detalhes</div>';
echo '</div>';

echo '<div class="row g-3">';

foreach ($competitions as $c) {
  if ($filterComp !== '' && $filterComp !== $c) {
    continue;
  }

  $count = isset($grouped[$c]) ? count($grouped[$c]) : 0;
  $years = $yearsByCompetition[$c] ?? [];
  $yearsText = $years ? implode(', ', $years) : '—';
  [$local, $fallback] = trophy_img($trophyImages, $c);
  $modalId = 'modal_' . substr(md5($c), 0, 10);

  echo '<div class="col-md-6 col-xl-4">';
  echo '<div class="card h-100 border-0 shadow-sm">';
  echo '<button type="button" class="btn text-start p-0 border-0 bg-transparent" data-bs-toggle="modal" data-bs-target="#' . h($modalId) . '">';
  echo '<div class="card-body d-flex align-items-center gap-3">';
  echo '<img src="' . h($local) . '" alt="' . h($c) . '" width="44" height="44" style="object-fit:contain;" onerror="this.onerror=null;this.src=\'' . h($fallback) . '\';">';
  echo '<div class="flex-grow-1">';
  echo '<div class="fw-semibold">' . h($c) . '</div>';
  echo '<div class="small text-muted">x' . (int)$count . '</div>';
  echo '<div class="small text-muted">Anos: ' . h($yearsText) . '</div>';
  echo '</div>';
  echo '</div>';
  echo '</button>';
  echo '</div>';
  echo '</div>';

  /**
   * Modal do troféu
   */
  echo '<div class="modal fade" id="' . h($modalId) . '" tabindex="-1" aria-hidden="true">';
  echo '<div class="modal-dialog modal-dialog-centered modal-lg">';
  echo '<div class="modal-content">';
  echo '<div class="modal-header">';
  echo '<h5 class="modal-title">' . h($c) . '</h5>';
  echo '<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>';
  echo '</div>';
  echo '<div class="modal-body">';

  echo '<div class="d-flex align-items-center gap-3 mb-3">';
  echo '<img src="' . h($local) . '" alt="' . h($c) . '" width="52" height="52" style="object-fit:contain;" onerror="this.onerror=null;this.src=\'' . h($fallback) . '\';">';
  echo '<div>';
  echo '<div class="small text-muted">Títulos</div>';
  echo '<div class="fw-semibold">x' . (int)$count . '</div>';
  echo '</div>';
  echo '</div>';

  echo '<div class="mb-3">';
  echo '<div class="small text-muted mb-1">Anos conquistados</div>';
  if (!$years) {
    echo '<div>—</div>';
  } else {
    echo '<div class="d-flex flex-wrap gap-2">';
    foreach ($years as $y) {
      echo '<span class="badge text-bg-light">' . (int)$y . '</span>';
    }
    echo '</div>';
  }
  echo '</div>';

  if (!empty($grouped[$c])) {
    echo '<hr>';
    echo '<div class="fw-semibold mb-2">Registros</div>';
    echo '<div class="list-group list-group-flush">';
    foreach ($grouped[$c] as $r) {
      $line = h((string)$r['season']);
      $dt = (string)($r['achieved_at'] ?? '');
      if ($dt !== '') {
        $line .= ' • ' . h($dt);
      }
      $nt = trim((string)($r['notes'] ?? ''));

      echo '<div class="list-group-item px-0">';
      echo '<div class="d-flex justify-content-between align-items-start gap-3">';
      echo '<div>';
      echo '<div class="fw-semibold">' . $line . '</div>';
      if ($nt !== '') {
        echo '<div class="small text-muted">' . h($nt) . '</div>';
      }
      echo '</div>';
      echo '<div class="text-nowrap">';
      echo '<a class="btn btn-sm btn-primary" href="/?page=trophies&edit=' . (int)$r['id'] . '">Editar</a> ';
      echo '<a class="btn btn-sm btn-danger" href="/?page=trophies&del=' . (int)$r['id'] . '" onclick="return confirm(\'Excluir troféu?\')">Excluir</a>';
      echo '</div>';
      echo '</div>';
      echo '</div>';
    }
    echo '</div>';
  }

  echo '</div>';
  echo '<div class="modal-footer">';
  echo '<button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Fechar</button>';
  echo '</div>';
  echo '</div>';
  echo '</div>';
  echo '</div>';
}

echo '</div>';

/**
 * Linha do tempo
 */
echo '<hr class="my-4">';

echo '<div class="mb-2">';
echo '<div class="fw-bold">Linha do tempo</div>';
echo '<div class="text-muted small">Agrupado por década</div>';
echo '</div>';

if (!$timeline) {
  echo '<div class="text-muted">Sem dados suficientes para montar a linha do tempo.</div>';
} else {
  echo '<div class="accordion" id="timelineAccordion">';
  $i = 0;

  foreach ($timeline as $decade => $items) {
    if ($filterComp !== '') {
      $items = array_values(array_filter($items, fn($it) => $it['competition'] === $filterComp));
      if (!$items) {
        continue;
      }
    }

    $i++;
    $accId = 'acc_' . $i;
    $headingId = 'heading_' . $i;
    $collapseId = 'collapse_' . $i;
    $open = $i === 1 ? 'show' : '';
    $collapsed = $i === 1 ? '' : 'collapsed';
    $aria = $i === 1 ? 'true' : 'false';

    echo '<div class="accordion-item">';
    echo '<h2 class="accordion-header" id="' . h($headingId) . '">';
    echo '<button class="accordion-button ' . $collapsed . '" type="button" data-bs-toggle="collapse" data-bs-target="#' . h($collapseId) . '" aria-expanded="' . $aria . '" aria-controls="' . h($collapseId) . '">';
    echo h($decade) . ' <span class="ms-2 badge text-bg-secondary">' . count($items) . '</span>';
    echo '</button>';
    echo '</h2>';
    echo '<div id="' . h($collapseId) . '" class="accordion-collapse collapse ' . $open . '" data-bs-parent="#timelineAccordion">';
    echo '<div class="accordion-body">';

    foreach ($items as $it) {
      [$local, $fallback] = trophy_img($trophyImages, $it['competition']);

      echo '<div class="d-flex align-items-start gap-3 mb-3">';
      echo '<img src="' . h($local) . '" alt="' . h($it['competition']) . '" width="32" height="32" style="object-fit:contain;" onerror="this.onerror=null;this.src=\'' . h($fallback) . '\';">';
      echo '<div class="flex-grow-1">';
      echo '<div class="fw-semibold">' . (int)$it['year'] . ' • ' . h($it['competition']) . '</div>';
      if ($it['notes'] !== '') {
        echo '<div class="small text-muted">' . h($it['notes']) . '</div>';
      }
      echo '</div>';
      echo '<div class="text-nowrap">';
      echo '<a class="btn btn-sm btn-primary" href="/?page=trophies&edit=' . (int)$it['id'] . '">Editar</a>';
      echo '</div>';
      echo '</div>';
    }

    echo '</div>';
    echo '</div>';
    echo '</div>';
  }

  echo '</div>';
}

echo '</div>';
echo '</div>';

render_footer();