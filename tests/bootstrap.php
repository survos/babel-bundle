<?php
declare(strict_types=1);

// Composer autoload
$autoload = __DIR__ . '/../../vendor/autoload.php';
if (!is_file($autoload)) {
    $autoload = __DIR__ . '/../vendor/autoload.php';
}
require_once $autoload;

// PSR-4 autoload for Survos\BabelBundle\Tests\
spl_autoload_register(function (string $class): void {
    $prefix = 'Survos\\BabelBundle\\Tests\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $relative = str_replace('\\', '/', substr($class, strlen($prefix)));
    $file = __DIR__ . '/' . $relative . '.php';
    if (is_file($file)) {
        require $file;
    }
});
