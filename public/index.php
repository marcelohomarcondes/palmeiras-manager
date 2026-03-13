<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/db.php';
require_once __DIR__ . '/../src/layout.php';

$page = (string)($_GET['page'] ?? 'dashboard');

$routes = [
  'dashboard'    => __DIR__ . '/../src/pages/dashboard.php',
  'players'      => __DIR__ . '/../src/pages/players.php',

  // Partidas (histórico/consulta)
  'matches'      => __DIR__ . '/../src/pages/matches.php',

  // Cadastrar partida (cadastro completo: infos + jogadores + stats)
  'create_match' => __DIR__ . '/../src/pages/create_match.php',
  'edit_match'   => __DIR__ . '/../src/pages/edit_match.php',

  // Detalhe da partida
  'match'        => __DIR__ . '/../src/pages/match.php',

  'templates'    => __DIR__ . '/../src/pages/templates.php',
  'transfers'    => __DIR__ . '/../src/pages/transfers.php',
  'injuries'     => __DIR__ . '/../src/pages/injuries.php',
  'trophies'     => __DIR__ . '/../src/pages/trophies.php',
  #'opponents'    => __DIR__ . '/../src/pages/opponents.php',
  'stats'        => __DIR__ . '/../src/pages/stats.php',
  'crias'        => __DIR__ . '/../src/pages/crias.php',

  // Almanaque
  'almanaque'              => __DIR__ . '/../src/pages/almanaque.php',
  'almanaque_opponents'    => __DIR__ . '/../src/pages/almanaque_opponents.php',
  'almanaque_players'      => __DIR__ . '/../src/pages/almanaque_players.php',
  'almanaque_matches'      => __DIR__ . '/../src/pages/almanaque_matches.php',
  'almanaque_trophies'     => __DIR__ . '/../src/pages/almanaque_trophies.php',
  'almanaque_stats'        => __DIR__ . '/../src/pages/almanaque_stats.php',
  'almanaque_coaches'      => __DIR__ . '/../src/pages/almanaque_coaches.php',
  'almanaque_seasons'      => __DIR__ . '/../src/pages/almanaque_seasons.php',
  'almanaque_records'      => __DIR__ . '/../src/pages/almanaque_records.php',
  'almanaque_lineups'      => __DIR__ . '/../src/pages/almanaque_lineups.php',
  'almanaque_transfers'    => __DIR__ . '/../src/pages/almanaque_transfers.php',
  'almanaque_injuries'     => __DIR__ . '/../src/pages/almanaque_injuries.php',
  'almanaque_stadiums'     => __DIR__ . '/../src/pages/almanaque_stadiums.php',
];

/*
|--------------------------------------------------------------------------
| Resolver páginas do Almanaque automaticamente
|--------------------------------------------------------------------------
| Qualquer rota no formato ?page=almanaque_xxx tentará carregar:
|   /src/pages/almanaque_xxx.php
|
| Assim, ao criar novas páginas do almanaque, você não precisa cadastrar
| manualmente no array acima, desde que siga o mesmo padrão de nome.
|--------------------------------------------------------------------------
*/
if (!isset($routes[$page]) && str_starts_with($page, 'almanaque_')) {
  $candidate = __DIR__ . '/../src/pages/' . $page . '.php';
  if (is_file($candidate)) {
    $routes[$page] = $candidate;
  }
}

if (!isset($routes[$page])) {
  http_response_code(404);
  echo "Página não encontrada.";
  exit;
}

require $routes[$page];