<?php
declare(strict_types=1);
umask(0077);
ini_set('memory_limit', '512M');
require __DIR__ . '/src/Vanilla.php';
$config = require __DIR__ . '/config.php';

const PLACE_RENDER_VERSION = 'vanilla-place-1.0';
const DEFAULT_WIDTH = 1050;
const DEFAULT_HEIGHT = 575;
const MIN_SIDE = 48;
const MAX_WIDTH = 1920;
const MAX_HEIGHT = 1080;

function createRenderer(array $config): Vanilla\Avatar {
    return new Vanilla\Avatar([
        'assetBase' => $config['assetBase'],
        'allowedHosts' => $config['allowedHosts'] ?? [],
        'allowPrivateHosts' => !empty($config['allowPrivateHosts']),
        'cacheDir' => $config['cacheDir'],
    ]);
}

if (PHP_SAPI === 'cli') {
    $inputFile = $argv[1] ?? 'meu.rbxl';
    $outputFile = $argv[2] ?? 'capa.png';
    if (!is_file($inputFile)) {
        echo "erro: arquivo nao encontrado: $inputFile\n";
        exit(1);
    }
    $renderer = createRenderer($config);
    $png = $renderer->renderPlace(
        (string)file_get_contents($inputFile),
        (int)($argv[3] ?? DEFAULT_WIDTH),
        (int)($argv[4] ?? DEFAULT_HEIGHT)
    );
    file_put_contents($outputFile, $png);
    foreach ($renderer->placeNotes as $note) {
        echo "aviso: $note\n";
    }
    echo "ok: $outputFile\n";
    exit(0);
}

function fail(int $code, string $message): void {
    http_response_code($code);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    echo $message, "\n";
    exit;
}

function sendImage(string $bytes, string $format, string $etag, int $maxAge, array $notes = []): void {
    header('Content-Type: ' . Vanilla\Img::FORMATS[$format]);
    header('Cache-Control: public, max-age=' . $maxAge);
    header('ETag: ' . $etag);
    if ($notes) {
        header('X-Vanilla-Notes: ' . substr((string)preg_replace('/[^\x20-\x7e]/', '?', implode(' | ', $notes)), 0, 900));
    }
    header('Content-Length: ' . strlen($bytes));
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'HEAD') {
        echo $bytes;
    }
    exit;
}

function cleanOldRenders(string $directory, int $maxAgeSeconds, int $maxBytes): void {
    if (mt_rand(1, 20) !== 1) {
        return;
    }
    $now = time();
    $keptFiles = [];
    $totalBytes = 0;
    foreach (@scandir($directory) ?: [] as $fileName) {
        if (!preg_match('/^place-[0-9a-f]{64}\.(png|webp|jpg)$/D', $fileName)) {
            continue;
        }
        $path = "$directory/$fileName";
        if (is_link($path) || !is_file($path)) {
            continue;
        }
        $modifiedAt = (int)filemtime($path);
        if ($now - $modifiedAt > $maxAgeSeconds) {
            @unlink($path);
            continue;
        }
        $keptFiles[$path] = $modifiedAt;
        $totalBytes += (int)filesize($path);
    }
    if ($totalBytes > $maxBytes) {
        asort($keptFiles);
        foreach ($keptFiles as $path => $modifiedAt) {
            if ($totalBytes <= $maxBytes) {
                break;
            }
            $totalBytes -= (int)@filesize($path);
            @unlink($path);
        }
    }
}

header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'none'");
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method !== 'GET' && $method !== 'HEAD') {
    fail(405, 'Only GET.');
}

$query = $_GET;
foreach ($query as $name => $value) {
    if (!is_string($value)) {
        fail(400, 'Bad parameter: ' . substr((string)$name, 0, 20));
    }
}

$placeId = trim($query['id'] ?? '');
if (!preg_match('/^[1-9]\d{0,17}$/D', $placeId)) {
    fail(400, 'id is the place asset number.');
}

$sizeText = $query['size'] ?? (isset($query['x'], $query['y']) ? $query['x'] . 'x' . $query['y'] : DEFAULT_WIDTH . 'x' . DEFAULT_HEIGHT);
if (!preg_match('/^(\d{2,4})x(\d{2,4})$/D', $sizeText, $sizeParts)) {
    fail(400, 'size is written WIDTHxHEIGHT, like 1050x575.');
}
$width = (int)$sizeParts[1];
$height = (int)$sizeParts[2];
if ($width < MIN_SIDE || $height < MIN_SIDE || $width > MAX_WIDTH || $height > MAX_HEIGHT) {
    fail(400, 'size goes from ' . MIN_SIDE . 'x' . MIN_SIDE . ' to ' . MAX_WIDTH . 'x' . MAX_HEIGHT . '.');
}

$format = strtolower($query['format'] ?? $config['format'] ?? 'png');
$format = $format === 'jpeg' ? 'jpg' : $format;
if (!isset(Vanilla\Img::FORMATS[$format])) {
    fail(400, 'format is one of: ' . implode(', ', array_keys(Vanilla\Img::FORMATS)) . '.');
}
$backgroundText = $query['bg'] ?? 'ffffff';
if (!preg_match('/^#?[0-9a-fA-F]{6}$/D', $backgroundText)) {
    fail(400, 'bg is a hex colour like ffffff.');
}
$background = (int)hexdec(ltrim($backgroundText, '#'));

$cacheDir = $config['cacheDir'];
if (!is_dir($cacheDir)) {
    @mkdir($cacheDir, 0700, true);
}
$maxAge = (int)$config['cacheSeconds'];
$maxBytes = (int)($config['cacheMaxMB'] ?? 200) * 1048576;

$cacheKey = hash(
    'sha256',
    PLACE_RENDER_VERSION . '|' . $placeId . '|' . $width . 'x' . $height . '|' . $format . ($format === 'jpg' ? '|' . $background : '')
);
$cachedFile = "$cacheDir/place-$cacheKey.$format";
$etag = '"' . substr($cacheKey, 0, 32) . '"';

$cacheIsFresh = static function () use ($cachedFile, $maxAge): bool {
    return is_file($cachedFile) && filemtime($cachedFile) > time() - $maxAge;
};

if (trim($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') === $etag && $cacheIsFresh()) {
    http_response_code(304);
    exit;
}
if ($cacheIsFresh()) {
    cleanOldRenders($cacheDir, $maxAge, $maxBytes);
    sendImage((string)file_get_contents($cachedFile), $format, $etag, $maxAge);
}

@set_time_limit(180);
@ini_set('memory_limit', '512M');
$slot = null;
for ($attempt = 0; $attempt < 300 && !$slot; $attempt++) {
    for ($index = 0; $index < max(1, (int)($config['maxConcurrentPlaces'] ?? 1)); $index++) {
        $handle = fopen("$cacheDir/place-slot-$index.lock", 'c');
        if ($handle && flock($handle, LOCK_EX | LOCK_NB)) {
            $slot = $handle;
            break;
        }
        if ($handle) {
            fclose($handle);
        }
    }
    if (!$slot) {
        usleep(200000);
    }
}
if (!$slot) {
    fail(503, 'Busy, try again shortly.');
}
if ($cacheIsFresh()) {
    cleanOldRenders($cacheDir, $maxAge, $maxBytes);
    sendImage((string)file_get_contents($cachedFile), $format, $etag, $maxAge);
}

$placeBase = (string)($config['placeBase'] ?? $config['assetBase']);
$allowedHosts = [];
foreach (($config['allowedHosts'] ?? []) as $host) {
    $allowedHosts[] = strtolower(trim((string)$host));
}
$baseHost = parse_url($placeBase, PHP_URL_HOST);
if ($baseHost) {
    $allowedHosts[] = strtolower($baseHost);
}
$basePort = parse_url($placeBase, PHP_URL_PORT);
$placeBytes = Vanilla\Net::get($placeBase . $placeId, [
    'maxBytes' => (int)($config['placeMaxMB'] ?? 32) * 1048576,
    'hosts' => array_values(array_unique($allowedHosts)),
    'redirects' => 3,
    'allowPrivate' => !empty($config['allowPrivateHosts']),
    'ports' => $basePort ? [(int)$basePort] : [],
    'timeout' => 30,
]);
if ($placeBytes === null || $placeBytes === '') {
    fail(Vanilla\Net::$error === 'http 404' ? 404 : 502, 'Could not get the place (' . (Vanilla\Net::$error ?: 'empty reply') . ').');
}

try {
    $renderer = createRenderer($config);
    $png = $renderer->renderPlace($placeBytes, $width, $height);
    $notes = $renderer->placeNotes;
} catch (InvalidArgumentException $error) {
    fail(400, $error->getMessage());
} catch (Throwable $error) {
    error_log('place.php: ' . $error->getMessage());
    fail(500, 'The render failed.');
}

$output = Vanilla\Img::encode($png, $format, $background);
if ($output === null) {
    flock($slot, LOCK_UN);
    fclose($slot);
    fail(501, 'This server cannot make ' . $format . '.');
}
$temporaryFile = $cachedFile . '.' . getmypid() . '.tmp';
if (@file_put_contents($temporaryFile, $output) !== false) {
    @rename($temporaryFile, $cachedFile);
}
flock($slot, LOCK_UN);
fclose($slot);
cleanOldRenders($cacheDir, $maxAge, $maxBytes);
sendImage($output, $format, $etag, $maxAge, $notes);