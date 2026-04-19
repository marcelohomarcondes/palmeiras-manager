<?php
declare(strict_types=1);

$pdo    = db();
$userId = require_user_id();

// ─────────────────────────────────────────────
// CSRF
// ─────────────────────────────────────────────
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token'];

function csrf_verify(): void
{
    $token = trim((string)($_POST['csrf_token'] ?? ''));
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
        http_response_code(403);
        die('Requisição inválida (CSRF).');
    }
}

// ─────────────────────────────────────────────
// Mapeamento de troféus
// ─────────────────────────────────────────────
$trophyImages = [
    'Paulistão Casas Bahia'          => ['file' => 'paulista.png',          'fallback' => 'https://www.verdazzo.com.br/wp-content/uploads/2016/11/sp2024-44x44.png'],
    'Brasileirão Betano'             => ['file' => 'brasileiro.png',         'fallback' => 'https://www.verdazzo.com.br/wp-content/uploads/2020/06/2016-brasileiro-44x44.png'],
    'Copa Betano do Brasil'          => ['file' => 'copa-do-brasil.png',     'fallback' => 'https://www.verdazzo.com.br/wp-content/uploads/2020/06/2015-copa-do-brasil-44x44.png'],
    'CONMEBOL Libertadores'          => ['file' => 'libertadores.png',       'fallback' => 'https://www.verdazzo.com.br/wp-content/uploads/2020/06/1999-libertadores-44x44.png'],
    'CONMEBOL Sul-Americana'         => ['file' => 'sul-americana.png',      'fallback' => 'https://www.verdazzo.com.br/wp-content/uploads/2016/09/tr_sulamericana1-44x44.png'],
    'CONMEBOL Recopa'                => ['file' => 'recopa.png',             'fallback' => 'https://www.verdazzo.com.br/wp-content/uploads/2021/01/recopa-44x44.png'],
    'Supercopa do Brasil'            => ['file' => 'supercopa.png',          'fallback' => 'https://www.verdazzo.com.br/wp-content/uploads/2021/03/supercopa2020-44x44.png'],
    'Intercontinental FIFA'          => ['file' => 'intercontinental.png',   'fallback' => 'https://www.verdazzo.com.br/wp-content/uploads/2016/09/mundial-44x44.png'],
    'Copa do Mundo de Clubes da FIFA'=> ['file' => 'mundial-de-clubes.png',  'fallback' => 'https://www.verdazzo.com.br/wp-content/uploads/2025/06/clubwc-44x44.png'],
];

$competitions = array_keys($trophyImages);

// ─────────────────────────────────────────────
// Helpers
// ─────────────────────────────────────────────

/** Retorna colunas da tabela (com cache estático). */
function pm_table_columns(PDO $pdo, string $table): array
{
    static $cache = [];
    if (!isset($cache[$table])) {
        $cache[$table] = array_column(
            $pdo->query("PRAGMA table_info($table)")->fetchAll(PDO::FETCH_ASSOC),
            'name'
        );
    }
    return $cache[$table];
}

/** Invalida o cache de colunas após ALTER TABLE. */
function pm_invalidate_columns_cache(string $table): void
{
    static $cache = [];   // mesma variável estática de pm_table_columns
    // Acessa via referência indireta chamando a função com um PDO dummy não é possível,
    // então usamos uma flag global simples.
    $GLOBALS['_pm_cols_cache_dirty'][$table] = true;
}

/**
 * Garante que o schema da tabela trophies esteja atualizado.
 * Executado apenas uma vez por requisição (flag estática).
 */
function pm_ensure_trophies_schema(PDO $pdo, int $userId): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    $exec = static function (PDO $pdo, string $sql) use (&$exec): void {
        $pdo->exec($sql);
        // Limpa cache de colunas após DDL
        $GLOBALS['_pm_cols_cache_dirty']['trophies'] = true;
    };

    // Recarrega colunas frescas (ignora cache se sujo)
    $cols = fn() => array_column(
        $pdo->query("PRAGMA table_info(trophies)")->fetchAll(PDO::FETCH_ASSOC),
        'name'
    );

    $c = $cols();

    if (!in_array('user_id', $c, true)) {
        $exec($pdo, "ALTER TABLE trophies ADD COLUMN user_id INTEGER NOT NULL DEFAULT 1");
        $c = $cols();
    }

    if (!in_array('notes', $c, true)) {
        $exec($pdo, "ALTER TABLE trophies ADD COLUMN notes TEXT");
        $c = $cols();
    }

    if (!in_array('competition_name', $c, true)) {
        $exec($pdo, "ALTER TABLE trophies ADD COLUMN competition_name TEXT");
        $c = $cols();
        if (in_array('competition', $c, true)) {
            $pdo->exec("UPDATE trophies SET competition_name = competition WHERE (competition_name IS NULL OR competition_name = '') AND competition IS NOT NULL AND competition <> ''");
        }
    }

    if (!in_array('achieved_at', $c, true)) {
        $exec($pdo, "ALTER TABLE trophies ADD COLUMN achieved_at TEXT");
        $c = $cols();
        if (in_array('title_date', $c, true)) {
            $pdo->exec("UPDATE trophies SET achieved_at = title_date WHERE (achieved_at IS NULL OR achieved_at = '') AND title_date IS NOT NULL AND title_date <> ''");
        }
    }

    // Sincroniza colunas legadas → novas
    $c = $cols();
    if (in_array('competition', $c, true)) {
        $pdo->exec("UPDATE trophies SET competition_name = competition WHERE (competition_name IS NULL OR competition_name = '') AND competition IS NOT NULL AND competition <> ''");
    }
    if (in_array('title_date', $c, true)) {
        $pdo->exec("UPDATE trophies SET achieved_at = title_date WHERE (achieved_at IS NULL OR achieved_at = '') AND title_date IS NOT NULL AND title_date <> ''");
    }

    // Vincula registros órfãos ao usuário atual
    $pdo->prepare("UPDATE trophies SET user_id = :uid WHERE user_id IS NULL OR user_id = 0")
        ->execute([':uid' => $userId]);
}

/** Retorna [src_local, src_fallback] para a imagem do troféu. */
function trophy_img(array $trophyImages, string $competition): array
{
    $default = '/assets/trophies/trophy.png';
    if (!isset($trophyImages[$competition])) {
        return [$default, $default];
    }
    $local    = '/assets/trophies/' . $trophyImages[$competition]['file'];
    $fallback = $trophyImages[$competition]['fallback'] ?: $local;
    return [$local, $fallback];
}

/** Extrai o ano de uma string de temporada. */
function season_to_year(string $season): ?int
{
    if (preg_match('/(19\d{2}|20\d{2})/', $season, $m)) {
        return (int)$m[1];
    }
    return null;
}

/** Rótulo de década (ex: 2020 → "2020s"). */
function decade_label(int $year): string
{
    return ((int)(floor($year / 10) * 10)) . 's';
}

/** Renderiza botões de ação (editar / excluir) reutilizáveis. */
function trophy_actions(int $id, string $csrfToken): string
{
    $editUrl = '/?page=trophies&edit=' . $id;
    return sprintf(
        '<a class="btn btn-sm btn-primary" href="%s">Editar</a> '
        . '<a class="btn btn-sm btn-danger" href="/?page=trophies&del=%d&csrf=%s" '
        . 'onclick="return confirm(\'Excluir troféu?\')">Excluir</a>',
        h($editUrl),
        $id,
        urlencode($csrfToken)
    );
}

// ─────────────────────────────────────────────
// Schema
// ─────────────────────────────────────────────
pm_ensure_trophies_schema($pdo, $userId);

// ─────────────────────────────────────────────
// Detecta colunas legadas (uma única vez)
// ─────────────────────────────────────────────
$cols           = pm_table_columns($pdo, 'trophies');
$hasCompetition = in_array('competition', $cols, true);
$hasTitleDate   = in_array('title_date', $cols, true);

// ─────────────────────────────────────────────
// Ações: POST (salvar)
// ─────────────────────────────────────────────
$err  = '';
$edit = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    $id     = (int)($_POST['id'] ?? 0);
    $comp   = trim((string)($_POST['competition_name'] ?? ''));
    $season = trim((string)($_POST['season'] ?? ''));
    $ach    = trim((string)($_POST['achieved_at'] ?? '')) ?: null;
    $notes  = trim((string)($_POST['notes'] ?? ''));

    if ($comp === '') {
        $err = 'Selecione o campeonato.';
    } elseif ($season === '' || !preg_match('/^\d{4}(\/\d{2,4})?$/', $season)) {
        $err = 'Temporada inválida. Use o formato: 2024 ou 2024/25.';
    } else {
        // Monta campos extras para compatibilidade com schema legado
        $extraCols  = $hasCompetition && $hasTitleDate ? ', competition = :competition, title_date = :title_date' : '';
        $extraBind  = $hasCompetition && $hasTitleDate ? [':competition' => $comp, ':title_date' => $ach] : [];

        if ($id > 0) {
            $stmt = $pdo->prepare(
                "UPDATE trophies
                    SET competition_name = :competition_name,
                        season           = :season,
                        achieved_at      = :achieved_at,
                        notes            = :notes
                        $extraCols
                  WHERE id = :id AND user_id = :user_id"
            );
            $stmt->execute(array_merge([
                ':competition_name' => $comp,
                ':season'           => $season,
                ':achieved_at'      => $ach,
                ':notes'            => $notes,
                ':id'               => $id,
                ':user_id'          => $userId,
            ], $extraBind));
        } else {
            $extraInsertCols = $hasCompetition && $hasTitleDate ? ', competition, title_date' : '';
            $extraInsertVals = $hasCompetition && $hasTitleDate ? ', :competition, :title_date' : '';

            $stmt = $pdo->prepare(
                "INSERT INTO trophies
                   (user_id, competition_name, season, achieved_at, notes $extraInsertCols)
                 VALUES
                   (:user_id, :competition_name, :season, :achieved_at, :notes $extraInsertVals)"
            );
            $stmt->execute(array_merge([
                ':user_id'          => $userId,
                ':competition_name' => $comp,
                ':season'           => $season,
                ':achieved_at'      => $ach,
                ':notes'            => $notes,
            ], $extraBind));
        }

        redirect('/?page=trophies');
    }
}

// ─────────────────────────────────────────────
// Ação: editar (GET)
// ─────────────────────────────────────────────
if (isset($_GET['edit'])) {
    $stmt = $pdo->prepare("SELECT * FROM trophies WHERE id = :id AND user_id = :uid LIMIT 1");
    $stmt->execute([':id' => (int)$_GET['edit'], ':uid' => $userId]);
    $edit = $stmt->fetch() ?: null;
}

// ─────────────────────────────────────────────
// Ação: excluir (GET + token CSRF)
// ─────────────────────────────────────────────
if (isset($_GET['del'])) {
    $getToken = trim((string)($_GET['csrf'] ?? ''));
    if (!hash_equals($csrfToken, $getToken)) {
        http_response_code(403);
        die('Requisição inválida (CSRF).');
    }
    $stmt = $pdo->prepare("DELETE FROM trophies WHERE id = :id AND user_id = :uid");
    $stmt->execute([':id' => (int)$_GET['del'], ':uid' => $userId]);
    redirect('/?page=trophies');
}

// ─────────────────────────────────────────────
// Filtro + listagem
// ─────────────────────────────────────────────
$filterComp = trim((string)($_GET['comp'] ?? ''));

if ($filterComp !== '') {
    $stmt = $pdo->prepare(
        "SELECT * FROM trophies
          WHERE user_id = :uid AND competition_name = :comp
          ORDER BY season DESC, achieved_at DESC, id DESC"
    );
    $stmt->execute([':uid' => $userId, ':comp' => $filterComp]);
} else {
    $stmt = $pdo->prepare(
        "SELECT * FROM trophies
          WHERE user_id = :uid
          ORDER BY season DESC, achieved_at DESC, id DESC"
    );
    $stmt->execute([':uid' => $userId]);
}
$rows = $stmt->fetchAll();

// ─────────────────────────────────────────────
// Agregações (SQL para contagens, PHP para agrupamento)
// ─────────────────────────────────────────────
$totalTitles = count($rows);
$grouped     = [];
$yearsByComp = [];
$allYears    = [];
$latestTitle = null;

foreach ($rows as $r) {
    $c = (string)($r['competition_name'] ?? '');
    if ($c === '') {
        continue;
    }

    $grouped[$c][] = $r;

    $y = season_to_year((string)($r['season'] ?? ''));
    if ($y !== null) {
        $yearsByComp[$c][] = $y;
        $allYears[] = [
            'year'        => $y,
            'competition' => $c,
            'season'      => (string)($r['season'] ?? ''),
            'achieved_at' => (string)($r['achieved_at'] ?? ''),
            'notes'       => (string)($r['notes'] ?? ''),
            'id'          => (int)$r['id'],
        ];
    }

    // Determina o título mais recente
    $score = !empty($r['achieved_at'])
        ? (int)strtotime((string)$r['achieved_at'])
        : ($y !== null ? (int)strtotime($y . '-12-31') : 0);

    if ($latestTitle === null || $score > $latestTitle['_score']) {
        $latestTitle = [
            '_score'      => $score,
            'competition' => $c,
            'season'      => (string)($r['season'] ?? ''),
            'achieved_at' => (string)($r['achieved_at'] ?? ''),
        ];
    }
}

// Ordena e deduplica anos por competição
foreach ($yearsByComp as $c => $ys) {
    $ys = array_values(array_unique($ys));
    sort($ys);
    $yearsByComp[$c] = $ys;
}

// Monta timeline agrupada por década
usort($allYears, fn($a, $b) => $b['year'] <=> $a['year']);
$timeline = [];
foreach ($allYears as $it) {
    $timeline[decade_label($it['year'])][] = $it;
}

// ─────────────────────────────────────────────
// Render
// ─────────────────────────────────────────────
render_header('Troféus');

echo '<div class="row g-3">';

// ── Coluna esquerda (formulário) ──────────────
echo '<div class="col-lg-4 col-xl-3">';
echo '<div class="card card-soft p-3">';

echo '<div class="d-flex justify-content-between align-items-start mb-3">';
echo '<div class="fw-bold">' . ($edit ? 'Editar troféu' : 'Novo troféu') . '</div>';
echo '<span class="badge text-bg-success">Total: ' . $totalTitles . '</span>';
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
echo '<input type="hidden" name="csrf_token" value="' . h($csrfToken) . '">';
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
echo '<input class="form-control" name="season" required value="' . h((string)($edit['season'] ?? '')) . '" placeholder="ex: 2026 ou 2025/26">';
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

// ── Coluna direita ────────────────────────────
echo '<div class="col-lg-8 col-xl-9">';
echo '<div class="card card-soft p-3">';

// Filtros
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

// Tabela histórico
echo '<div class="table-responsive mb-4">';
echo '<table class="table table-sm align-middle mb-0">';
echo '<thead><tr>';
echo '<th>Competição</th><th>Temporada</th><th>Data</th><th>Notas</th><th class="text-end">Ações</th>';
echo '</tr></thead>';
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
        echo '<td class="text-end">' . trophy_actions((int)$r['id'], $csrfToken) . '</td>';
        echo '</tr>';
    }
}

echo '</tbody></table>';
echo '</div>';

// Galeria visual
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

    $count    = isset($grouped[$c]) ? count($grouped[$c]) : 0;
    $years    = $yearsByComp[$c] ?? [];
    $yearsText = $years ? implode(', ', $years) : '—';
    [$local, $fallback] = trophy_img($trophyImages, $c);
    $modalId  = 'modal_' . substr(md5($c), 0, 10);

    // Card
    echo '<div class="col-md-6 col-xl-4">';
    echo '<div class="card h-100 border-0 shadow-sm">';
    echo '<button type="button" class="btn text-start p-0 border-0 bg-transparent" data-bs-toggle="modal" data-bs-target="#' . h($modalId) . '">';
    echo '<div class="card-body d-flex align-items-center gap-3">';
    echo '<img src="' . h($local) . '" alt="' . h($c) . '" width="44" height="44" style="object-fit:contain;" onerror="this.onerror=null;this.src=\'' . h($fallback) . '\';">';
    echo '<div class="flex-grow-1">';
    echo '<div class="fw-semibold">' . h($c) . '</div>';
    if ($count > 0) {
        echo '<div class="small text-muted">x' . $count . '</div>';
        echo '<div class="small text-muted">Anos: ' . h($yearsText) . '</div>';
    } else {
        echo '<div class="small text-muted fst-italic">Sem títulos</div>';
    }
    echo '</div>';
    echo '</div>';
    echo '</button>';
    echo '</div>';
    echo '</div>';

    // Modal
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
    echo '<div class="fw-semibold">x' . $count . '</div>';
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
            $dt   = (string)($r['achieved_at'] ?? '');
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
            echo '<div class="text-nowrap">' . trophy_actions((int)$r['id'], $csrfToken) . '</div>';
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

// Linha do tempo
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
        $collapseId = 'collapse_' . $i;
        $headingId  = 'heading_' . $i;
        $open       = $i === 1 ? 'show' : '';
        $collapsed  = $i === 1 ? '' : 'collapsed';
        $aria       = $i === 1 ? 'true' : 'false';

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
            echo '<div class="text-nowrap"><a class="btn btn-sm btn-primary" href="/?page=trophies&edit=' . (int)$it['id'] . '">Editar</a></div>';
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
echo '</div>';

echo '<script>
document.addEventListener("DOMContentLoaded", function () {
    // Move modais para o body para evitar problemas de z-index/backdrop
    document.querySelectorAll(".modal").forEach(function (el) {
        if (el.parentNode !== document.body) {
            document.body.appendChild(el);
        }
    });

    // Limpeza de backdrop ao fechar modal
    document.addEventListener("hidden.bs.modal", function (e) {
        if (e.target.classList.contains("modal")) {
            document.body.classList.remove("modal-open");
            document.querySelectorAll(".modal-backdrop").forEach(function (bd) {
                bd.remove();
            });
        }
    });
});
</script>';

render_footer();