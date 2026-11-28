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

function checkThrows($name, $func) {
    global $failed, $passed;
    try {
        $func();
    } catch (Exception $e) {
        $passed++;
        return;
    }
    $failed++;
    echo "FAIL: $name (exception not thrown)".PHP_EOL;
}

// run a php script in a child process (same include_path, E_ALL), killed after $timeout seconds
function runPHP($args, $timeout = 10) {
    $cmd = escapeshellarg(PHP_BINARY).' -d include_path='.escapeshellarg(get_include_path()).
         ' -d error_reporting=-1 -d display_errors=stderr '.join(' ', array_map('escapeshellarg', $args));
    $proc = proc_open($cmd, array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);
    $out = $err = '';
    $deadline = microtime(true) + $timeout;
    while (true) {
        $out .= stream_get_contents($pipes[1]);
        $err .= stream_get_contents($pipes[2]);
        $st = proc_get_status($proc);
        if (! $st['running']) {
            break;
        }
        if (microtime(true) > $deadline) {
            proc_terminate($proc, 9);
            $err .= 'TIMEOUT';
            break;
        }
        usleep(10000);
    }
    $out .= stream_get_contents($pipes[1]);
    $err .= stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($proc);
    return array($st['running'] ? -1 : $st['exitcode'], $out, $err);
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

/*
 * VP8X: canvas width / height are stored minus one
 */
$vp8x = chr(0x08)."\0\0\0".substr(pack('V', 639), 0, 3).substr(pack('V', 479), 0, 3);
$webp = parseWebP(webp(chunk('VP8X', $vp8x)));
$c = $webp->ChunkList[0];
check('VP8X E(exif)', 1, $c['E']);
check('VP8X image_width', 640, $c['image_width']);
check('VP8X image_height', 480, $c['image_height']);

/*
 * RIFF: odd-sized chunk is followed by a pad byte
 */
$webp = parseWebP(webp(chunk('VP8X', $vp8x).chunk('EXIF', 'abc').chunk('VP8 ', $vp8)));
check('odd chunk: count', 3, count($webp->ChunkList));
check('odd chunk: EXIF', array('Header' => 'EXIF', 'TagSize' => 3), $webp->ChunkList[1]);
check('odd chunk: next header', 'VP8 ', $webp->ChunkList[2]['Header']);
check('odd chunk: next width', 2000, $webp->ChunkList[2]['width']);

/*
 * VP8L: signature byte, then LSB-first 14-bit width-1, 14-bit height-1, alpha, version
 */
function vp8l($sig, $w, $h, $alpha, $version) {
    $v = ($w - 1) | (($h - 1) << 14) | ($alpha << 28) | ($version << 29);
    return chr($sig).pack('V', $v)."\0\0\0";
}
$c = parseWebP(webp(chunk('VP8L', vp8l(0x2f, 100, 50, 1, 0))))->ChunkList[0];
check('VP8L signature', 0x2f, $c['signature']);
check('VP8L image_width', 100, $c['image_width']);
check('VP8L image_height', 50, $c['image_height']);
check('VP8L alpha_is_used', 1, $c['alpha_is_used']);
check('VP8L version_number', 0, $c['version_number']);
$c = parseWebP(webp(chunk('VP8L', vp8l(0x2f, 16384, 1, 0, 5))))->ChunkList[0];
check('VP8L max width', 16384, $c['image_width']);
check('VP8L alpha_is_used 0', 0, $c['alpha_is_used']);
check('VP8L version_number 5', 5, $c['version_number']);
ob_start();
parseWebP(webp(chunk('VP8L', vp8l(0x2f, 640, 480, 0, 0))))->dump(array());
check('VP8L dump', 1, preg_match('/Header:VP8L TagSize:8\n signature:0x2f image_width:640 image_height:480/', ob_get_clean()));

/*
 * test data (tests/data/make.php)
 */
$dataDir = $root.'/tests/data';
function dataFile($name) {
    global $dataDir;
    return file_get_contents($dataDir.'/'.$name);
}
function headers($webp) {
    return array_map(function ($c) { return $c['Header'].':'.$c['TagSize']; }, $webp->ChunkList);
}

// make.php regenerates the committed files byte-identically
$tmpDir = sys_get_temp_dir().'/io_webp_test_'.getmypid();
mkdir($tmpDir);
list($status, $out, $err) = runPHP(array($dataDir.'/make.php', $tmpDir));
check('make.php status', array(0, ''), array($status, $err));
$dataNames = array('lossy.webp', 'lossless.webp', 'alpha.webp', 'meta.webp', 'anim.webp', 'badsig.webp', 'truncated.webp');
foreach ($dataNames as $name) {
    check("make.php $name", md5(dataFile($name)), md5((string) @file_get_contents("$tmpDir/$name")));
    @unlink("$tmpDir/$name");
}
rmdir($tmpDir);

// lossy.webp: VP8 key frame (frame type bit 0), 320x240, odd payload + pad
$webp = parseWebP(dataFile('lossy.webp'));
check('lossy FileHeader', array('FourCC' => 'RIFF', 'Size' => 28, 'Signature' => 'WEBP'), $webp->FileHeader);
check('lossy chunk', array('Header' => 'VP8 ', 'TagSize' => 15,
                           'key_frame' => 0, 'version' => 0, 'show_frame' => 1, 'first_part_size' => 5,
                           'width' => 320, 'horizontal_scale' => 0, 'height' => 240, 'vertical_scale' => 0),
      $webp->ChunkList[0]);
check('lossy count', 1, count($webp->ChunkList));

// lossless.webp
$webp = parseWebP(dataFile('lossless.webp'));
check('lossless FileHeader Size', 20, $webp->FileHeader['Size']);
check('lossless chunk', array('Header' => 'VP8L', 'TagSize' => 8, 'signature' => 0x2f,
                              'image_width' => 64, 'image_height' => 32, 'alpha_is_used' => 1, 'version_number' => 0),
      $webp->ChunkList[0]);

// alpha.webp: VP8X(L) + ALPH + VP8
$webp = parseWebP(dataFile('alpha.webp'));
check('alpha headers', array('VP8X:10', 'ALPH:7', 'VP8 :14'), headers($webp));
$c = $webp->ChunkList[0];
check('alpha VP8X flags', array(0, 0, 1, 0, 0, 0, 0, 0),
      array($c['Rsv'], $c['I'], $c['L'], $c['E'], $c['X'], $c['A'], $c['R'], $c['Reserved']));
check('alpha VP8X canvas', array(320, 240), array($c['image_width'], $c['image_height']));
$c = $webp->ChunkList[1];
check('alpha ALPH', array(0, 1, 2, 1), array($c['Rsv'], $c['P'], $c['F'], $c['C']));
$c = $webp->ChunkList[2];
check('alpha VP8 version/scale', array(1, 3, 320, 3, 240, 1),
      array($c['version'], $c['first_part_size'], $c['width'], $c['horizontal_scale'], $c['height'], $c['vertical_scale']));

// meta.webp: VP8X(I,E,X), 24-bit max canvas, odd metadata chunks, unknown empty chunk
$webp = parseWebP(dataFile('meta.webp'));
check('meta headers', array('VP8X:10', 'ICCP:5', 'VP8L:8', 'EXIF:3', 'XMP :4', 'ABCD:0'), headers($webp));
$c = $webp->ChunkList[0];
check('meta VP8X flags', array(1, 0, 1, 1, 0), array($c['I'], $c['L'], $c['E'], $c['X'], $c['A']));
check('meta VP8X canvas', array(16777216, 1), array($c['image_width'], $c['image_height']));
$c = $webp->ChunkList[2];
check('meta VP8L max size', array(16384, 16384, 0), array($c['image_width'], $c['image_height'], $c['alpha_is_used']));
check('meta ICCP', array('Header' => 'ICCP', 'TagSize' => 5), $webp->ChunkList[1]);
check('meta unknown', array('Header' => 'ABCD', 'TagSize' => 0), $webp->ChunkList[5]);

// anim.webp: ANMF payload (frame header + VP8L) is not descended into
$webp = parseWebP(dataFile('anim.webp'));
check('anim headers', array('VP8X:10', 'ANIM:6', 'ANMF:32', 'ANMF:32'), headers($webp));
$c = $webp->ChunkList[0];
check('anim VP8X flags', array(0, 1, 0, 0, 1), array($c['I'], $c['L'], $c['E'], $c['X'], $c['A']));
check('anim VP8X canvas', array(100, 80), array($c['image_width'], $c['image_height']));
check('anim FileHeader Size', strlen(dataFile('anim.webp')) - 8, $webp->FileHeader['Size']);

// broken input
checkThrows('truncated VP8 header', function () { parseWebP(dataFile('truncated.webp')); });
checkThrows('truncated RIFF header', function () { parseWebP(substr(dataFile('lossy.webp'), 0, 10)); });
checkThrows('truncated chunk size', function () { parseWebP(substr(dataFile('lossy.webp'), 0, 18)); });
checkThrows('truncated VP8L header', function () { parseWebP(substr(dataFile('lossless.webp'), 0, 22)); });
checkThrows('truncated VP8X header', function () { parseWebP(substr(dataFile('alpha.webp'), 0, 28)); });

// dump() on every parsable file: no warnings / notices
foreach (array('lossy.webp', 'lossless.webp', 'alpha.webp', 'meta.webp', 'anim.webp') as $name) {
    $errors = array();
    set_error_handler(function ($no, $str) use (&$errors) { $errors []= $str; return true; });
    ob_start();
    parseWebP(dataFile($name))->dump(array());
    $out = ob_get_clean();
    restore_error_handler();
    check("dump $name no warnings", array(), $errors);
    check("dump $name header line", 1, preg_match('/^FourCC:RIFF Size:\d+ Signature:WEBP\n/', $out));
}
ob_start();
parseWebP(dataFile('alpha.webp'))->dump(array());
$out = ob_get_clean();
check('dump alpha VP8X', 1, preg_match('/Header:VP8X TagSize:10\n    I\(icc\):0 L\(alpha\):1 E\(exif\):0 X\(xmp\):0 A\(anim\):0\n    image_width:320 image_height:240\n/', $out));
check('dump alpha ALPH', 1, preg_match('/Header:ALPH TagSize:7\n    P\(process\):1 F\(filter\):2 C\(compress\):1\n/', $out));
check('dump alpha VP8', 1, preg_match('/Header:VP8  TagSize:14\n    key_frame:0 version:1 show_frame:1 first_part_size:3\n    width:320 horizontal_scale:3 height:240 vertical_scale:1\n/', $out));

// sample/webpdump.php
foreach (array('lossy.webp', 'lossless.webp', 'alpha.webp', 'meta.webp', 'anim.webp') as $name) {
    list($status, $out, $err) = runPHP(array($root.'/sample/webpdump.php', '-f', $dataDir.'/'.$name));
    check("webpdump $name status/stderr", array(0, ''), array($status, $err));
    check("webpdump $name output", 1, preg_match('/^FourCC:RIFF Size:\d+ Signature:WEBP\nHeader:/', $out));
}
list($status, $out, $err) = runPHP(array($root.'/sample/webpdump.php', '-f', $dataDir.'/meta.webp'));
check('webpdump meta chunks', 1, preg_match('/Header:ICCP TagSize:5\n.*Header:EXIF TagSize:3\n+Header:XMP  TagSize:4\n+Header:ABCD TagSize:0\n+$/s', $out));
list($status, $out, $err) = runPHP(array($root.'/sample/webpdump.php', '-f', $dataDir.'/badsig.webp'));
check('webpdump badsig status', 0, $status);
check('webpdump badsig warning', 1, preg_match('/warning: VP8L invalid signature/', $err));
check('webpdump badsig output', 1, preg_match('/ signature:0x2e image_width:8 image_height:8/', $out));
list($status, $out, $err) = runPHP(array($root.'/sample/webpdump.php'));
check('webpdump usage', array(1, 1), array($status, preg_match('/^Usage:/', $out)));

echo "passed: $passed, failed: $failed".PHP_EOL;
exit($failed ? 1 : 0);
