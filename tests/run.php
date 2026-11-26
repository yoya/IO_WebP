<?php
/*
 * regression tests (no dependency)
 * usage: php tests/run.php
 *   IO_Bit is loaded from vendor/ (composer install) or include_path.
 */

$root = dirname(dirname(__FILE__));
if (is_readable($root.'/vendor/autoload.php')) {
    require_once $root.'/vendor/autoload.php';
}
set_include_path($root.PATH_SEPARATOR.get_include_path());
require_once 'IO/WebP.php';

$failed = 0;
$passed = 0;

function check($name, $expect, $actual) {
    global $failed, $passed;
    if ($expect === $actual) {
        $passed++;
        return;
    }
    $failed++;
    echo "FAIL: $name".PHP_EOL;
    echo "  expect: ".var_export($expect, true).PHP_EOL;
    echo "  actual: ".var_export($actual, true).PHP_EOL;
}

// RIFF chunk, padded to even length
function chunk($fourcc, $data) {
    $c = $fourcc.pack('V', strlen($data)).$data;
    return (strlen($data) % 2) ? $c."\0" : $c;
}

function webp($chunks) {
    $body = 'WEBP'.$chunks;
    return 'RIFF'.pack('V', strlen($body)).$body;
}

function parseWebP($data) {
    $webp = new IO_WebP();
    $webp->parse($data);
    return $webp;
}

/*
 * VP8: 14-bit width / height
 */
$tag = 0 | (1 << 4) | (10 << 5); // key frame, show_frame, first_part_size:10
$vp8 = substr(pack('V', $tag), 0, 3)."\x9d\x01\x2a".pack('v', 2000 | (1 << 14)).pack('v', 1500 | (2 << 14))."\0\0";
$webp = parseWebP(webp(chunk('VP8 ', $vp8)));
$c = $webp->ChunkList[0];
check('VP8 width', 2000, $c['width']);
check('VP8 height', 1500, $c['height']);
check('VP8 horizontal_scale', 1, $c['horizontal_scale']);
check('VP8 vertical_scale', 2, $c['vertical_scale']);

echo "passed: $passed, failed: $failed".PHP_EOL;
exit($failed ? 1 : 0);
