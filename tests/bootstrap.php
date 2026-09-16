<?php

declare(strict_types=1);

$autoload = is_file(__DIR__.'/../vendor/autoload.php') ? __DIR__.'/../vendor/autoload.php' : __DIR__.'/../../../vendor/autoload.php';
require $autoload;
spl_autoload_register(static function (string $class): void {
    $prefix = 'Survos\\FollowTheMoney\\';
    if (str_starts_with($class, $prefix)) {
        $path = __DIR__.'/../src/'.str_replace('\\', '/', substr($class, strlen($prefix))).'.php';
        if (is_file($path)) {
            require $path;
        }
    }
});
