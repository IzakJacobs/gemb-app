<?php
/**
 * pwa-icon-admin.php
 * Deep red background, gear/cog icon with key overlay.
 * Distinctive from guard (green), security (purple), resident (blue).
 */
$size = (int)($_GET['size'] ?? 192);
if (!in_array($size, [96, 192, 512])) $size = 192;

header('Content-Type: image/png');
header('Cache-Control: public, max-age=86400');

$img = imagecreatetruecolor($size, $size);
imageantialias($img, true);

/* ── Colours ── */
$bg      = imagecolorallocate($img, 26,   0,   0);   // #1a0000 deep red-black
$red     = imagecolorallocate($img, 192,  57,  43);   // #c0392b admin red
$redL    = imagecolorallocate($img, 231, 100,  89);   // lighter red highlight
$gold    = imagecolorallocate($img, 212, 175,  55);   // #d4af37 gold
$white   = imagecolorallocate($img, 255, 255, 255);
$shadow  = imagecolorallocate($img, 10,   0,   0);

/* ── Rounded square background ── */
$r = (int)($size * 0.18);
imagefilledrectangle($img, $r, 0, $size-$r, $size, $bg);
imagefilledrectangle($img, 0, $r, $size, $size-$r, $bg);
imagefilledellipse($img, $r,       $r,       $r*2, $r*2, $bg);
imagefilledellipse($img, $size-$r, $r,       $r*2, $r*2, $bg);
imagefilledellipse($img, $r,       $size-$r, $r*2, $r*2, $bg);
imagefilledellipse($img, $size-$r, $size-$r, $r*2, $r*2, $bg);

/* ── Gear / cog shape ──
   Draw as a circle with rectangular teeth around the outside */
$cx   = $size / 2;
$cy   = $size / 2;
$outerR = (int)($size * 0.36);   // outer radius incl teeth
$innerR = (int)($size * 0.24);   // inner radius of gear ring
$holeR  = (int)($size * 0.10);   // centre hole
$toothW = (int)($size * 0.09);   // tooth width
$toothH = (int)($size * 0.07);   // tooth height
$teeth  = 8;

// Shadow offset
$off = (int)($size * 0.025);

// Draw gear shadow
imagefilledellipse($img,
    (int)($cx+$off), (int)($cy+$off),
    $outerR*2, $outerR*2, $shadow);

// Draw gear teeth (shadow)
for ($i = 0; $i < $teeth; $i++) {
    $angle = deg2rad($i * (360 / $teeth));
    $tx = (int)($cx + $off + cos($angle) * ($outerR - $toothH/2));
    $ty = (int)($cy + $off + sin($angle) * ($outerR - $toothH/2));
    $pts = [
        (int)($tx + cos($angle + M_PI/2) * $toothW/2),
        (int)($ty + sin($angle + M_PI/2) * $toothW/2),
        (int)($tx - cos($angle + M_PI/2) * $toothW/2),
        (int)($ty - sin($angle + M_PI/2) * $toothW/2),
        (int)($tx - cos($angle + M_PI/2) * $toothW/2 + cos($angle) * $toothH),
        (int)($ty - sin($angle + M_PI/2) * $toothW/2 + sin($angle) * $toothH),
        (int)($tx + cos($angle + M_PI/2) * $toothW/2 + cos($angle) * $toothH),
        (int)($ty + sin($angle + M_PI/2) * $toothW/2 + sin($angle) * $toothH),
    ];
    imagefilledpolygon($img, $pts, $shadow);
}

// Draw main gear (red)
imagefilledellipse($img, (int)$cx, (int)$cy, $outerR*2, $outerR*2, $red);

// Draw gear teeth (red)
for ($i = 0; $i < $teeth; $i++) {
    $angle = deg2rad($i * (360 / $teeth));
    $tx = (int)($cx + cos($angle) * ($outerR - $toothH/2));
    $ty = (int)($cy + sin($angle) * ($outerR - $toothH/2));
    $pts = [
        (int)($tx + cos($angle + M_PI/2) * $toothW/2),
        (int)($ty + sin($angle + M_PI/2) * $toothW/2),
        (int)($tx - cos($angle + M_PI/2) * $toothW/2),
        (int)($ty - sin($angle + M_PI/2) * $toothW/2),
        (int)($tx - cos($angle + M_PI/2) * $toothW/2 + cos($angle) * $toothH),
        (int)($ty - sin($angle + M_PI/2) * $toothW/2 + sin($angle) * $toothH),
        (int)($tx + cos($angle + M_PI/2) * $toothW/2 + cos($angle) * $toothH),
        (int)($ty + sin($angle + M_PI/2) * $toothW/2 + sin($angle) * $toothH),
    ];
    imagefilledpolygon($img, $pts, $red);
}

// Gear highlight (lighter top-left area)
imagefilledellipse($img, (int)$cx, (int)$cy, $outerR*2, $outerR*2,
    imagecolorallocatealpha($img, 231, 100, 89, 90));

// Centre hole (background colour)
imagefilledellipse($img, (int)$cx, (int)$cy, $holeR*2, $holeR*2, $bg);

// Inner ring highlight
$bw = max(2, (int)($size * 0.015));
for ($i = 0; $i < $bw; $i++) {
    imageellipse($img, (int)$cx, (int)$cy,
        $outerR*2 - $i*2, $outerR*2 - $i*2, $gold);
}

/* ── Key icon overlaid bottom-right ── */
$kx  = (int)($cx + $size * 0.18);
$ky  = (int)($cy + $size * 0.18);
$kbr = (int)($size * 0.085);    // key bow radius
$kbl = (int)($size * 0.13);     // key blade length
$kbw = (int)($size * 0.025);    // key shaft width
$kth = max(2, (int)($size * 0.02)); // line thickness

// Key shadow
imagefilledellipse($img, $kx+2, $ky+2, $kbr*2, $kbr*2, $shadow);

// Key bow (circle)
imagefilledellipse($img, $kx, $ky, $kbr*2, $kbr*2, $gold);
imagefilledellipse($img, $kx, $ky,
    (int)($kbr*1.1), (int)($kbr*1.1), $bg);  // hole in bow

// Key shaft
$shaftAngle = deg2rad(45);
$sx1 = (int)($kx + cos($shaftAngle) * $kbr);
$sy1 = (int)($ky + sin($shaftAngle) * $kbr);
$sx2 = (int)($sx1 + cos($shaftAngle) * $kbl);
$sy2 = (int)($sy1 + sin($shaftAngle) * $kbl);

for ($t = -$kbw; $t <= $kbw; $t++) {
    imageline($img,
        (int)($sx1 + cos($shaftAngle + M_PI/2) * $t),
        (int)($sy1 + sin($shaftAngle + M_PI/2) * $t),
        (int)($sx2 + cos($shaftAngle + M_PI/2) * $t),
        (int)($sy2 + sin($shaftAngle + M_PI/2) * $t),
        $gold);
}

// Key teeth (two notches)
$notch1x = (int)($sx1 + cos($shaftAngle) * $kbl * 0.4);
$notch1y = (int)($sy1 + sin($shaftAngle) * $kbl * 0.4);
$notch2x = (int)($sx1 + cos($shaftAngle) * $kbl * 0.7);
$notch2y = (int)($sy1 + sin($shaftAngle) * $kbl * 0.7);
$notchLen = (int)($size * 0.035);
$notchAngle = $shaftAngle + M_PI/2;

for ($t = 0; $t <= $notchLen; $t++) {
    imagesetpixel($img,
        (int)($notch1x + cos($notchAngle) * $t),
        (int)($notch1y + sin($notchAngle) * $t), $gold);
    imagesetpixel($img,
        (int)($notch2x + cos($notchAngle) * $t),
        (int)($notch2y + sin($notchAngle) * $t), $gold);
}

/* ── Output ── */
imagepng($img);
imagedestroy($img);
