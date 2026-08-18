<?php

/*
 * Génère la marque visuelle : favicon.svg, favicon.ico, apple-touch-icon.png,
 * og-image.png (OpenGraph). Réexécutable : `php scripts/generate-brand.php`.
 */

$font = 'C:\Windows\Fonts\arialbd.ttf';
if (! is_file($font)) {
    $font = '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf';
}
if (! is_file($font)) {
    fwrite(STDERR, "Aucune police TrueType trouvée (arialbd.ttf / DejaVuSans-Bold.ttf).\n");
    exit(1);
}

function rounded(int $w, int $h, int $r, string $bg): GdImage
{
    $im = imagecreatetruecolor($w, $h);
    imagesavealpha($im, true);
    [$pr, $pg, $pb] = sscanf($bg, '#%02x%02x%02x');
    $col = imagecolorallocate($im, $pr, $pg, $pb);
    imagealphablending($im, false);
    imagefilledrectangle($im, 0, 0, $w - 1, $h - 1, $col);
    // Découpe des coins arrondis.
    $transparent = imagecolorallocatealpha($im, 0, 0, 0, 127);
    foreach ([[$r - 1, $r - 1], [$w - $r, $r - 1], [$r - 1, $h - $r], [$w - $r, $h - $r]] as [$cx, $cy]) {
        imagefilledellipse($im, $cx, $cy, $r * 2, $r * 2, $transparent);
    }

    return $im;
}

function drawBrand(int $w, int $h, string $bg): GdImage
{
    $im = rounded($w, $h, (int) round($w * 0.22), $bg);
    $font = 'C:\Windows\Fonts\arialbd.ttf';
    if (! is_file($font)) {
        $font = '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf';
    }
    $white = imagecolorallocate($im, 255, 255, 255);
    $size = (int) ($w * 0.3);
    $text = 'ABC';
    $box = imagettfbbox($size, 0, $font, $text);
    $tw = $box[2] - $box[0];
    $th = $box[1] - $box[7];
    $x = (int) (($w - $tw) / 2);
    $textY = (int) (($h - $th) / 2 + $th);
    imagettftext($im, $size, 0, $x, $textY, $white, $font, $text);

    return $im;
}

function imgToPng(GdImage $im, string $path): void
{
    imagepng($im, $path);
}

function pngToIco(string $pngPath, string $icoPath): void
{
    $png = file_get_contents($pngPath);
    // ICONDIR + ICONDIRENTRY (PNG intégré, moderne/browsers récents).
    $header = pack('vvv', 0, 1, 1);
    $entry = pack('CCCCvvVV', 32, 32, 0, 0, 1, 32, strlen($png), 22);
    file_put_contents($icoPath, $header.$entry.$png);
}

$brand = '#14532d';
$public = __DIR__.'/../public';

// favicon.svg (portable, responsive).
$svg = <<<'SVG'
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64">
  <rect width="64" height="64" rx="14" fill="#14532d"/>
  <g fill="none" stroke="#fff" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round">
    <path d="M32 42c-7-2-9-8-8-14h14v14z"/>
    <path d="M32 18v12M20 28c4 2 8 2 12 0M32 42v4"/>
  </g>
  <text x="32" y="56" font-family="Arial, sans-serif" font-size="24" font-weight="bold"
        fill="#fff" text-anchor="middle">ABC</text>
</svg>
SVG;
file_put_contents($public.'/favicon.svg', $svg);

// favicon.ico (32px) + png source (512 pour hi-dpi).
$f512 = drawBrand(512, 512, $brand);
imgToPng($f512, $public.'/favicon.png');
$f32 = drawBrand(32, 32, $brand);
imgToPng($f32, storage_path_or(sys_get_temp_dir()).'/favicon-32.png');
pngToIco(sys_get_temp_dir().'/favicon-32.png', $public.'/favicon.ico');
@unlink(sys_get_temp_dir().'/favicon-32.png');

// apple-touch-icon.png (180px).
$apple = drawBrand(180, 180, $brand);
imgToPng($apple, $public.'/apple-touch-icon.png');

// og-image.png (1200x630) — encart logo sur fond clair.
$og = imagecreatetruecolor(1200, 630);
imagesavealpha($og, true);
[$r, $g, $b] = sscanf('#f3f6f3', '#%02x%02x%02x');
$bg = imagecolorallocate($og, $r, $g, $b);
imagefilledrectangle($og, 0, 0, 1200, 630, $bg);
$logo = drawBrand(300, 300, $brand);
imagecopy($og, $logo, 120, 165, 0, 0, 300, 300);
$font = 'C:\Windows\Fonts\arialbd.ttf';
if (! is_file($font)) {
    $font = '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf';
}
$dark = imagecolorallocate($og, 20, 40, 30);
imagettftext($og, 56, 0, 480, 300, $dark, $font, 'Observatoire des ABC');
imagettftext($og, 30, 0, 480, 350, $dark, $font, 'Atlas de la Biodiversité Communale — le suivi national');
imgToPng($og, $public.'/og-image.png');

echo "Marque visuelle générée dans {$public}\n";

function storage_path_or(string $fallback): string
{
    return defined('storage_path') ? storage_path('app') : $fallback;
}
