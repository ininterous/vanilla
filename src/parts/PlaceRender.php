<?php
namespace Vanilla;

// capa de um place antigo (.rbxl em xml): as pecas vistas pela camera salva no arquivo
// usa a mesma luz, o mesmo stud e o mesmo rasterizador do avatar
trait PlaceRender {
    // avisos do ultimo render (ceu que nao carregou, etc)
    public $placeNotes = [];
    private $placeMaterials = false;
    private $matTex = [];

    // quantos studs cada repeticao da textura de material cobre
    const MATERIAL_TILE = 4.0;
    // escala de uv dos .material originais (transform): numero = por stud, matriz = sobre a posicao (wood)
    const ADD_SIGNED = [
        'wood' => [[0.01699672, 0.1353425, -0.03733006], [-0.1155417, -0.07062286, -0.04077413]],
        'woodplanks' => [[0.01699672, 0.1353425, -0.03733006], [-0.1155417, -0.07062286, -0.04077413]],
        'slate' => 0.28, 'concrete' => 0.18, 'diamondplate' => 0.28,
        'metal' => 0.06, 'foil' => 0.06, 'ice' => 0.06, 'grass' => 0.1,
    ];
    const MATERIALS = [
        256 => 'plastic', 272 => 'smoothplastic', 288 => 'neon', 512 => 'wood', 528 => 'woodplanks',
        784 => 'marble', 800 => 'slate', 816 => 'concrete', 832 => 'granite', 848 => 'brick',
        864 => 'pebble', 880 => 'cobblestone', 1040 => 'corrodedmetal', 1056 => 'diamondplate',
        1072 => 'foil', 1088 => 'metal', 1280 => 'grass', 1296 => 'sand', 1312 => 'fabric', 1536 => 'ice',
    ];

    private function inWorkspace(Inst $o): bool {
        for ($x = $o->parent, $d = 0; $x && $d < 200; $x = $x->parent, $d++) {
            if ($x->cls === 'Workspace') {
                return true;
            }
        }
        return false;
    }

    // cor da peca de 0 a 1
    private function partRgb(array $p): array {
        $u = $p['Color3uint8'] ?? null;
        if (is_array($u) && isset($u['r'])) {
            return [$u['r'], $u['g'], $u['b']];
        }
        $b = $p['BrickColor'] ?? 194;
        if (is_array($b) && isset($b['color'])) {
            return [$b['color']['r'], $b['color']['g'], $b['color']['b']];
        }
        $c = BrickColors::rgb((int)$b);
        return [$c[0] / 255, $c[1] / 255, $c[2] / 255];
    }

    // textura de material: data/materials/<nome>.png (ou .jpg). sem arquivo = cor lisa
    private function materialTexture(int $m): ?Texture {
        $name = self::MATERIALS[$m] ?? null;
        if ($name === null || $name === 'plastic' || $name === 'smoothplastic' || $name === 'neon') {
            return null;
        }
        if (!array_key_exists($name, $this->matTex)) {
            $this->matTex[$name] = null;
            foreach (['png', 'jpg'] as $ext) {
                $f = $this->dataDir . '/materials/' . $name . '.' . $ext;
                if (is_file($f)) {
                    $im = Img::decode((string)file_get_contents($f), self::MAX_IMAGE_SIDE);
                    if ($im) {
                        $add = isset(self::ADD_SIGNED[$name]);
                        if ($name !== 'corrodedmetal' && !$add) {
                            $this->normTex($im);
                        }
                        $t = new Texture($im);
                        $t->repeat = true;
                        if ($add) {
                            $t->addSigned = true;
                            $t->uvm = self::ADD_SIGNED[$name];
                        }
                        $t->rust = ($name === 'corrodedmetal');
                        $this->matTex[$name] = $t;
                    }
                    break;
                }
            }
        }
        return $this->matTex[$name];
    }

private function normTex(Img $im, float $base = 225, float $k = 2.2): void {
    $s = 0; $n = 0;
    foreach ($im->px as $i => $c) {
        if ($i % 5 === 0) { $s += ($c >> 16 & 255) * 0.3 + ($c >> 8 & 255) * 0.59 + ($c & 255) * 0.11; $n++; }
    }
    $mean = $s / max(1, $n);
    foreach ($im->px as $i => $c) {
        $l = ($c >> 16 & 255) * 0.3 + ($c >> 8 & 255) * 0.59 + ($c & 255) * 0.11;
        $g = (int)max(0, min(255, $base + ($l - $mean) * $k));
        $im->px[$i] = ($c & 0xFF000000) | ($g << 16) | ($g << 8) | $g;
    }
}


    private $placeMeshRaw = [];

    // mesh de uma peca: SpecialMesh (Head, FileMesh, Sphere) ou MeshPart. null = usa caixa/esfera normal
    private function placeMesh(Inst $o, ?Inst $sm, array $h): ?array {
        $mp = $o->cls === 'MeshPart';
        if (!$mp && !$sm) {
            return null;
        }
        $P = $mp ? $o->props : $sm->props;
        $size = [$h[0] * 2, $h[1] * 2, $h[2] * 2];
        $scale = [1, 1, 1];
        $off = [0, 0, 0];
        $tint = [1, 1, 1];
        $head = false;
        $fit = false;
        $m = null;
        $texUrl = null;
        try {
            if ($mp) {
                $url = ($P['MeshId'] ?? $P['MeshID'] ?? [])['url'] ?? null;
                $texUrl = ($P['TextureID'] ?? $P['TextureId'] ?? [])['url'] ?? null;
                $m = $url ? $this->placeMeshRaw($url) : null;
                $fit = true;
            } else {
                $type = $P['MeshType'] ?? null;
                if ($sm->cls === 'BlockMesh') {
                    $type = 'block';
                }
                $scale = self::v3($P['Scale'] ?? null, [1, 1, 1]);
                $off = self::v3($P['Offset'] ?? null, [0, 0, 0]);
                if (isset($P['VertexColor']['x'])) {
                    $tint = [$P['VertexColor']['x'], $P['VertexColor']['y'], $P['VertexColor']['z']];
                }
                $url = $P['MeshId']['url'] ?? null;
                $texUrl = $P['TextureId']['url'] ?? null;
                $isNum = is_numeric($type);
                if ($url && ($type === null || ($isNum && (int)$type === 5) || (is_string($type) && strcasecmp($type, 'FileMesh') === 0))) {
                    $m = $this->placeMeshRaw($url);
                } elseif (($isNum && (int)$type === 0) || (is_string($type) && strcasecmp($type, 'Head') === 0)) {
                    $m = $this->base()['headRaw'];
                    $k = min($size[0] / 2, $size[1], $size[2]) / 1.25;
                    $scale = [$scale[0] * $k, $scale[1] * $k, $scale[2] * $k];
                    $head = true;
                } elseif (($isNum && (int)$type === 3) || $type === 'Sphere') {
                    $m = self::sphereMesh($size[0] / 2 * $scale[0], $size[1] / 2 * $scale[1], $size[2] / 2 * $scale[2]);
                    $scale = [1, 1, 1];
                }
            }
        } catch (\Throwable $e) {
            $this->placeNotes[] = 'mesh nao carregou: ' . substr((string)($P['MeshId']['url'] ?? ''), 0, 60);
            return null;
        }
        if (!$m) {
            if ($mp || ($sm && $sm->cls !== 'BlockMesh' && !empty($P['MeshId']['url']))) {
                $this->placeNotes[] = 'mesh nao carregou: ' . substr((string)(($P['MeshId'] ?? $P['MeshID'] ?? [])['url'] ?? ''), 0, 60);
            }
            return null;
        }
        if ($fit) {
            $bb = self::meshBounds($m);
            for ($k = 0; $k < 3; $k++) {
                $e = $bb['hi'][$k] - $bb['lo'][$k];
                $scale[$k] = $e > 1e-6 ? $size[$k] / $e : 1;
                $off[$k] = -($bb['hi'][$k] + $bb['lo'][$k]) / 2 * $scale[$k];
            }
        }
        $pos = [];
        $nrm = [];
        foreach ($m['pos'] as $q) {
            $pos[] = [$q[0] * $scale[0] + $off[0], $q[1] * $scale[1] + $off[1], $q[2] * $scale[2] + $off[2]];
        }
        foreach ($m['nrm'] as $n) {
            $nrm[] = self::norm([$scale[0] ? $n[0] / $scale[0] : 0, $scale[1] ? $n[1] / $scale[1] : 0, $scale[2] ? $n[2] / $scale[2] : 0]);
        }
        $uv = $m['uv'];
        if ($scale[0] * $scale[1] * $scale[2] < 0) {
            for ($t = 0; $t + 2 < count($pos); $t += 3) {
                foreach ([&$pos, &$nrm, &$uv] as &$a) {
                    $x = $a[$t + 1];
                    $a[$t + 1] = $a[$t + 2];
                    $a[$t + 2] = $x;
                }
                unset($a);
            }
        }
        $tex = null;
        if ($texUrl && !$head) {
            $im = $this->image($texUrl, 0, true);
            if ($im) {
                $tex = new Texture($im, true);
                $tex->cut = self::alphaCutsOut($im, $uv);
            } else {
                $this->placeNotes[] = 'textura da mesh nao carregou: ' . substr((string)$texUrl, 0, 60);
            }
        }
        return ['g' => ['pos' => $pos, 'nrm' => $nrm, 'uv' => $uv], 'tex' => $tex, 'tint' => $tint, 'head' => $head];
    }

    private function placeMeshRaw(string $url): ?array {
        if (!array_key_exists($url, $this->placeMeshRaw)) {
            $b = $this->bytes($url);
            $this->placeMeshRaw[$url] = $b === null ? null : self::cleanMesh(self::parseMesh($b));
        }
        return $this->placeMeshRaw[$url];
    }


    private $decalTex = [];
    private $decalFail = 0;

    // decal numa face de peca comum: um quadrado levemente acima da face, com a imagem
    private function decalCall(Inst $d, array $pc, array $h, int $mat): ?array {
        if (($d->props['Transparency'] ?? 0) >= 0.9) {
            return null;
        }
        $tv = $d->props['Texture'] ?? null;
        $url = is_array($tv) ? (($tv['url'] ?? '') !== '' ? $tv['url'] : (isset($tv['data']) ? 'data:' . md5($tv['data']) : null)) : $tv;
        if (!$url) {
            return null;
        }
        $tiled = $d->cls === 'Texture';
        $url = ($tiled ? 'tile:' : '') . $url;
        if (!array_key_exists($url, $this->decalTex)) {
            $im = $this->contentImage($tv);
            $t = null;
            if ($im) {
                $t = new Texture($im, true);
                $t->repeat = $tiled;
                $this->placeNotes[] = 'decal ' . $im->w . 'x' . $im->h . ' alpha=' . ($t->hasAlpha ? 'sim' : 'nao') . ' ' . ($tiled ? 'tile' : 'decal');
            } else {
                $this->decalFail++;
            }
            $this->decalTex[$url] = $t;
        }
        $tex = $this->decalTex[$url];
        if (!$tex) {
            return null;
        }
        $fc = $d->props['Face'] ?? 5;
        $byNum = [0 => 'R', 1 => 'U', 2 => 'B', 3 => 'D', 4 => 'L', 5 => 'F'];
        $byName = ['right' => 'R', 'top' => 'U', 'back' => 'B', 'bottom' => 'D', 'left' => 'L', 'front' => 'F'];
        $key = is_numeric($fc) ? ($byNum[(int)$fc] ?? 'F') : ($byName[strtolower((string)$fc)] ?? 'F');
        $idx = ['F' => 0, 'B' => 1, 'R' => 2, 'L' => 3, 'U' => 4, 'D' => 5][$key];
        $f = self::boxFaces($h[0], $h[1], $h[2], self::TEMPLATE['torso'], 0)[$idx];
        $e = 0.01;
        $ku = 1.0;
        $kv = 1.0;
        if ($tiled) {
            $p0 = ($f['at'])(0, 0);
            $du = sqrt(self::dot(self::sub(($f['at'])(1, 0), $p0), self::sub(($f['at'])(1, 0), $p0)));
            $dv = sqrt(self::dot(self::sub(($f['at'])(0, 1), $p0), self::sub(($f['at'])(0, 1), $p0)));
            $ku = $du / max(0.01, (float)($d->props['StudsPerTileU'] ?? 2));
            $kv = $dv / max(0.01, (float)($d->props['StudsPerTileV'] ?? 2));
        }
        $C = [];
        foreach ([[0, 0], [1, 0], [1, 1], [0, 1]] as $st) {
            $q = ($f['at'])($st[0], $st[1]);
            $C[] = [[$q[0] + $f['n'][0] * $e, $q[1] + $f['n'][1] * $e, $q[2] + $f['n'][2] * $e], [$st[0] * $ku, $st[1] * $kv]];
        }
        $g = ['pos' => [], 'nrm' => [], 'uv' => []];
        foreach ([[0, 1, 2], [0, 2, 3]] as $t) {
            $a = $C[$t[0]];
            $b = $C[$t[1]];
            $c = $C[$t[2]];
            $ok = self::dot(self::cross(self::sub($b[0], $a[0]), self::sub($c[0], $a[0])), $f['n']) >= 0;
            foreach ($ok ? [$a, $b, $c] : [$a, $c, $b] as $v) {
                $g['pos'][] = $v[0];
                $g['nrm'][] = $f['n'];
                $g['uv'][] = $v[1];
            }
        }
        return [$g, $pc, 3, null, $tex, false, null, [1, 1, 1], $mat];
    }


    // imagem de um Content: bytes embutidos no xml (base64), rbxasset:// local ou url normal
    private function contentImage($v): ?Img {
        if (!is_array($v)) {
            $v = ['url' => $v];
        }
        if (!empty($v['data'])) {
            return Img::decode((string)$v['data'], self::MAX_IMAGE_SIDE) ?: null;
        }
        $url = (string)($v['url'] ?? '');
        if ($url === '') {
            return null;
        }
        if (strlen($url) > 256 && !preg_match('#^[a-z][a-z0-9+.-]*://#i', $url)) {
            $raw = base64_decode(preg_replace('/\s+/', '', $url), true);
            return $raw ? (Img::decode($raw, self::MAX_IMAGE_SIDE) ?: null) : null;
        }
        if (stripos($url, 'rbxasset://') === 0) {
            $rel = ltrim(str_replace('\\', '/', substr($url, 11)), '/');
            foreach ([$this->dataDir . '/' . $rel, $this->dataDir . '/rbxasset/' . $rel] as $f) {
                if (is_file($f)) {
                    return Img::decode((string)file_get_contents($f), self::MAX_IMAGE_SIDE) ?: null;
                }
            }
            return null;
        }
        return $this->image($url, 0, true);
    }


    // uv em coordenadas do MUNDO (como o Roblox): matriz 2x3 sobre a posicao, ou numero = tiles por stud projetado na face dominante
    private static function worldUV(array $g, array $cf, $m): array {
        $R = $cf['R'];
        $t = $cf['p'];
        $uv = [];
        foreach ($g['pos'] as $i => $p) {
            $w = [
                $R[0] * $p[0] + $R[1] * $p[1] + $R[2] * $p[2] + $t[0],
                $R[3] * $p[0] + $R[4] * $p[1] + $R[5] * $p[2] + $t[1],
                $R[6] * $p[0] + $R[7] * $p[1] + $R[8] * $p[2] + $t[2],
            ];
            if (is_array($m)) {
                $uv[$i] = [$m[0][0] * $w[0] + $m[0][1] * $w[1] + $m[0][2] * $w[2], $m[1][0] * $w[0] + $m[1][1] * $w[1] + $m[1][2] * $w[2]];
                continue;
            }
            $n = $g['nrm'][$i];
            $wn = [abs($R[0] * $n[0] + $R[1] * $n[1] + $R[2] * $n[2]), abs($R[3] * $n[0] + $R[4] * $n[1] + $R[5] * $n[2]), abs($R[6] * $n[0] + $R[7] * $n[1] + $R[8] * $n[2])];
            if ($wn[1] >= $wn[0] && $wn[1] >= $wn[2]) {
                $uv[$i] = [$w[0] * $m, $w[2] * $m];
            } elseif ($wn[0] >= $wn[2]) {
                $uv[$i] = [$w[2] * $m, $w[1] * $m];
            } else {
                $uv[$i] = [$w[0] * $m, $w[1] * $m];
            }
        }
        $g['uv'] = $uv;
        return $g;
    }

    // uv de caixa pra textura de material: repete a cada MATERIAL_TILE studs
    private static function boxUV(array $g, float $hx, float $hy, float $hz, $m = null): array {
        $k = self::MATERIAL_TILE;
        if (is_array($m)) {
            foreach ($g['pos'] as $i => $p) {
                $g['uv'][$i] = [$m[0][0] * $p[0] + $m[0][1] * $p[1] + $m[0][2] * $p[2], $m[1][0] * $p[0] + $m[1][1] * $p[1] + $m[1][2] * $p[2]];
            }
            return $g;
        }
        if (is_numeric($m)) {
            $k = 1.0 / $m;
        }
        foreach ($g['pos'] as $i => $p) {
            $n = $g['nrm'][$i];
            $a = [abs($n[0]), abs($n[1]), abs($n[2])];
            if ($a[1] >= $a[0] && $a[1] >= $a[2]) {
                $g['uv'][$i] = [($p[0] + $hx) / $k, ($p[2] + $hz) / $k];
            } elseif ($a[0] >= $a[2]) {
                $g['uv'][$i] = [($p[2] + $hz) / $k, ($p[1] + $hy) / $k];
            } else {
                $g['uv'][$i] = [($p[0] + $hx) / $k, ($p[1] + $hy) / $k];
            }
        }
        return $g;
    }

    // as 6 imagens do ceu: do objeto Sky do place, ou da pasta data/sky (bk dn ft lf rt up)
    private function skyTextures(array $tree): ?array {
        $faces = ['ft' => 'SkyboxFt', 'bk' => 'SkyboxBk', 'lf' => 'SkyboxLf', 'rt' => 'SkyboxRt', 'up' => 'SkyboxUp', 'dn' => 'SkyboxDn'];
        $sky = null;
        foreach ($tree['byRef'] as $o) {
            if ($o->cls === 'Sky') {
                $sky = $o;
                break;
            }
        }
        $imgs = [];
        if ($sky) {
            foreach ($faces as $k => $prop) {
                $v = $sky->props[$prop] ?? null;
                $url = is_array($v) ? ($v['url'] ?? null) : $v;
                $im = $url ? $this->image($url) : null;
                if (!$im) {
                    $this->placeNotes[] = 'sky: ' . $prop . ' nao carregou (' . substr((string)$url, 0, 60) . ')';
                    $imgs = [];
                    break;
                }
                $imgs[$k] = $im;
            }
        }
        if (count($imgs) < 6) {
            $imgs = [];
            foreach ($faces as $k => $prop) {
                foreach (['png', 'jpg'] as $ext) {
                    $f = $this->dataDir . '/sky/' . $k . '.' . $ext;
                    if (is_file($f)) {
                        $im = Img::decode((string)file_get_contents($f), self::MAX_IMAGE_SIDE);
                        if ($im) {
                            $imgs[$k] = $im;
                            break;
                        }
                    }
                }
            }
        }
        if (count($imgs) < 6) {
            return null;
        }
        $out = [];
        foreach ($imgs as $k => $im) {
            $t = new Texture($im);
            $t->repeat = false;
            $t->mip = false;
            $out[$k] = $t;
        }
        return $out;
    }
    
private function wallNames(array $sky): array {
    $best = ['bk', 'lf', 'ft', 'rt'];
    $bestErr = INF;
    foreach ([['bk', 'lf', 'ft', 'rt'], ['bk', 'rt', 'ft', 'lf']] as $n) {
        $err = 0;
        for ($i = 0; $i < 4; $i++) {
            for ($j = 0; $j <= 32; $j++) {
                $v = $j / 32;
                $c = $sky[$n[$i]]->bilinear(0, 0.999, $v);
                $d = $sky[$n[($i + 1) % 4]]->bilinear(0, 0.001, $v);
                $err += ($c[0] - $d[0]) ** 2 + ($c[1] - $d[1]) ** 2 + ($c[2] - $d[2]) ** 2;
            }
        }
        if ($err < $bestErr) {
            $bestErr = $err;
            $best = $n;
        }
    }
    return $best;
}

private function floorMap(array $sky, array $wn): int {
    $m = function ($k, $a, $b) {
        return [[$a, $b], [$b, $a], [1 - $a, $b], [$b, 1 - $a], [$a, 1 - $b], [1 - $b, $a], [1 - $a, 1 - $b], [1 - $b, 1 - $a]][$k];
    };
    $edges = [
        [$wn[0], function ($t) { return [$t, 0]; }, function ($t) { return $t; }],
        [$wn[1], function ($t) { return [1, $t]; }, function ($t) { return $t; }],
        [$wn[2], function ($t) { return [$t, 1]; }, function ($t) { return 1 - $t; }],
        [$wn[3], function ($t) { return [0, $t]; }, function ($t) { return $t; }],
    ];
    $best = 0;
    $bestErr = INF;
    for ($k = 0; $k < 8; $k++) {
        $err = 0;
        foreach ($edges as [$wall, $pt, $wu]) {
            for ($i = 0; $i <= 32; $i++) {
                $t = $i / 32;
                [$a, $b] = $pt($t);
                [$u, $v] = $m($k, $a, $b);
                $c = $sky['dn']->bilinear(0, $u, $v);
                $w = $sky[$wall]->bilinear(0, $wu($t), 0.999);
                $err += ($c[0] - $w[0]) ** 2 + ($c[1] - $w[1]) ** 2 + ($c[2] - $w[2]) ** 2;
            }
        }
        if ($err < $bestErr) {
            $bestErr = $err;
            $best = $k;
        }
    }
    return $best;
}

    // devolve um png (ceu + pecas)
    function renderPlace(string $bytes, int $W = 420, int $H = 230, int $SS = 2): string {
        $this->placeNotes = [];
        $this->decalFail = 0;
        $this->decalTex = [];
        $this->placeMaterials = true;
        $oldLighting = $this->lighting;
        try {
            return $this->renderPlaceInner($bytes, $W, $H, $SS);
        } finally {
            $this->placeMaterials = false;
            $this->lighting = $oldLighting;
        }
    }

    private function renderPlaceInner(string $bytes, int $W, int $H, int $SS): string {
        $tree = Rbxm::parse($bytes);

        // luz do place: Ambient e Brightness do objeto Lighting (padrao do Roblox: Ambient 128/255, Brightness 1)
        foreach ($tree['byRef'] as $o) {
            if ($o->cls === 'Lighting') {
                $am = $o->props['Ambient'] ?? null;
                $br = $o->props['Brightness'] ?? 1;
                if (is_array($am) && isset($am['r'])) {
                    $this->lighting['ambient'] = ($am['r'] + $am['g'] + $am['b']) / 3;
                }
                if (is_numeric($br)) {
                    $this->lighting['key'] = self::KEY * (float)$br;
                    $this->lighting['fill'] = self::FILL * (float)$br;
                }
                $this->placeNotes[] = 'luz: ambient=' . round($this->lighting['ambient'], 3) . ' brightness=' . $br;
                break;
            }
        }

        // camera: a CurrentCamera do Workspace, ou a primeira Camera que existir
        $cam = null;
        foreach ($tree['byRef'] as $o) {
            if ($o->cls === 'Workspace') {
                $ref = $o->props['CurrentCamera']['ref'] ?? null;
                $cam = $ref !== null ? ($tree['byRef'][$ref] ?? null) : null;
                break;
            }
        }
        if (!$cam) {
            foreach ($tree['byRef'] as $o) {
                if ($o->cls === 'Camera') {
                    $cam = $o;
                    break;
                }
            }
        }
        $cf = $cam ? self::cfFrom($cam->props['CoordinateFrame'] ?? $cam->props['CFrame'] ?? null) : self::CF([0, 10, 20]);
        $eye = $cf['p'];
        $R = $cf['R'];
        $look = [-$R[2], -$R[5], -$R[8]];
        $up = [$R[1], $R[4], $R[7]];
        $fov = 70 * M_PI / 180;
        $vp = self::mul4(
            self::perspective($fov, $W / $H, 0.1, 3000),
            self::lookAt($eye, [$eye[0] + $look[0], $eye[1] + $look[1], $eye[2] + $look[2]], $up)
        );

        // pecas -> chamadas de desenho
        $calls = [];
        $geo = [];
        foreach ($tree['byRef'] as $o) {
            if ($o->cls === 'Camera' || !$this->inWorkspace($o)) {
                continue;
            }
            $p = $o->props;
            $s = $p['size'] ?? $p['Size'] ?? null;
            $c = $p['CFrame'] ?? null;
            if (!is_array($s) || !is_array($c) || ($p['Transparency'] ?? 0) > 0.5) {
                continue;
            }
            if (in_array($o->cls, ['WedgePart', 'CornerWedgePart', 'TrussPart'], true)) {
                continue;
            }
            $pc = self::cfFrom($c);
            $hx = $s['x'] / 2;
            $hy = $s['y'] / 2;
            $hz = $s['z'] / 2;
            // corta o que esta atras da camera ou longe/pequeno demais
            $r = sqrt($hx * $hx + $hy * $hy + $hz * $hz);
            $z = self::dot(self::sub($pc['p'], $eye), $look);
            if ($z < -$r || $z - $r > 2500 || $r < 0.003 * $z) {
                continue;
            }
            $mat = (int)($p['Material'] ?? 256);
            $sm = null;
            $decal = null;
            foreach ($o->children as $c) {
                if (!$sm && in_array($c->cls, ['SpecialMesh', 'FileMesh', 'BlockMesh', 'CylinderMesh'], true)) {
                    $sm = $c;
                }
                if (!$decal && $c->cls === 'Decal' && ($c->props['Transparency'] ?? 0) < 0.9) {
                    $fc = $c->props['Face'] ?? 5;
                    if (is_numeric($fc) ? (int)$fc === 5 : strcasecmp((string)$fc, 'Front') === 0) {
                        $decal = $c;
                    }
                }
            }
            $pm = $this->placeMesh($o, $sm, [$hx, $hy, $hz]);
            if ($pm && $pm['head']) {
                // diagnostico temporario: apague depois que a face aparecer
                $this->placeNotes[] = 'cabeca: decal=' . ($decal ? 'sim' : 'nao') . ' filhos=' . implode(',', array_map(function ($c) { return $c->cls . ':' . json_encode($c->props['Face'] ?? null); }, $o->children));
            }
            if ($pm) {
                $rgb = $this->partRgb($p);
                $tn = $pm['tint'];
                if ($pm['head'] && $decal) {
                    $fi = $this->contentImage($decal->props['Texture'] ?? null);
                    $ft = new Texture($fi ?: $this->base()['face']);
                    $calls[] = [$pm['g'], $pc, 2, $rgb, $ft, false, null, [1, 1, 1], $mat];
                } elseif ($pm['tex']) {
                    $calls[] = [$pm['g'], $pc, 3, null, $pm['tex'], false, null, $tn, $mat];
                } else {
                    $calls[] = [$pm['g'], $pc, 0, [$rgb[0] * $tn[0], $rgb[1] * $tn[1], $rgb[2] * $tn[2]], null, false, null, [1, 1, 1], $mat];
                }
                continue;
            }
            $ball = (int)($p['shape'] ?? $p['Shape'] ?? 1) === 0;
            if (!$ball) {
                foreach ($o->children as $c) {
                    if ($c->cls === 'Decal' || $c->cls === 'Texture') {
                        $dc = $this->decalCall($c, $pc, [$hx, $hy, $hz], (int)($p['Material'] ?? 256));
                        if ($dc) {
                            $calls[] = $dc;
                        }
                    }
                }
            }
            $wood = !$ball && ($mat === 512 || $mat === 528);
            $tex = ($ball || $wood) ? null : $this->materialTexture($mat);
            $key = ($ball ? 's' : ($tex ? 't' . $mat : 'b')) . round($hx, 3) . ',' . round($hy, 3) . ',' . round($hz, 3);
            if (!isset($geo[$key])) {
                if ($ball) {
                    $geo[$key] = self::sphereMesh($hx, $hy, $hz);
                } else {
                    $g = self::bevelBox($hx, $hy, $hz);
                    $geo[$key] = $g;
                }
            }
            $studs = !$ball && (int)($p['TopSurface'] ?? 0) === 3;
            $rgb = $this->partRgb($p);
            if ($wood) {
                $calls[] = [$geo[$key], $pc, 5, null, null, false, null, $rgb, $mat];
            } elseif ($tex) {
                $calls[] = [self::worldUV($geo[$key], $pc, $tex->uvm ?? (1.0 / self::MATERIAL_TILE)), $pc, 3, null, $tex, $studs, [$hx, $hy, $hz], $rgb, $mat];
            } else {
                $calls[] = [$geo[$key], $pc, 0, $rgb, null, $studs, [$hx, $hy, $hz], [1, 1, 1], $mat];
            }
        }

        // calls -> triangulos (joga fora o que cai fora da tela, pra poupar memoria)
        $Wss = $W * $SS;
        $Hss = $H * $SS;
        $fb = [0, 1, 0, 1];
        $tris = [];
        foreach ($calls as $call) {
            $t = [];
            $this->setup($t, $call, $vp, $eye, $Wss, $Hss, $fb);
            foreach ($t as $T) {
                if ($T['bx1'] >= $T['bx0'] && $T['by1'] >= $T['by0']) {
                    $tris[] = $T;
                }
            }
        }
        $stud = $this->base()['stud'];
        $hb = $this->base()['headBox'];
        $fbx = self::FACE_BOX;
        $fs = ($hb['hi'][0] - $hb['lo'][0]) / 2;
        $faceBox = [($fbx[0] - $fbx[2]) * $fs, ($fbx[0] + $fbx[2]) * $fs, ($fbx[1] - $fbx[3]) * $fs, ($fbx[1] + $fbx[3]) * $fs];
        $png = $this->exact
            ? $this->rasterise($tris, $W, $H, $SS, $stud, $faceBox)
            : $this->rasteriseFast($tris, $W, $H, $SS, $stud, $faceBox);

        // ceu
        $im = imagecreatetruecolor($W, $H);
        $tan = tan($fov / 2);
        $sky = $this->skyTextures($tree);
        if ($sky) {
            $right = self::norm(self::cross($look, $up));
            $wn = $this->wallNames($sky);
            $fk = $this->floorMap($sky, $wn);
            $upv = self::cross($right, $look);
            for ($y = 0; $y < $H; $y++) {
                $sy = (1 - 2 * ($y + 0.5) / $H) * $tan;
                for ($x = 0; $x < $W; $x++) {
                    $sx = (2 * ($x + 0.5) / $W - 1) * $tan * $W / $H;
                    $dx = $look[0] + $right[0] * $sx + $upv[0] * $sy;
                    $dy = $look[1] + $right[1] * $sx + $upv[1] * $sy;
                    $dz = $look[2] + $right[2] * $sx + $upv[2] * $sy;
                    $ax = abs($dx);
                    $ay = abs($dy);
                    $az = abs($dz);
                    if ($ay >= $ax && $ay >= $az) {
                        $f = $dy > 0 ? 'up' : 'dn';
                        $u = ($dx / $ay + 1) / 2;
                        $v = ($dy > 0 ? (1 - $dz / $ay) : (1 + $dz / $ay)) / 2;
                    } elseif ($az >= $ax) {
                       $f = $dz < 0 ? $wn[0] : $wn[2];
                       $u = ($dz < 0 ? $dx / $az : -$dx / $az) / 2 + 0.5;
                        $v = (1 - $dy / $az) / 2;
                    } else {
                        $f = $dx > 0 ? $wn[1] : $wn[3];
                        $u = ($dx > 0 ? $dz / $ax : -$dz / $ax) / 2 + 0.5;
                        $v = (1 - $dy / $ax) / 2;
                    }
                    if ($f === 'dn') {
    $a = ($dx / $ay + 1) / 2;
    $b = ($dz / $ay + 1) / 2;
    [$u, $v] = [[$a, $b], [$b, $a], [1 - $a, $b], [$b, 1 - $a], [$a, 1 - $b], [1 - $b, $a], [1 - $a, 1 - $b], [1 - $b, 1 - $a]][$fk];
}
                    $c = $sky[$f]->bilinear(0, $u, $v);
                    imagesetpixel($im, $x, $y, (min(255, (int)round($c[0] * 255)) << 16) | (min(255, (int)round($c[1] * 255)) << 8) | min(255, (int)round($c[2] * 255)));
                }
            }
        } else {
            // sem skybox: degrade azul, o horizonte sobe/desce conforme a camera olha pra cima/baixo
            $f = ($H / 2) / $tan;
            $pitch = max(-1.4, min(1.4, asin(max(-1, min(1, $look[1])))));
            $hor = $H / 2 + $f * tan($pitch);
            $top = [64, 114, 196];
            $low = [185, 208, 238];
            for ($y = 0; $y < $H; $y++) {
                $k = $hor <= 0 ? 1.0 : min(1.0, $y / $hor);
                imageline($im, 0, $y, $W, $y, imagecolorallocate(
                    $im,
                    (int)($top[0] + ($low[0] - $top[0]) * $k),
                    (int)($top[1] + ($low[1] - $top[1]) * $k),
                    (int)($top[2] + ($low[2] - $top[2]) * $k)
                ));
            }
        }
        if ($this->decalFail) {
            $this->placeNotes[] = $this->decalFail . ' decal(s) de asset do Roblox nao carregaram (ignorados)';
        }
        $fg = imagecreatefromstring($png);
        file_put_contents('dbg.txt', dechex(imagecolorat($fg,0,0)).' '.dechex(imagecolorat($im,0,0)).' '.($sky?'sky':'nosky'));
        imagealphablending($im, true);
        imagecopy($im, $fg, 0, 0, 0, 0, $W, $H);
        ob_start();
        imagepng($im);
        return (string)ob_get_clean();
    }
}