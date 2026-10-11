<?php

/**
 * Replaces the résumé tables with the JSON document on stdin. The JSON lives
 * in the private data repo, never in this one.
 *
 *   ssh resume 'php resume.robnugen.com/bin/import_resume.php' < resume.json
 *   php bin/import_resume.php --check < resume.json
 *
 * --check imports into a throwaway in-memory SQLite instead, so a document
 * can be validated anywhere with no Config.php and no database.
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/classes/Mlaphp/Autoloader.php';
spl_autoload_register([new \Mlaphp\Autoloader(), 'load']);

$args = $_SERVER['argv'] ?? [];
$check = is_array($args) && in_array('--check', $args, true);

$json = stream_get_contents(STDIN);
if ($json === false || trim($json) === '') {
    fwrite(STDERR, "Nothing on stdin. Pipe the resume JSON in.\n");
    exit(1);
}

if ($check) {
    $pdo = new \PDO('sqlite::memory:');
    $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
    \Resume\SqliteSchema::create($pdo);
} else {
    $pdo = \Database\Base::getPDO(new \Config\Config());
}

try {
    $counts = (new \Resume\Importer($pdo))->import($json);
} catch (\Resume\ImportException $e) {
    fwrite(STDERR, 'Not imported: ' . $e->getMessage() . "\n");
    exit(1);
}

foreach ($counts as $table => $count) {
    echo str_pad($table, 20) . $count . "\n";
}
echo $check ? "Valid. Nothing was written.\n" : "Imported.\n";
