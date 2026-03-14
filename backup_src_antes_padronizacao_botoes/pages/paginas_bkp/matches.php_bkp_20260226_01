<?php
declare(strict_types=1);

require_once __DIR__ . '/../db.php';

$pdo  = db();
$club = app_club();

$action = (string)($_GET['action'] ?? '');
$msg    = (string)($_GET['msg'] ?? '');
$err    = (string)($_GET['err'] ?? '');

/**
 * EXCLUIR PARTIDA (POST)
 * Endpoint: /?page=matches&action=delete
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'delete') {
  $matchId = (int)($_POST['match_id'] ?? 0);

  if ($matchId <= 0) {
    redirect('/?page=matches&err=invalid');
  }

  // Garante que a partida existe e envolve o clube atual
  $m = q($pdo, "SELECT id, home, away FROM matches WHERE id = ? LIMIT 1", [$matchId])->fetch();
  if (!$m) {
    redirect('/?page=matches&err=not_found');
  }
  if ((string)$m['home'] !== $club && (string)$m['away'] !== $club) {
    redirect('/?page=matches&err=not_allowed');
  }

  $pdo->beginTransaction();
  try {
    // Apaga dependências primeiro
    q($pdo, "DELETE FROM match_player_stats WHERE match_id = ?", [$matchId]);
    q($pdo, "DELETE FROM match_players      WHERE match_id = ?", [$matchId]);

    // Apaga a partida
    q($pdo, "DELETE FROM matches WHERE id = ?", [$matchId]);

    $pdo->commit();
    redirect('/?page=matches&msg=deleted');
  } catch (Throwable $e) {
    $pdo->rollBack();
    redirect('/?page=matches&err=delete_failed');
  }
}

render_header('Partidas');

// Mensagens
if ($msg === 'saved') {
  echo '<div class="alert alert-success card-soft">Partida cadastrada com sucesso.</div>';
}
if ($msg === 'deleted') {
  echo '<div class="alert alert-success card-soft">Partida excluída com sucesso.</div>';
}
if ($err === 'invalid') {
  echo '<div class="alert alert-warning card-soft">Requisição inválida.</div>';
} elseif ($err === 'not_found') {
  echo '<div class="alert alert-warning card-soft">Partida não encontrada.</div>';
} elseif ($err === 'not_allowed') {
  echo '<div class="alert alert-warning card-soft">Você não pode excluir uma partida que não envolve o clube atual.</div>';
} elseif ($err === 'delete_failed') {
  echo '<div class="alert alert-danger card-soft">Falha ao excluir a partida. Tente novamente.</div>';
}

// Lista partidas (histórico) - trazendo season e ordenando para agrupar
$rows = q($pdo, "
  SELECT
    id,
    season,
    competition,
    match_date,
    home,
    away,
    home_score,
    away_score
  FROM matches
  ORDER BY
    (CASE WHEN season IS NULL OR season = '' THEN 0 ELSE 1 END) DESC,
    season DESC,
    match_date DESC,
    id DESC
")->fetchAll();

// Header da página
echo '<div class="d-flex justify-content-between align-items-center mb-3">';
echo '  <h4 class="mb-0">Histórico</h4>';
echo '  <a class="btn btn-success" href="index.php?page=create_match">Cadastrar partida</a>';
echo '</div>';

if (!$rows) {
  echo '<div class="card-soft"><div class="text-muted">Nenhuma partida cadastrada.</div></div>';
  render_footer();
  exit;
}

/**
 * Helper: normaliza rótulo de temporada para STRING sempre.
 */
function season_label($v): string {
  if ($v === null) return 'Sem temporada';
  if (is_string($v)) {
    $t = trim($v);
    return $t !== '' ? $t : 'Sem temporada';
  }
  // season pode vir como int/float (ex.: 2026)
  if (is_int($v) || is_float($v)) return (string)$v;
  // qualquer outro tipo improvável
  $s = trim((string)$v);
  return $s !== '' ? $s : 'Sem temporada';
}

/**
 * Helper: cria chave segura (string) para data-attr/localStorage.
 */
function season_key(string $seasonLabel): string {
  $s = strtolower($seasonLabel);
  $s = preg_replace('/[^a-z0-9]+/i', '_', $s); // <-- agora sempre string
  $s = trim($s ?? '', '_');
  return $s !== '' ? $s : 'sem_temporada';
}

// Agrupar por temporada
$grouped = [];
foreach ($rows as $r) {
  $seasonLabel = season_label($r['season'] ?? null); // STRING garantida
  if (!isset($grouped[$seasonLabel])) $grouped[$seasonLabel] = [];
  $grouped[$seasonLabel][] = $r;
}

// Ordenar temporadas: numéricas desc; "Sem temporada" por último
$seasonLabels = array_keys($grouped);
usort($seasonLabels, function ($a, $b) {
  $a = (string)$a;
  $b = (string)$b;

  if ($a === 'Sem temporada' && $b !== 'Sem temporada') return 1;
  if ($b === 'Sem temporada' && $a !== 'Sem temporada') return -1;

  $na = preg_match('/^\d+$/', $a) ? (int)$a : null;
  $nb = preg_match('/^\d+$/', $b) ? (int)$b : null;

  if ($na !== null && $nb !== null) return $nb <=> $na;
  return strcasecmp($b, $a);
});

// CSS mínimo pro “accordion”
?>
<style>
  .season-card { padding: 0; overflow: hidden; }
  .season-head {
    padding: 12px 14px;
    cursor: pointer;
    user-select: none;
  }
  .season-head:focus { outline: 2px solid rgba(255,255,255,.18); outline-offset: -2px; }
  .season-caret { opacity: .85; transition: transform .15s ease; }
  .season-head[aria-expanded="false"] .season-caret { transform: rotate(-90deg); }
  .season-body { padding: 0 0 8px 0; }
  .season-body[hidden] { display: none !important; }
  .season-title { font-weight: 700; }
  .season-sub { opacity: .75; font-size: 12px; }
</style>

<?php
// Renderizar cada temporada como uma sessão expansível, com tabela completa (thead dentro)
foreach ($seasonLabels as $seasonLabel) {
  $seasonLabel = (string)$seasonLabel;                 // reforço
  $seasonRows  = $grouped[$seasonLabel] ?? [];
  $key         = season_key($seasonLabel);             // <-- SEM ERRO (string)

  $count = count($seasonRows);

  echo '<div class="card-soft season-card mb-3">';

  // Cabeçalho clicável da temporada
  echo '  <div class="season-head d-flex justify-content-between align-items-center table-active"';
  echo '       data-season="' . h($key) . '" role="button" tabindex="0" aria-expanded="true">';
  echo '    <div>';
  echo '      <div class="season-title">Temporada ' . h($seasonLabel) . '</div>';
  echo '      <div class="season-sub">' . h((string)$count) . ' partida(s)</div>';
  echo '    </div>';
  echo '    <span class="season-caret" aria-hidden="true">▾</span>';
  echo '  </div>';

  // Corpo expansível
  echo '  <div class="season-body" data-season-body="' . h($key) . '">';
  echo '    <div class="table-responsive">';
  echo '      <table class="table table-sm mb-0">';
  echo '        <thead><tr>';
  echo '          <th>Data</th>';
  echo '          <th>Temporada</th>';
  echo '          <th>Campeonato</th>';
  echo '          <th>Jogo</th>';
  echo '          <th>Placar</th>';
  echo '          <th class="text-end">Ações</th>';
  echo '        </tr></thead>';
  echo '        <tbody>';

  foreach ($seasonRows as $r) {
    $id   = (int)$r['id'];
    $date = (string)$r['match_date'];
    $comp = (string)$r['competition'];
    $home = (string)$r['home'];
    $away = (string)$r['away'];

    $hs = $r['home_score'];
    $as = $r['away_score'];
    $score = (($hs === null || $hs === '') || ($as === null || $as === '')) ? '-' : ((int)$hs . ' x ' . (int)$as);

    echo '<tr>';
    echo '<td>' . h($date) . '</td>';
    echo '<td>' . h($seasonLabel) . '</td>';
    echo '<td>' . h($comp) . '</td>';
    echo '<td><b>' . h($home) . '</b> vs ' . h($away) . '</td>';
    echo '<td>' . h($score) . '</td>';

    echo '<td class="text-end" style="white-space:nowrap;">';
    echo '  <a class="btn btn-sm btn-outline-primary" href="/?page=match&id=' . $id . '">Abrir</a> ';
    echo '  <a class="btn btn-sm btn-outline-warning" href="/?page=create_match&id=' . $id . '">Editar</a> ';
    echo '  <form method="post" action="/?page=matches&action=delete" style="display:inline-block;" onsubmit="return confirm(\'Excluir esta partida? Essa ação não pode ser desfeita.\')">';
    echo '    <input type="hidden" name="match_id" value="' . $id . '">';
    echo '    <button class="btn btn-sm btn-outline-danger" type="submit">Excluir</button>';
    echo '  </form>';
    echo '</td>';

    echo '</tr>';
  }

  echo '        </tbody>';
  echo '      </table>';
  echo '    </div>';
  echo '  </div>';

  echo '</div>';
}

// JS: expandir/fechar + persistência
?>
<script>
(function () {
  const STORAGE_KEY = 'matches_season_states';

  function loadStates() {
    try { return JSON.parse(localStorage.getItem(STORAGE_KEY) || '{}'); }
    catch (e) { return {}; }
  }
  function saveStates(states) {
    try { localStorage.setItem(STORAGE_KEY, JSON.stringify(states)); } catch (e) {}
  }

  function setExpanded(seasonKey, expanded) {
    const head = document.querySelector('.season-head[data-season="' + seasonKey + '"]');
    const body = document.querySelector('[data-season-body="' + seasonKey + '"]');
    if (!head || !body) return;

    head.setAttribute('aria-expanded', expanded ? 'true' : 'false');
    if (expanded) body.removeAttribute('hidden');
    else body.setAttribute('hidden', 'hidden');
  }

  function toggle(seasonKey) {
    const head = document.querySelector('.season-head[data-season="' + seasonKey + '"]');
    if (!head) return;
    const expanded = head.getAttribute('aria-expanded') === 'true';
    setExpanded(seasonKey, !expanded);

    const states = loadStates();
    states[seasonKey] = !expanded ? 'open' : 'closed';
    saveStates(states);
  }

  document.addEventListener('click', function (e) {
    const head = e.target.closest && e.target.closest('.season-head[data-season]');
    if (!head) return;
    toggle(head.getAttribute('data-season'));
  });

  document.addEventListener('keydown', function (e) {
    const head = e.target.closest && e.target.closest('.season-head[data-season]');
    if (!head) return;
    if (e.key === 'Enter' || e.key === ' ') {
      e.preventDefault();
      toggle(head.getAttribute('data-season'));
    }
  });

  document.addEventListener('DOMContentLoaded', function () {
    const states = loadStates();
    document.querySelectorAll('.season-head[data-season]').forEach(function (head) {
      const key = head.getAttribute('data-season');
      const st = states[key];
      if (st === 'closed') setExpanded(key, false);
      else setExpanded(key, true);
    });
  });
})();
</script>

<?php
render_footer();