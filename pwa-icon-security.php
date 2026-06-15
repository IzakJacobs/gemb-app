<?php
/**
 * pwa-icon-security.php
 * Generates a purple shield PWA icon for the Site Manager portal.
 * Usage: pwa-icon-security.php?size=192  or  ?size=512
 */
$size = (int)($_GET['size'] ?? 192);
if (!in_array($size, [96, 192, 512])) $size = 192;

header('Content-Type: image/png');
header('Cache-Control: public, max-age=86400');

$img = imagecreatetruecolor($size, $size);
imageantialias($img, true);

/* ── Colours ── */
$bg       = imagecolorallocate($img, 26,  10,  46);   // #1a0a2e deep purple-black
$purple   = imagecolorallocate($img, 142, 68, 173);   // #8e44ad
$purpleL  = imagecolorallocate($img, 187,143,206);    // lighter purple highlight
$gold     = imagecolorallocate($img, 212,175, 55);    // #d4af37 gold accent
$white    = imagecolorallocate($img, 255,255,255);
$shadow   = imagecolorallocate($img, 15,  5,  30);    // dark shadow

/* ── Background — rounded square ── */
$r = (int)($size * 0.18);
imagefilledrectangle($img, $r, 0, $size-$r, $size, $bg);
imagefilledrectangle($img, 0, $r, $size, $size-$r, $bg);
imagefilledellipse($img, $r,        $r,        $r*2, $r*2, $bg);
imagefilledellipse($img, $size-$r,  $r,        $r*2, $r*2, $bg);
imagefilledellipse($img, $r,        $size-$r,  $r*2, $r*2, $bg);
imagefilledellipse($img, $size-$r,  $size-$r,  $r*2, $r*2, $bg);

/* ── Shield shape ──
   Build as a filled polygon: wide at top, pointed at bottom */
$cx = $size / 2;
$s  = $size * 0.72;   // shield overall scale
$tx = $cx - $s/2;     // left x
$ty = $size * 0.14;   // top y

// Shadow offset shield
$off = (int)($size * 0.025);
$shieldShadow = [
    (int)($tx + $off),           (int)($ty + $off),
    (int)($tx + $s + $off),      (int)($ty + $off),
    (int)($tx + $s + $off),      (int)($ty + $s*0.55 + $off),
    (int)($cx + $off),           (int)($ty + $s + $off),
    (int)($tx + $off),           (int)($ty + $s*0.55 + $off),
];
imagefilledpolygon($img, $shieldShadow, $shadow);

// Main shield fill
$shield = [
    (int)$tx,          (int)$ty,
    (int)($tx + $s),   (int)$ty,
    (int)($tx + $s),   (int)($ty + $s*0.55),
    (int)$cx,          (int)($ty + $s),
    (int)$tx,          (int)($ty + $s*0.55),
];
imagefilledpolygon($img, $shield, $purple);

// Shield highlight (top-left lighter area)
$highlight = [
    (int)($tx + $size*0.04),          (int)($ty + $size*0.03),
    (int)($cx - $size*0.02),          (int)($ty + $size*0.03),
    (int)($cx - $size*0.02),          (int)($ty + $s*0.52),
    (int)($tx + $size*0.04),          (int)($ty + $s*0.52),
];
imagefilledpolygon($img, $highlight, $purpleL);
imagefilledpolygon($img, $shield, imagecolorallocatealpha($img, 255,255,255, 110));

// Shield border (gold)
$bw = max(2, (int)($size * 0.018));
for ($i = 0; $i < $bw; $i++) {
    $bShield = [
        (int)($tx-$i),         (int)($ty-$i),
        (int)($tx+$s+$i),      (int)($ty-$i),
        (int)($tx+$s+$i),      (int)($ty+$s*0.55),
        (int)$cx,              (int)($ty+$s+$i*1.4),
        (int)($tx-$i),         (int)($ty+$s*0.55),
    ];
    imagepolygon($img, $bShield, $gold);
}

/* ── Lock icon in centre of shield ── */
$lx  = (int)$cx;
$ly  = (int)($ty + $s * 0.38);
$lw  = (int)($s * 0.28);   // lock body width
$lh  = (int)($s * 0.22);   // lock body height
$lbw = (int)($lw * 0.55);  // shackle width
$lbh = (int)($lh * 0.55);  // shackle height
$lt  = max(2, (int)($size * 0.022)); // line thickness

// Shackle (arc on top)
for ($t = 0; $t < $lt; $t++) {
    imagearc($img,
        $lx, $ly - (int)($lh*0.18),
        $lbw*2 - $t*2, $lbh*2 - $t*2,
        180, 0,
        $white
    );
}

// Lock body (rounded rectangle)
imagefilledrectangle($img,
    $lx - (int)($lw/2),
    $ly,
    $lx + (int)($lw/2),
    $ly + $lh,
    $white
);
// Round the lock body corners
$cr = (int)($lw * 0.18);
imagefilledellipse($img, $lx-(int)($lw/2)+$cr, $ly+$cr,              $cr*2, $cr*2, $white);
imagefilledellipse($img, $lx+(int)($lw/2)-$cr, $ly+$cr,              $cr*2, $cr*2, $white);
imagefilledellipse($img, $lx-(int)($lw/2)+$cr, $ly+$lh-$cr,         $cr*2, $cr*2, $white);
imagefilledellipse($img, $lx+(int)($lw/2)-$cr, $ly+$lh-$cr,         $cr*2, $cr*2, $white);

// Keyhole
$kh = (int)($lh * 0.35);
imagefilledellipse($img, $lx, $ly + (int)($lh*0.35), $kh, $kh, $purple);
imagefilledpolygon($img, [
    $lx - (int)($kh*0.3), $ly + (int)($lh*0.42),
    $lx + (int)($kh*0.3), $ly + (int)($lh*0.42),
    $lx + (int)($kh*0.18),$ly + (int)($lh*0.78),
    $lx - (int)($kh*0.18),$ly + (int)($lh*0.78),
], $purple);

/* ── Gold star badge top-right ── */
$sx = (int)($size * 0.76);
$sy = (int)($size * 0.18);
$sr = (int)($size * 0.09);
$starPts = [];
for ($i = 0; $i < 10; $i++) {
    $angle = deg2rad($i * 36 - 90);
    $rad   = ($i % 2 === 0) ? $sr : $sr * 0.45;
    $starPts[] = (int)($sx + cos($angle) * $rad);
    $starPts[] = (int)($sy + sin($angle) * $rad);
}
imagefilledpolygon($img, $starPts, $gold);

/* ── Output ── */
imagepng($img);
imagedestroy($img);
