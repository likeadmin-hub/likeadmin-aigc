<?php

declare(strict_types=1);

/**
 * Standalone Composer autoload consistency check. It intentionally does not
 * load the application bootstrap, so it can diagnose a broken vendor runtime.
 */
$root = is_dir(__DIR__ . '/vendor') ? __DIR__ : dirname(__DIR__);
$autoload = $root . '/vendor/autoload.php';
$autoloadReal = $root . '/vendor/composer/autoload_real.php';

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
echo "Composer autoload runtime is valid.\n";
