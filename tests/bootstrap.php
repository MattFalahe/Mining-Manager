<?php

// The tests run without SeAT or a database, so only code that needs neither is
// loaded: the sums, the rules and the source files themselves.

require __DIR__ . '/vendor/autoload.php';

spl_autoload_register(function (string $class): void {
    foreach (['MiningManager\\Tests\\' => __DIR__ . '/', 'MiningManager\\' => dirname(__DIR__) . '/src/'] as $prefix => $root) {
        if (strncmp($class, $prefix, strlen($prefix)) === 0) {
            $file = $root . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
            if (is_file($file)) {
                require $file;
            }

            return;
        }
    }
});
