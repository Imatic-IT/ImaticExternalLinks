<?php

declare(strict_types=1);

/**
 * Minimal PSR-4 autoloader for the ImaticExternalLinks\ namespace, mapping it to
 * this inc/ directory. Registered once; safe to require multiple times.
 */
(static function (): void {
    static $registered = false;
    if ($registered) {
        return;
    }
    $registered = true;

    spl_autoload_register(static function (string $class): void {
        $t_prefix = 'ImaticExternalLinks\\';
        if (strpos($class, $t_prefix) !== 0) {
            return;
        }
        $t_relative = substr($class, strlen($t_prefix));
        $t_path     = __DIR__ . '/' . str_replace('\\', '/', $t_relative) . '.php';
        if (is_file($t_path)) {
            require_once $t_path;
        }
    });
})();
