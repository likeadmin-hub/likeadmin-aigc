<?php

declare(strict_types=1);

/**
 * Standalone Composer autoload consistency check. It intentionally does not
 * load the application bootstrap, so it can diagnose a broken vendor runtime.
 */
$root = is_dir(__DIR__ . '/vendor') ? __DIR__ : dirname(__DIR__);
$vendor = $root . '/vendor';
$generation = 'canonical';
$pointerFile = $root . '/.composer-runtime/current.json';
if (!in_array('--canonical', $argv ?? [], true) && is_file($pointerFile)) {
    $pointer = json_decode((string)file_get_contents($pointerFile), true);
    $generation = (string)($pointer['generation'] ?? '');
    if (($pointer['schema'] ?? null) !== 1 || !preg_match('/^(?:baseline|release)-[a-f0-9]{24,64}$/', $generation)) {
        fwrite(STDERR, "Composer runtime pointer is invalid.\n"); exit(1);
    }
    $directory = $root . '/.composer-runtime/generations/' . $generation;
    $metadata = json_decode((string)@file_get_contents($directory . '/generation.json'), true);
    if (is_link($directory) || !is_array($metadata) || ($metadata['schema'] ?? null) !== 1) {
        fwrite(STDERR, "Composer runtime generation is invalid.\n"); exit(1);
    }
    if (($metadata['vendor'] ?? '') !== 'site') $vendor = $directory . '/vendor';
}
$autoload = $vendor . '/autoload.php';
$autoloadReal = $vendor . '/composer/autoload_real.php';

foreach ([$autoload, $autoloadReal] as $path) {
    if (!is_file($path) || !is_readable($path)) {
        fwrite(STDERR, "Composer runtime file is missing or unreadable: {$path}\n");
        exit(1);
    }
}

$autoloadContent = (string)file_get_contents($autoload);
$autoloadRealContent = (string)file_get_contents($autoloadReal);
$pattern = '/ComposerAutoloaderInit([A-Za-z0-9_]+)/';
preg_match($pattern, $autoloadContent, $autoloadMatch);
preg_match($pattern, $autoloadRealContent, $autoloadRealMatch);

if (empty($autoloadMatch[1]) || empty($autoloadRealMatch[1]) || $autoloadMatch[1] !== $autoloadRealMatch[1]) {
    fwrite(STDERR, "Composer autoload runtime is inconsistent. Replace the complete vendor directory atomically.\n");
    exit(1);
}

require $autoload;
echo "Composer autoload runtime is valid ({$generation}).\n";
