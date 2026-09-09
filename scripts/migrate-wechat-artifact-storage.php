<?php

declare(strict_types=1);

/**
 * Move legacy public mini-program artifacts into the private archive root.
 * This operation is idempotent and never removes a conflicting directory.
 */
$serverRoot = dirname(__DIR__);
$publicRoot = $serverRoot . '/public';
$archiveRoot = $serverRoot . '/runtime/wechat-artifacts';

if (!is_dir($publicRoot)) {
    fwrite(STDERR, "public directory not found: {$publicRoot}\n");
    exit(1);
}
if (!is_dir($archiveRoot) && !mkdir($archiveRoot, 0750, true) && !is_dir($archiveRoot)) {
    fwrite(STDERR, "cannot create archive directory: {$archiveRoot}\n");
    exit(1);
}

$moved = 0;
$skipped = 0;
foreach (glob($publicRoot . '/mp-weixin.pre-release-*', GLOB_ONLYDIR) ?: [] as $source) {
    $name = basename($source);
    if (!preg_match('/^mp-weixin\.pre-release-(\d+\.\d+\.\d+)$/', $name, $match)) {
        continue;
    }
    $version = $match[1];
    $target = $archiveRoot . '/' . $version;
    if (is_dir($target)) {
        $skipped++;
        fwrite(STDOUT, "skip existing archive: {$version}\n");
        continue;
    }
    if (!rename($source, $target)) {
        fwrite(STDERR, "failed to move {$source} to {$target}\n");
        exit(1);
    }
    $moved++;
    fwrite(STDOUT, "moved {$version}\n");
}

fwrite(STDOUT, "migration complete: moved={$moved}, skipped={$skipped}\n");
