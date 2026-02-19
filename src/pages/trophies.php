<?php
declare(strict_types=1);
require_once __DIR__ . '/../db.php';

$pdo = db();

/**
 * Mapa de imagens (local + fallback Verdazzo).
 * Local: /assets/trophies/<arquivo>.png
 * Fallback: URL do Verdazzo (carrega automaticamente se o PNG local não existir).
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

function trophy_img(array $trophyImages, string $competition): array {
  $local = '/assets/trophies/trophy.png';
  $fallback = '/assets/trophies/trophy.png';
  if (isset($trophyImages[$competition])) {
    $local = '/assets/trophies/' . $trophyImages[$competition]['file'];
    $fallback = $trophyImages[$competition]['fallback'] ?: $local;
  }
  return [$local, $fallback];
}

function season_to_year(string $season): ?int {
  // tenta extrair um ano (4 dígitos) de qualquer formato
  if (preg_match('/(19\d{2}|20\d{2})/', $season, $m)) return (int)$m[1];
  if (preg_match('/^\d{4}$/', $season)) return (int)$season;
  return null;
}

function decade_label(int $year): string {
  $d = (int)(floor($year / 10) * 10);
  return $d . 's';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $id = (int)($_POST['id'] ?? 0);
  $comp = trim((string)($_POST['competition_name'] ?? ''));
  $season = trim((string)($_POST['season'] ?? ''));
  $ach = trim((string)($_POST['achieved_at'] ?? ''));
  $ach = $ach === '' ? null : $ach;
  $notes = trim((string)($_POST['notes'] ?? ''));

  if ($comp !== '' && $season !== '') {
    if ($id > 0) {
      q($pdo, "UPDATE trophies SET competition_name=?, season=?, achieved_at=?, notes=? WHERE id=?", [$comp,$season,$ach,$notes,$id]);
    } else {
      q($pdo, "INSERT INTO trophies(competition_name, season, achieved_at, notes) VALUES (?,?,?,?)", [$comp,$season,$ach,$notes]);
    }
  }
  redirect('/?page=trophies');
}

$edit = null;
if (isset($_GET['edit'])) $edit = q($pdo, "SELECT * FROM trophies WHERE id=?", [(int)$_GET['edit']])->fetch() ?: null;
if (isset($_GET['del'])) { q($pdo, "DELETE FROM trophies WHERE id=?", [(int)$_GET['del']]); redirect('/?page=trophies'); }

// Filtros (GET)
$filterComp = trim((string)($_GET['comp'] ?? ''));

// Carrega dados (com filtro opcional)
if ($filterComp !== '') {
  $rows = q($pdo, "SELECT * FROM trophies WHERE competition_name=? ORDER BY season DESC, achieved_at DESC, id DESC", [$filterComp])->fetchAll();
} else {
  $rows = q($pdo, "SELECT * FROM trophies ORDER BY season DESC, achieved_at DESC, id DESC")->fetchAll();
}

// Agregações
$totalTitles = count($rows);
$grouped = [];            // [competition] => [rows]
$yearsByCompetition = []; // [competition] => [year,...]
$allYears = [];           // para timeline
$latestTitle = null;      // ['competition','season','achieved_at']
foreach ($rows as $r) {
  $c = (string)$r['competition_name'];
  $grouped[$c] = $grouped[$c] ?? [];
  $grouped[$c][] = $r;

  $y = season_to_year((string)$r['season']);
  if ($y !== null) {
    $yearsByCompetition[$c] = $yearsByCompetition[$c] ?? [];
    $yearsByCompetition[$c][] = $y;
    $allYears[] = ['year' => $y, 'competition' => $c, 'season' => (string)$r['season'], 'achieved_at' => (string)($r['achieved_at'] ?? ''), 'notes' => (string)($r['notes'] ?? ''), 'id' => (int)$r['id']];
  }

  // define "último título" pelo achieved_at; se vazio, usa season (ano)
  $candidateScore = 0;
  if (!empty($r['achieved_at'])) {
    $candidateScore = (int)strtotime((string)$r['achieved_at']);
  } else if ($y !== null) {
    // 31/12 do ano como fallback para ordenar
    $candidateScore = (int)strtotime($y . '-12-31');
  }
  if ($latestTitle === null || $candidateScore > $latestTitle['_score']) {
    $latestTitle = [
      '_score' => $candidateScore,
      'competition' => $c,
      'season' => (string)$r['season'],
      'achieved_at' => (string)($r['achieved_at'] ?? ''),
    ];
  }
}

// Normaliza/ordena anos por competição
foreach ($yearsByCompetition as $c => $ys) {
  $ys = array_values(array_unique($ys));
  sort($ys);
  $yearsByCompetition[$c] = $ys;
}

// Timeline por década
$timeline = []; // ['1990s' => [items...]]
usort($allYears, fn($a,$b) => $b['year'] <=> $a['year']);
foreach ($allYears as $it) {
  $label = decade_label($it['year']);
  $timeline[$label] = $timeline[$label] ?? [];
  $timeline[$label][] = $it;
}

render_header('Troféus');

echo '<style>
.trophy-card{cursor:pointer}
.trophy-img{width:64px;height:64px;object-fit:contain}
.trophy-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:12px}
.trophy-years{line-height:1.6}
.trophy-years .badge{margin:2px 4px 2px 0}
</style>';

echo '<div class="row g-3">';

// Coluna esquerda: formulário
echo '<div class="col-lg-4"><div class="card card-soft p-3">';
echo '<div class="d-flex align-items-center justify-content-between mb-2">';
echo '<div class="fw-bold">' . ($edit ? 'Editar troféu' : 'Novo troféu') . '</div>';
echo '<span class="badge text-bg-secondary">Total: ' . (int)$totalTitles . '</span>';
echo '</div>';

if ($latestTitle && $totalTitles > 0) {
  $ltDate = $latestTitle['achieved_at'] !== '' ? $latestTitle['achieved_at'] : ($latestTitle['season'] !== '' ? $latestTitle['season'] : '-');
  echo '<div class="alert alert-light border py-2 mb-3">';
  echo '<div class="small text-muted">Último título</div>';
  echo '<div class="fw-semibold">' . h($latestTitle['competition']) . '</div>';
  echo '<div class="small text-muted">' . h($ltDate) . '</div>';
  echo '</div>';
}

echo '<form method="post" class="vstack gap-2">';
if ($edit) echo '<input type="hidden" name="id" value="' . (int)$edit['id'] . '">';

echo '<div><label class="form-label">Campeonato</label><select class="form-select" name="competition_name" required>';
echo '<option value="">-- selecione --</option>';
foreach ($competitions as $c) {
  $sel = (($edit['competition_name'] ?? '') === $c) ? 'selected' : '';
  echo '<option value="' . h($c) . '" ' . $sel . '>' . h($c) . '</option>';
}
echo '</select></div>';

echo '<div><label class="form-label">Temporada</label><input class="form-control" name="season" required value="' . h($edit['season'] ?? '') . '" placeholder="ex: 2026"></div>';
echo '<div><label class="form-label">Data (opcional)</label><input class="form-control" type="date" name="achieved_at" value="' . h((string)($edit['achieved_at'] ?? '')) . '"></div>';
echo '<div><label class="form-label">Notas</label><textarea class="form-control" rows="3" name="notes">' . h($edit['notes'] ?? '') . '</textarea></div>';

echo '<button class="btn btn-success">Salvar</button>';
if ($edit) echo '<a class="btn btn-outline-secondary" href="/?page=trophies">Cancelar</a>';
echo '</form></div></div>';

// Coluna direita: tabela + filtros + galeria visual
echo '<div class="col-lg-8"><div class="card card-soft p-3">';

// Filtros
echo '<div class="d-flex flex-wrap gap-2 align-items-end justify-content-between mb-2">';
echo '<div class="fw-bold">Galeria</div>';

echo '<form method="get" class="d-flex flex-wrap gap-2 align-items-end">';
echo '<input type="hidden" name="page" value="trophies">';
echo '<div>';
echo '<label class="form-label small mb-1">Filtrar por campeonato</label>';
echo '<select class="form-select form-select-sm" name="comp" onchange="this.form.submit()">';
echo '<option value="">(todos)</option>';
foreach ($competitions as $c) {
  $sel = ($filterComp === $c) ? 'selected' : '';
  echo '<option value="' . h($c) . '" ' . $sel . '>' . h($c) . '</option>';
}
echo '</select></div>';
echo '<div class="pb-1">';
echo '<a class="btn btn-sm btn-outline-secondary" href="/?page=trophies">Limpar</a>';
echo '</div>';
echo '</form>';
echo '</div>';

// Tabela
echo '<div class="table-responsive"><table class="table table-sm align-middle mb-0">';
echo '<thead><tr><th>Competição</th><th>Temporada</th><th>Data</th><th>Notas</th><th></th></tr></thead><tbody>';
foreach ($rows as $r) {
  echo '<tr>';
  echo '<td>' . h($r['competition_name']) . '</td>';
  echo '<td>' . h($r['season']) . '</td>';
  echo '<td>' . h((string)($r['achieved_at'] ?? '')) . '</td>';
  echo '<td class="text-muted">' . h((string)($r['notes'] ?? '')) . '</td>';
  echo '<td class="text-end">';
  echo '<a class="btn btn-sm btn-outline-primary" href="/?page=trophies&edit=' . (int)$r['id'] . '">Editar</a> ';
  echo '<a class="btn btn-sm btn-outline-danger" href="/?page=trophies&del=' . (int)$r['id'] . '" onclick="return confirm(\'Excluir?\')">Excluir</a>';
  echo '</td>';
  echo '</tr>';
}
echo '</tbody></table></div>';

// Galeria visual (cards)
echo '<hr class="my-3">';
echo '<div class="d-flex align-items-center justify-content-between mb-2">';
echo '<div class="fw-bold">Galeria visual</div>';
echo '<div class="text-muted small">Clique em um troféu para ver detalhes</div>';
echo '</div>';

echo '<div class="trophy-grid">';
foreach ($competitions as $c) {
  if ($filterComp !== '' && $filterComp !== $c) continue;

  $count = isset($grouped[$c]) ? count($grouped[$c]) : 0;
  $years = $yearsByCompetition[$c] ?? [];
  $yearsText = $years ? implode(', ', $years) : '—';

  [$local, $fallback] = trophy_img($trophyImages, $c);
  $modalId = 'modal_' . substr(md5($c), 0, 10);

  echo '<div class="card card-soft p-3 trophy-card" data-bs-toggle="modal" data-bs-target="#' . h($modalId) . '">';
  echo '<div class="d-flex gap-3 align-items-center">';
  echo '<img class="trophy-img" src="' . h($local) . '" onerror="this.onerror=null;this.src=\'' . h($fallback) . '\';" alt="' . h($c) . '">';
  echo '<div class="flex-grow-1">';
  echo '<div class="d-flex align-items-center justify-content-between">';
  echo '<div class="fw-semibold">' . h($c) . '</div>';
  echo '<span class="badge text-bg-success">x' . (int)$count . '</span>';
  echo '</div>';
  echo '<div class="small text-muted trophy-years">Conquistas: ' . h($yearsText) . '</div>';
  echo '</div>';
  echo '</div>';
  echo '</div>';

  // Modal
  echo '<div class="modal fade" id="' . h($modalId) . '" tabindex="-1" aria-hidden="true">';
  echo '  <div class="modal-dialog modal-dialog-centered">';
  echo '    <div class="modal-content">';
  echo '      <div class="modal-header">';
  echo '        <h5 class="modal-title">' . h($c) . '</h5>';
  echo '        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>';
  echo '      </div>';
  echo '      <div class="modal-body">';
  echo '        <div class="d-flex gap-3 align-items-center mb-2">';
  echo '          <img class="trophy-img" src="' . h($local) . '" onerror="this.onerror=null;this.src=\'' . h($fallback) . '\';" alt="' . h($c) . '">';
  echo '          <div>';
  echo '            <div class="text-muted small">Títulos</div>';
  echo '            <div class="fw-bold fs-4">x' . (int)$count . '</div>';
  echo '          </div>';
  echo '        </div>';

  echo '        <div class="text-muted small mb-1">Conquistas</div>';
  if (!$years) {
    echo '        <div>—</div>';
  } else {
    echo '        <div class="trophy-years">';
    foreach ($years as $y) {
      echo '<span class="badge text-bg-light border">' . (int)$y . '</span>';
    }
    echo '        </div>';
  }

  // lista detalhada dos registros desse campeonato
  if (!empty($grouped[$c])) {
    echo '        <hr>';
    echo '        <div class="text-muted small mb-2">Registros</div>';
    echo '        <div class="vstack gap-2">';
    foreach ($grouped[$c] as $r) {
      $line = h((string)$r['season']);
      $dt = (string)($r['achieved_at'] ?? '');
      if ($dt !== '') $line .= ' • ' . h($dt);
      $nt = trim((string)($r['notes'] ?? ''));
      echo '          <div class="d-flex justify-content-between align-items-start">';
      echo '            <div>';
      echo '              <div class="fw-semibold">' . $line . '</div>';
      if ($nt !== '') echo '              <div class="small text-muted">' . h($nt) . '</div>';
      echo '            </div>';
      echo '            <div class="text-end">';
      echo '              <a class="btn btn-sm btn-outline-primary" href="/?page=trophies&edit=' . (int)$r['id'] . '">Editar</a> ';
      echo '              <a class="btn btn-sm btn-outline-danger" href="/?page=trophies&del=' . (int)$r['id'] . '" onclick="return confirm(\'Excluir?\')">Excluir</a>';
      echo '            </div>';
      echo '          </div>';
    }
    echo '        </div>';
  }

  echo '      </div>';
  echo '      <div class="modal-footer">';
  echo '        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Fechar</button>';
  echo '      </div>';
  echo '    </div>';
  echo '  </div>';
  echo '</div>';
}
echo '</div>';

// Timeline
echo '<hr class="my-3">';
echo '<div class="d-flex align-items-center justify-content-between mb-2">';
echo '<div class="fw-bold">Linha do tempo</div>';
echo '<div class="text-muted small">Agrupado por década</div>';
echo '</div>';

if (!$timeline) {
  echo '<div class="text-muted">Sem dados suficientes para montar a linha do tempo (informe a temporada com ano).</div>';
} else {
  echo '<div class="accordion" id="trophyTimeline">';
  $i = 0;
  foreach ($timeline as $decade => $items) {
    $i++;
    $accId = 'acc_' . $i;
    $open = $i === 1 ? 'show' : '';
    $collapsed = $i === 1 ? '' : 'collapsed';
    $aria = $i === 1 ? 'true' : 'false';

    // aplica filtro de competição, se houver
    if ($filterComp !== '') {
      $items = array_values(array_filter($items, fn($it) => $it['competition'] === $filterComp));
      if (!$items) continue;
    }

    echo '<div class="accordion-item">';
    echo '  <h2 class="accordion-header" id="' . h($accId) . '_h">';
    echo '    <button class="accordion-button ' . h($collapsed) . '" type="button" data-bs-toggle="collapse" data-bs-target="#' . h($accId) . '" aria-expanded="' . h($aria) . '" aria-controls="' . h($accId) . '">';
    echo '      ' . h($decade) . ' <span class="badge text-bg-secondary ms-2">' . count($items) . '</span>';
    echo '    </button>';
    echo '  </h2>';
    echo '  <div id="' . h($accId) . '" class="accordion-collapse collapse ' . h($open) . '" aria-labelledby="' . h($accId) . '_h" data-bs-parent="#trophyTimeline">';
    echo '    <div class="accordion-body">';
    echo '      <div class="vstack gap-2">';
    foreach ($items as $it) {
      [$local, $fallback] = trophy_img($trophyImages, $it['competition']);
      $line = (int)$it['year'] . ' • ' . h($it['competition']);
      echo '        <div class="d-flex gap-3 align-items-center">';
      echo '          <img style="width:28px;height:28px;object-fit:contain" src="' . h($local) . '" onerror="this.onerror=null;this.src=\'' . h($fallback) . '\';" alt="">';
      echo '          <div class="flex-grow-1">';
      echo '            <div class="fw-semibold">' . $line . '</div>';
      if ($it['notes'] !== '') echo '            <div class="small text-muted">' . h($it['notes']) . '</div>';
      echo '          </div>';
      echo '          <div class="text-end">';
      echo '            <a class="btn btn-sm btn-outline-primary" href="/?page=trophies&edit=' . (int)$it['id'] . '">Editar</a>';
      echo '          </div>';
      echo '        </div>';
    }
    echo '      </div>';
    echo '    </div>';
    echo '  </div>';
    echo '</div>';
  }
  echo '</div>';
}

echo '</div></div>'; // fecha card e col

echo '</div>'; // fecha row

render_footer();
