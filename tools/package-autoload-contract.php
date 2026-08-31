<?php

declare(strict_types=1);

/** Ensure optional package autoloaders are registered in shadow-safe order. */
$source = file_get_contents(dirname(__DIR__) . '/public/index.php');
if ($source === false) {
    fwrite(STDERR, "Unable to read public/index.php.\n");
    exit(1);
}

$explaining = strpos($source, 'private/mathphp-explaining/vendor/autoload.php');
$visuals = strpos($source, 'private/mathphp-visuals/vendor/autoload.php');
if ($explaining === false || $visuals === false || $explaining >= $visuals) {
    fwrite(STDERR, "Explaining must be registered before standalone Visuals.\n");
    exit(1);
}

echo "Optional package autoload order is shadow-safe.\n";
