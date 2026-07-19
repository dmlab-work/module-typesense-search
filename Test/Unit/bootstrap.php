<?php
/**
 * Copyright © MageDevGroup. All rights reserved.
 *
 * Standalone unit-test bootstrap: loads Magento's Composer autoloader (for the
 * framework classes this module depends on), registers a PSR-4 map for this
 * module and its sibling MageDevGroup deps so their classes resolve without a
 * full `composer install`, then runs the module registration.
 */
declare(strict_types=1);

$moduleRoot = dirname(__DIR__, 2);
$modulesDir = dirname($moduleRoot);

$candidates = [
    getenv('MAGENTO_ROOT') ? rtrim((string)getenv('MAGENTO_ROOT'), '/') . '/vendor/autoload.php' : null,
    '/var/www/html/vendor/autoload.php',
    $moduleRoot . '/vendor/autoload.php',
    $moduleRoot . '/../../src/vendor/autoload.php',
];

$autoloaderLoaded = false;
foreach ($candidates as $candidate) {
    if ($candidate !== null && is_file($candidate)) {
        require $candidate;
        $autoloaderLoaded = true;
        break;
    }
}

if (!$autoloaderLoaded) {
    fwrite(STDERR, "Unable to locate a Composer autoloader (set MAGENTO_ROOT).\n");
    // phpcs:ignore Magento2.Security.LanguageConstruct.ExitUsage
    exit(1);
}

// PSR-4 for this module and its sibling MageDevGroup deps (typesense-indexer
// provides the contracts we consume, and it in turn depends on typesense-core),
// so all resolve without a full `composer install` in the test environment.
$psr4 = [
    'MageDevGroup\\TypesenseSearch\\' => $moduleRoot,
    'MageDevGroup\\TypesenseIndexer\\' => $modulesDir . '/module-typesense-indexer',
    'MageDevGroup\\TypesenseCore\\' => $modulesDir . '/module-typesense-core',
];

spl_autoload_register(static function (string $class) use ($psr4): void {
    foreach ($psr4 as $prefix => $base) {
        if (!str_starts_with($class, $prefix)) {
            continue;
        }
        $relative = str_replace('\\', '/', substr($class, strlen($prefix)));
        $file = $base . '/' . $relative . '.php';
        if (is_file($file)) {
            require $file;
        }

        return;
    }
});

// When the module is composer-installed, Magento's autoloader has already run its
// registration.php; re-requiring it would throw "already defined". Swallow that so the
// suite runs whether or not the module is installed.
try {
    require $moduleRoot . '/registration.php';
} catch (\LogicException $e) {
    // Already registered by the Composer autoloader — nothing to do.
}
