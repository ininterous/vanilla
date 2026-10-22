<?php
// uso: php capa.php meu.rbxl capa.png
ini_set('memory_limit', '512M');
require 'src/Vanilla.php';
$entrada = $argv[1] ?? 'meu.rbxl';
$saida = $argv[2] ?? 'capa.png';
$cfg = is_file(__DIR__ . '/config.php') ? include __DIR__ . '/config.php' : [];
$av = new Vanilla\Avatar(is_array($cfg) ? $cfg : []);
file_put_contents($saida, $av->renderPlace(file_get_contents($entrada), (int)($argv[3] ?? 1050), (int)($argv[4] ?? 575)));
foreach ($av->placeNotes as $n) {
    echo "aviso: $n\n";
}
echo "ok: $saida\n";