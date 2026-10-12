<?php

/**
 * Generates fixture/image.png, the raster image in the visual-check fixture.
 *
 * Kept next to the image so it can be regenerated, but the committed PNG is what the baselines
 * were rendered from: regenerating with another GD build could change bytes, so only run this
 * when the image is meant to change, and re-take the baselines after.
 *
 * 400 x 200 px, shown at 50 x 25 mm (about 203 dpi): colour bars to catch colour handling,
 * a checkerboard to catch image resampling (--image-dpi, --lowquality), and a 1 px frame to
 * catch cropping or scaling of the image box.
 *
 * Run: php tests/Visual/fixture/make-image.php
 */

$w = 400;
$h = 200;
$img = imagecreatetruecolor($w, $h);
$white = imagecolorallocate($img, 255, 255, 255);
$black = imagecolorallocate($img, 0, 0, 0);
imagefilledrectangle($img, 0, 0, $w - 1, $h - 1, $white);

# Eight colour bars across the top half
$bars = [[255, 0, 0], [0, 160, 0], [0, 0, 255], [255, 200, 0], [0, 200, 200], [200, 0, 200], [128, 128, 128], [0, 0, 0]];
foreach ($bars as $i => [$r, $g, $b]) {
    $c = imagecolorallocate($img, $r, $g, $b);
    imagefilledrectangle($img, $i * 50, 0, $i * 50 + 49, 99, $c);
}

# 10 px checkerboard in the bottom-left half
for ($y = 100; $y < 200; $y += 10) {
    for ($x = 0; $x < 200; $x += 10) {
        if ((($x + $y) / 10) % 2 === 0) {
            imagefilledrectangle($img, $x, $y, $x + 9, $y + 9, $black);
        }
    }
}

# Diagonal in the bottom-right half, and a 1 px frame around everything
imageline($img, 200, 100, 399, 199, $black);
imagerectangle($img, 0, 0, $w - 1, $h - 1, $black);

imagepng($img, __DIR__ . '/image.png', 9);
