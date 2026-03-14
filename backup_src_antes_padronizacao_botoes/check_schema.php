<?php
$pdo = new PDO("sqlite:" . __DIR__ . "/../data/app.sqlite");
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

foreach (["match_players","match_player_stats"] as $t) {
    echo "== $t ==\n";
    $st = $pdo->query("PRAGMA table_info($t)");
    while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
        echo $r["name"], PHP_EOL;
    }
}
