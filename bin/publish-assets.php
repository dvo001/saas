<?php

declare(strict_types=1);

$projectDirectory = dirname(__DIR__);
$source = $projectDirectory . '/vendor/twbs/bootstrap/dist/css/bootstrap.min.css';
$targetDirectory = $projectDirectory . '/public/assets/vendor/bootstrap';
$target = $targetDirectory . '/bootstrap.min.css';

if (!is_file($source)) {
    fwrite(STDERR, "Bootstrap asset not found. Run composer install first.\n");
    exit(1);
}

if (!is_dir($targetDirectory) && !mkdir($targetDirectory, 0775, true) && !is_dir($targetDirectory)) {
    fwrite(STDERR, "Could not create public asset directory.\n");
    exit(1);
}

if (!copy($source, $target)) {
    fwrite(STDERR, "Could not publish Bootstrap CSS.\n");
    exit(1);
}

fwrite(STDOUT, "Published Bootstrap CSS.\n");
