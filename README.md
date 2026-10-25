<p align="center">
  <img src="assets/icon.png" alt="vanilla" width="128" height="128">
</p>

<h1 align="center">vanilla</h1>

<p align="center">
  2012 style roblox avatar renders in plain php, with experimental 2009 and 2016 modes
</p>

<p align="center">
  <img src="https://img.shields.io/badge/php-7.4%2B-777bb4?style=flat-square&logo=php&logoColor=white" alt="php 7.4+">
</p>

---

renders 2012 style roblox avatars in php (and, experimentally, 2009 and 2016 style ones). you give it a charapp (or asset ids and body colors) and it gives you back a png. runs on regular shared hosting, no gpu or webgl or anything.

## how it works

you send a charapp link, it downloads the items from your asset host, builds the character and draws it in php, then sends back a png. renders get cached so the same avatar is instant after the first time (the first one takes about 0.5 to 2 seconds).

## vs rccservice

rccservice is roblox's real render server. you send it a soap request with a lua script and it runs the actual engine on a windows machine with a gpu.

vanilla doesn't use any of that. there's no soap, no lua and no windows box, you just make a normal request and get an image back.

## setup

needs php 7.4+ with gd, curl and zlib. Allow 256 MB PHP memory for large 2016 renders; `avatar.php` already requests this limit. Direct PHP callers should configure the same limit.

1. put the folder on your server
2. set `assetBase` in `config.php` to your asset host (the id gets added to the end)
3. make sure php can write to `cache/`

now request a render:

```
avatar.php?charapp=https://yoursite.com/charapp&size=150x200
```

the charapp url is the link your site gives out for an avatar. vanilla fetches it and reads the list inside, which looks like this:

```
https://yoursite.com/bodycolors?a;https://yoursite.com/asset/?id=1234;https://yoursite.com/asset/?id=5678;
```

the first url is the bodycolors url, it gets fetched for the six body colors. every `asset/?id=` url after it is an item the avatar is wearing. everything is split by `;`. you can also pass that list straight into `charapp` instead of a link to it.

the urls have to be on your `assetBase` host. if you need other hosts, add them to `allowedHosts` in `config.php`.

if you don't have a bodycolors url you can pass `colors` yourself (`colors=f5cd30,0d69ac,...`, head, torso, left arm, right arm, left leg, right leg). if you pass both, `colors` wins.

you can also skip the charapp and just use ids and colors:

```
avatar.php?ids=1234,5678&colors=f5cd30,0d69ac,f5cd30,f5cd30,a4bd47,a4bd47&size=150x200
```

| param | what it does |
|---|---|
| `charapp` | the charapp link (or the list itself). use this or `ids`, not both |
| `item` | one asset id, rendered on its own, see below. use it instead of `charapp` and `ids` |
| `ids` | up to 12 asset ids (shirts, pants, t-shirts, faces, hats, gear) |
| `colors` | head, torso, left arm, right arm, left leg, right leg as `rrggbb` |
| `size` | `150x200` (default), `180x220`, `100x100`, `352x352`, `420x420`, `512x512`, `150x150`, `48x48`. `x` and `y` work too |
| `view` | `body` (default) or `head` for a headshot |
| `renderer` | `2012` (default), or experimental `2009` or `2016`. each one has its own camera, lighting and material highlights (2016 also has legacy particle effects) |
| `format` | `png` (default), `webp` or `jpg`. png and webp keep transparency, jpg gets a solid background |
| `bg` | background colour for jpg as hex, `ffffff` by default |
| `smooth` | `1` (default) or `0` for hard edges |
| `sid` | 16 to 32 hex chars. a new render with the same sid deletes the previous one |

### rendering a single item

`item` renders one asset by itself, same lighting and camera as the avatar renders:

```
avatar.php?item=1234&size=420x420
```

| item | what you get |
|---|---|
| hat, accessory, gear | the item alone, framed to fit |
| head, face | the head alone |
| shirt, pants, t-shirt | a gray avatar wearing it |
| package (old id list or the newer r6/r15 bundle) | a full avatar in the package, r6 only |

anything else (bare images, models without one of the above) is refused.

### rbxasset:// paths

some gear points at `rbxasset://` files. vanilla swaps the `rbxasset://` part for your asset url, so with `assetBase` set to `https://site.com/asset/?id=` the path `rbxasset://fonts/sword.mesh` is requested as `https://site.com/asset/?id=fonts/sword.mesh`, and `rbxasset://123` is the same as asset id 123. only `.mesh`, `.png` and `.jpg` paths are read. the body part meshes and the default face are bundled.

or from php:

```php
require 'src/Vanilla.php';
$r = (new Vanilla\Avatar(['assetBase' => 'https://your.host/asset/?id=', 'cacheDir' => __DIR__ . '/cache']))
    ->render(['charapp' => 'https://your.host/charapp', 'size' => '150x200']);
file_put_contents('thumb.png', $r['png']);
```

## rendering a place

`place.php` renders a place (an old `.rbxl`, xml or binary) into a cover image. same idea as `avatar.php`: you give it the place asset id, it downloads the place from your asset host, draws it and sends back an image.

```
place.php?id=1818&size=1050x575
```

| param | what it does |
|---|---|
| `id` | the place asset id. required, numbers only |
| `size` | `WIDTHxHEIGHT`, from 48x48 up to 1920x1080. `1050x575` by default. `x` and `y` work too |
| `format` | `png` (default), `webp` or `jpg`. png and webp keep transparency, jpg gets a solid background |
| `bg` | background colour for jpg as hex, `ffffff` by default |

the place is fetched from `assetBase` + `id`, so it has to be on your `assetBase` host (or one in `allowedHosts`). if your places live somewhere else, set `placeBase` in `config.php` and the id gets added to the end of that instead.

extra settings for `config.php` (all optional):

| setting | what it does |
|---|---|
| `placeBase` | where places are downloaded from, `assetBase` by default |
| `placeMaxMB` | biggest place file it will download, `32` by default |
| `maxConcurrentPlaces` | how many place renders run at once, `1` by default. they are slow and use a lot of memory, so keep it low |

the first render of a place takes a while (seconds, not the half second of an avatar) and is cached after that, so put the url in an `<img>` and the next requests are instant. the cache follows `cacheSeconds` and `cacheMaxMB` like avatar renders. the response has an `X-Vanilla-Notes` header with anything the renderer skipped (the same lines the terminal prints as `aviso:`). php needs 512 MB of memory for this, `place.php` already requests it.

errors: `400` bad parameter, `404` place not found on the host, `502` the host could not be reached, `503` too many renders running, `500` the render failed.

you can still run it from the terminal on a file on your machine:

```
php place.php meu.rbxl capa.png 1050 575
```

or from php:

```php
require 'src/Vanilla.php';
$renderer = new Vanilla\Avatar(['assetBase' => 'https://your.host/asset/?id=', 'cacheDir' => __DIR__ . '/cache']);
file_put_contents('capa.png', $renderer->renderPlace(file_get_contents('meu.rbxl'), 1050, 575));
foreach ($renderer->placeNotes as $note) {
    echo $note, "\n";
}
```

## 2009 rendering option

an experimental profile that tries to look like the avatar thumbnails from 2009. append `&renderer=2009`:

```
avatar.php?ids=1234,5678&size=150x200&renderer=2009
```

compared to the 2012 look it has flatter lighting with more ambient light, a soft and wide highlight (weak on the flat body parts, most visible on the rounded head), and its own camera, turned about 25 degrees and tilted about 24 degrees down with a 60 degree field of view. the 2012 body studs and leg inlets stay. it uses the same assets and request parameters as the other modes, and its png cache and etags are separate.

you can also set `renderer` in `config.php` (or `config.local.php`) to make it the endpoint default. in php pass `'renderer' => '2009'` to `render()` or to the `Avatar` constructor.

it is **not verified as a 1:1 2009 reproduction**. the camera was fitted to one reference thumbnail by comparing silhouettes, and the lighting and highlight values are starting points that were tuned by eye. the body part meshes in `data/` are the same ones the 2012 mode uses, so their edge rounding is not 2009 specific.

### tuning the profile from php

two methods on `Avatar` let you change the profile without editing the source. they only affect php callers, not the url parameters.

```php
$avatar = new Vanilla\Avatar([
    'assetBase' => 'https://your.host/asset/?id=',
    'cacheDir' => __DIR__ . '/cache',
    'renderer' => '2009',
]);

// camera for the body view: dir is the direction from the avatar to the camera
$avatar->setCamera(['dir' => [-0.3861, 0.4067, -0.8280], 'fov' => 60.0]);

// 2009 lighting and highlight: spec is [intensity, exponent], a lower exponent spreads the highlight out
$avatar->tune([
    'spec' => [0.6, 8],
    'lighting' => ['ambient' => 0.62, 'key' => 0.50, 'fill' => 0.15],
]);
```

`setCamera` takes `dir`, `fov` (degrees) and `zoom`, and applies to the body view of the 2009 and 2012 modes (the head view and the 2016 mode have their own cameras). `tune` only applies to the 2009 mode. pass `null` to `setCamera` to go back to the profile's camera.

the camera direction can be built from two angles, `a` for the turn and `e` for the tilt:

```
dir = [-sin(a) * cos(e), sin(e), -cos(a) * cos(e)]
```

the render cache used by `avatar.php` does not include these overrides, so when you change the built-in values in `src/parts/RenderProfiles.php`, change `RENDER_VERSION` in `avatar.php` too or you will keep getting the old png.

### calibration scripts

two small scripts for adjusting the 2009 profile. both run from the terminal in the vanilla folder.

```
php fit.php ref.png
```

`fit.php` searches for the camera that best matches a reference thumbnail. it renders many combinations of turn, tilt and field of view, compares the silhouette of each one with the reference (colors, clothes and lighting do not count) and prints the best five together with the two lines to paste into the 2009 profile. it also writes `cache/fit_compare.png` with the reference on the left and the best render on the right. the reference should show the avatar without a hat or gear, in the same pose vanilla draws.

```
php spec.php
```

`spec.php` writes `cache/spec_sheet.png`, a sheet of nine purple avatars with different highlight settings (rows are the intensity and columns are the exponent), so you can pick the one that looks closest to your reference by eye.

## 2016 rendering option

Append `&renderer=2016` to an existing render URL:

```
avatar.php?ids=1234,5678&size=420x420&renderer=2016
```

Use `renderer=2012` for the existing appearance. You can also set `renderer` in
`config.php` (or `config.local.php`) to change the endpoint default. All modes
share the same assets and request parameters; their PNG caches and ETags are separate.
In PHP, pass `'renderer' => '2016'` to `render()` or to the `Avatar` constructor.
A per-request choice only affects that request.

The experimental mode uses source-derived 2016 default lighting, per-material
specular values, and layered Fire/Smoke/Sparkles with original 2016 textures.
Lighting rotates the sun into the avatar coordinate frame and evaluates HQ highlights per pixel.
It also uses the source avatar script's uncropped body camera, native binary mesh
UVs and clothing atlas, no body studs, and the original head face UVs. R6 package
body meshes and legacy/file-mesh heads can replace default parts.
It is **not verified as a 1:1 2016 RCCService replacement**. See [source notes and remaining gaps](docs/2016-renderer.md).

Run the offline checks with `php tests/renderers.php`, `php tests/geometry2016.php`, and `php tests/lighting2016.php`.

## switching from rccservice to vanilla

you already have the charapp you give rcc. instead of sending it to rcc, put it in the `charapp` param of `avatar.php` and use the png you get back. save it or put the url in an `<img>`.

## what's where

```
avatar.php            the endpoint, reads the request and sends back the png
place.php              the place endpoint, renders a place into a cover image (also runs from the terminal)
config.php            your settings
fit.php               finds the 2009 camera that best matches a reference thumbnail
spec.php              makes a sheet of 2009 highlight settings to compare
src/Vanilla.php       the renderer, render() is the entry point
src/CharApp.php       reads charapp links and lists
src/Net.php           all outside requests, public addresses only
src/parts/            the renderer split by job (assets, meshes, look building, particles, scene, rasterisers)
src/parts/RenderProfiles.php   the 2009, 2012 and 2016 profiles: camera, lighting and highlights
src/Rbxm.php          reads roblox models (xml and binary)
src/Img.php           image decoding and drawing
data/                 the body meshes and textures
```

## license

[GNU General Public License v3.0](LICENSE). you can use, change and share vanilla freely, as long as anything you distribute that's based on it is also released under the GPLv3 with its source available. the models and textures in `data/` come from the original client and aren't covered by it.