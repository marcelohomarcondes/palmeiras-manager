<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/db.php';
require_once __DIR__ . '/../src/auth/auth.php';

auth_start_session();

/*
|--------------------------------------------------------------------------
| Páginas internas permitidas
|--------------------------------------------------------------------------
*/
$allowedPages = [
    'dashboard'               => __DIR__ . '/../src/pages/dashboard.php',
    'matches'                 => __DIR__ . '/../src/pages/matches.php',
    'match'                   => __DIR__ . '/../src/pages/match.php',
    'create_match'            => __DIR__ . '/../src/pages/create_match.php',
    'edit_match'              => __DIR__ . '/../src/pages/edit_match.php',

    'players'                 => __DIR__ . '/../src/pages/players.php',
    'crias'                   => __DIR__ . '/../src/pages/crias.php',
    'templates'               => __DIR__ . '/../src/pages/templates.php',
    'transfers'               => __DIR__ . '/../src/pages/transfers.php',
    'injuries'                => __DIR__ . '/../src/pages/injuries.php',
    'trophies'                => __DIR__ . '/../src/pages/trophies.php',
    'stats'                   => __DIR__ . '/../src/pages/stats.php',

    'almanaque'               => __DIR__ . '/../src/pages/almanaque.php',
    'almanaque_players'       => __DIR__ . '/../src/pages/almanaque_players.php',
    'almanaque_opponents'     => __DIR__ . '/../src/pages/almanaque_opponents.php',
    'almanaque_competitions'  => __DIR__ . '/../src/pages/almanaque_competitions.php',
    'almanaque_stadiums'      => __DIR__ . '/../src/pages/almanaque_stadiums.php',
    'almanaque_referees'      => __DIR__ . '/../src/pages/almanaque_referees.php',
    'almanaque_timeline'      => __DIR__ . '/../src/pages/almanaque_timeline.php',
];

/*
|--------------------------------------------------------------------------
| Página padrão
|--------------------------------------------------------------------------
*/
$page = trim((string)($_GET['page'] ?? 'dashboard'));
if ($page === '') {
    $page = 'dashboard';
}

/*
|--------------------------------------------------------------------------
| Exige autenticação para qualquer rota do index
|--------------------------------------------------------------------------
*/
if (!auth_check()) {
    header('Location: /login.php');
    exit;
}

/*
|--------------------------------------------------------------------------
| Resolução segura da página
|--------------------------------------------------------------------------
*/
if (!array_key_exists($page, $allowedPages)) {
    http_response_code(404);
    render_header('Página não encontrada');
    echo '<div class="alert alert-warning">A página solicitada não foi encontrada.</div>';
    echo '<a class="btn btn-primary" href="/index.php?page=dashboard">Voltar ao Dashboard</a>';
    render_footer();
    exit;
}

$pageFile = $allowedPages[$page];

if (!is_file($pageFile)) {
    http_response_code(500);
    render_header('Erro interno');
    echo '<div class="alert alert-danger">O arquivo da página não foi encontrado.</div>';
    echo '<a class="btn btn-primary" href="/index.php?page=dashboard">Voltar ao Dashboard</a>';
    render_footer();
    exit;
}

/*
|--------------------------------------------------------------------------
| Carrega a página
|--------------------------------------------------------------------------
*/
require $pageFile;