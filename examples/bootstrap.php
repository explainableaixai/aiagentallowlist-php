<?php

// Loads Composer's autoloader when the package is installed, otherwise a tiny PSR-4 fallback
// so the examples run straight from a git checkout.

declare(strict_types=1);

$composer = __DIR__ . '/../vendor/autoload.php';
if (is_file($composer)) {
    require $composer;
} else {
    spl_autoload_register(static function (string $class): void {
        $prefix = 'AIAgentAllowlist\\';
        if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
            return;
        }
        $file = __DIR__ . '/../src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($file)) {
            require $file;
        }
    });
}

function aal_api_key(): string
{
    $key = getenv('AIAGENTALLOWLIST_API_KEY');
    if ($key === false || $key === '') {
        fwrite(STDERR, "Set AIAGENTALLOWLIST_API_KEY to the key from your aiagentallowlist.com account.\n");
        exit(2);
    }
    return $key;
}
