<?php

declare(strict_types=1);

// Unit test bootstrap - load project classes without prepend.php
// (prepend.php starts a session and connects to the DB; unit tests must not)

$projectRoot = dirname(__DIR__, 2);

spl_autoload_register(function ($class) use ($projectRoot) {
    $class = ltrim($class, '\\');
    $path = str_replace(['\\', '_'], DIRECTORY_SEPARATOR, $class);
    $file = $projectRoot . '/classes/' . $path . '.php';

    if (file_exists($file)) {
        require_once $file;
    }
    // Return silently if file not found (let other autoloaders handle it)
});

// Shared test helpers such as Tests\Unit\ResumeFixture live beside the tests.
// Codeception loads *Test.php files itself, so only helpers come through here.
spl_autoload_register(function ($class) use ($projectRoot) {
    $prefix = 'Tests\\Unit\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $file = $projectRoot . '/Tests/Unit/' . substr($class, strlen($prefix)) . '.php';
    if (file_exists($file)) {
        require_once $file;
    }
});

// The real Config.php is per-site and gitignored; in a fresh clone only the
// sample exists. Prefer a real Config (autoloaded above), else load the
// sample, which declares the same \Config\Config class.
if (!class_exists(\Config\Config::class)) {
    require_once $projectRoot . '/classes/Config/ConfigSample.php';
}
