<?php
/*
 * generate synthetic WebP test data (no real images)
 * usage: php tests/data/make.php [outdir]   (default: this directory)
 *   ref) https://developers.google.com/speed/webp/docs/riff_container
 *        RFC 6386 9.1 (VP8 frame tag), WebP lossless bitstream 3.2 (VP8L header)
 */

$dir = isset($argv[1]) ? $argv[1] : dirname(__FILE__);

// RIFF chunk, odd-sized payload is followed by a pad byte
function chunk($fourcc, $data) {
    $c = $fourcc.pack('V', strlen($data)).$data;
    return (strlen($data) % 2) ? $c."\0" : $c;
}

function webp($chunks) {
    $body = 'WEBP'.$chunks;
    return 'RIFF'.pack('V', strlen($body)).$body;
}

function ui24le($v) {
    return substr(pack('V', $v), 0, 3);
}

// VP8 key frame header: 3-byte frame tag, start code, 14-bit size + 2-bit scale
function vp8($frame_type, $version, $show, $first_part_size, $w, $hs, $h, $vs, $payload) {
    $tag = $frame_type | ($version << 1) | ($show << 4) | ($first_part_size << 5);
    return ui24le($tag)."\x9d\x01\x2a".pack('v', $w | ($hs << 14)).pack('v', $h | ($vs << 14)).$payload;
}

// VP8L header: signature 0x2f, LSB-first width-1:14 height-1:14 alpha:1 version:3
function vp8l($sig, $w, $h, $alpha, $version, $payload) {
    $v = ($w - 1) | (($h - 1) << 14) | ($alpha << 28) | ($version << 29);
    return chr($sig).pack('V', $v).$payload;
}

// VP8X: flags byte (Rsv:2 I L E X A R), 3 reserved bytes, canvas width-1, height-1 (24-bit LE)
function vp8x($flags, $w, $h) {
    return chr($flags)."\0\0\0".ui24le($w - 1).ui24le($h - 1);
}

$files = array();

// lossy: VP8 with odd-sized payload (15 bytes + pad)
$files['lossy.webp'] = webp(chunk('VP8 ', vp8(0, 0, 1, 5, 320, 0, 240, 0, "\x11\x22\x33\x44\x55")));

// lossless: VP8L with alpha
$files['lossless.webp'] = webp(chunk('VP8L', vp8l(0x2f, 64, 32, 1, 0, "\x00\x01\x02")));

// extended with alpha: VP8X(L) + ALPH (odd) + VP8 (scales, version 1)
$alph = chr((1 << 4) | (2 << 2) | 1)."\xaa\xbb\xcc\xdd\xee\xff"; // P:1 F:2 C:1
$files['alpha.webp'] = webp(chunk('VP8X', vp8x(0x10, 320, 240)).
                            chunk('ALPH', $alph).
                            chunk('VP8 ', vp8(0, 1, 1, 3, 320, 3, 240, 1, "\x01\x02\x03\x04")));

// extended with metadata: VP8X(I,E,X) max canvas width, ICCP (odd), VP8L, EXIF (odd), XMP, unknown empty chunk
$files['meta.webp'] = webp(chunk('VP8X', vp8x(0x2c, 16777216, 1)).
                           chunk('ICCP', "icc01").
                           chunk('VP8L', vp8l(0x2f, 16384, 16384, 0, 0, "\x00\x00\x00")).
                           chunk('EXIF', "II*").
                           chunk('XMP ', "<x/>").
                           chunk('ABCD', ""));

// animation: VP8X(A,L) + ANIM + 2 ANMF frames, each holding a VP8L chunk
function anmf($x, $y, $w, $h, $duration, $flags, $frame) {
    return ui24le($x / 2).ui24le($y / 2).ui24le($w - 1).ui24le($h - 1).ui24le($duration).chr($flags).$frame;
}
$files['anim.webp'] = webp(chunk('VP8X', vp8x(0x12, 100, 80)).
                           chunk('ANIM', pack('V', 0xff0000ff).pack('v', 3)).
                           chunk('ANMF', anmf(0, 0, 100, 80, 100, 0, chunk('VP8L', vp8l(0x2f, 100, 80, 1, 0, "\x00\x00\x00")))).
                           chunk('ANMF', anmf(10, 20, 50, 40, 200, 3, chunk('VP8L', vp8l(0x2f, 50, 40, 1, 0, "\x00\x00\x00")))));

// VP8L with a wrong signature byte (parser warns on STDERR)
$files['badsig.webp'] = webp(chunk('VP8L', vp8l(0x2e, 8, 8, 0, 0, "\x00\x00\x00")));

// truncated inside the VP8 frame header (width field missing)
$files['truncated.webp'] = substr($files['lossy.webp'], 0, 20 + 7);

foreach ($files as $name => $data) {
    file_put_contents($dir.'/'.$name, $data);
}
