<?php

if (is_readable('vendor/autoload.php')) {
    require 'vendor/autoload.php';
} else {
    require_once 'IO/WebP.php';
}

$options = getopt("f:h");

function usage() {
    echo "Usage: php webpdump.php [-h] -f <webpfile>".PHP_EOL;
}

if ((isset($options['f']) === false) ||
    (is_readable($options['f']) === false)) {
    usage();
    exit(1);
}
$opts = array();

$opts['hexdump'] = isset($options['h']);

$webpfile = $options['f'];
$webpdata = file_get_contents($webpfile);

$webp = new IO_WebP();
$webp->parse($webpdata);

$webp->dump($opts);

exit(0);
