<?php

$file = getcwd().'/cinder.php';
$contents = file_get_contents($file);
$prefixed = is_dir(getcwd().'/vendor-prefixed') ? 'vendor-prefixed' : 'vendor_prefixed';

if (str_contains($contents, $prefixed.'/autoload.php')) {
    exit(0);
}

$contents = str_replace(
    "__DIR__.'/vendor/autoload.php',",
    "__DIR__.'/{$prefixed}/autoload.php',".PHP_EOL."    __DIR__.'/vendor/autoload.php',",
    $contents,
);

file_put_contents($file, $contents);
