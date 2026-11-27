<?php

if ($argc < 2) {
    fwrite(STDERR, "Usage: prepare-build-composer.php <composer.json>\n");
    exit(1);
}

$path = $argv[1];

/** @var array<string, mixed> $data */
$data = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

$data['repositories'] = [
    [
        'type' => 'path',
        'url' => 'packages/cinder-php',
        'options' => ['symlink' => false],
    ],
];

unset($data['require-dev']);

$data['require']['cinderwatch/cinder-php'] = '*';

$cinderPhpComposer = dirname($path).'/packages/cinder-php/composer.json';

if (is_file($cinderPhpComposer)) {
    /** @var array<string, mixed> $cinderPhp */
    $cinderPhp = json_decode(file_get_contents($cinderPhpComposer), true, 512, JSON_THROW_ON_ERROR);
    $cinderPhp['version'] = '0.0.1';
    file_put_contents(
        $cinderPhpComposer,
        json_encode($cinderPhp, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n"
    );
}

file_put_contents(
    $path,
    json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n"
);
