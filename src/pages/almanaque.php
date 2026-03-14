<?php
declare(strict_types=1);

require_once __DIR__ . '/../db.php';

$pdo = db();
$userId = require_user_id();

render_header('Almanaque');

$items = [
    [
        'title' => 'Jogadores',
        'icon'  => '/assets/player.svg',
        'text'  => 'Histórico completo de todos os atletas que passaram pelo elenco profissional.',
        'link'  => 'index.php?page=almanaque_players',
    ],
    [
        'title' => 'Adversários',
        'icon'  => '/assets/shield.svg',
        'text'  => 'Estatísticas históricas contra todos os adversários enfrentados.',
        'link'  => 'index.php?page=almanaque_opponents',
    ],
    [
        'title' => 'Linha do Tempo',
        'icon'  => '/assets/timeline.svg',
        'text'  => 'Navegação histórica por décadas e anos com resumo completo das temporadas.',
        'link'  => 'index.php?page=almanaque_timeline',
    ],
    [
        'title' => 'Campeonatos',
        'icon'  => '/assets/trophy.svg',
        'text'  => 'Desempenho do clube em cada competição disputada ao longo da história.',
        'link'  => 'index.php?page=almanaque_competitions',
    ],
    [
        'title' => 'Estádios',
        'icon'  => '/assets/stadium.svg',
        'text'  => 'Histórico de jogos por estádio e desempenho do clube em cada um deles.',
        'link'  => 'index.php?page=almanaque_stadiums',
    ],
    [
        'title' => 'Árbitros',
        'icon'  => '/assets/referee.svg',
        'text'  => 'Histórico de partidas apitadas por cada árbitro.',
        'link'  => 'index.php?page=almanaque_referees',
    ],
];
?>

<div class="container-fluid px-0">
    <h2 class="pm-page-title">Almanaque</h2>

    <div class="row g-3">
        <?php foreach ($items as $item): ?>
            <div class="col-12 col-md-6 col-xl-4">
                <div class="card pm-feature-card h-100">
                    <div class="card-body d-flex flex-column justify-content-center">
                        <img
                            src="<?= htmlspecialchars($item['icon'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"
                            class="icon-svg mx-auto"
                            alt="<?= htmlspecialchars($item['title'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"
                        >

                        <h5 class="mt-3 mb-2">
                            <?= htmlspecialchars($item['title'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                        </h5>

                        <p>
                            <?= htmlspecialchars($item['text'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                        </p>

                        <div class="mt-auto">
                            <a
                                href="<?= htmlspecialchars($item['link'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"
                                class="btn btn-primary"
                            >
                                Acessar
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<?php render_footer(); ?>