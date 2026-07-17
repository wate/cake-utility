<?php

declare(strict_types=1);

/**
 * Test bootstrap for CakeUtility plugin.
 *
 * Self-contained bootstrap that loads the plugin's own vendor autoloader.
 */

require dirname(__DIR__) . '/vendor/autoload.php';

use Cake\Cache\Cache;
use Cake\Core\Configure;
use Cake\Datasource\ConnectionManager;
use Cake\Routing\Router;

// Set minimal App configuration for Controller/Response compatibility
Configure::write('App.encoding', 'UTF-8');
Cache::setConfig('_cake_translations_', [
    'className' => 'File',
    'path' => sys_get_temp_dir() . '/cake_utility_test_cache',
    'prefix' => 'cake_utility_translations_',
    'duration' => '+1 hour',
]);

// Initialize Router with DashedRoute for URL generation (matching production default)
use Cake\Routing\Route\DashedRoute;

Router::reload();
Router::createRouteBuilder('/')->setRouteClass(DashedRoute::class)->connect('/{controller}/{action}/*', []);

// Setup a dedicated SQLite database for plugin integration tests
$dbPath = dirname(__DIR__) . '/tests/test_scenario.sqlite';

// Clean start: remove existing DB file
if (file_exists($dbPath)) {
    unlink($dbPath);
}

// Configure the 'test' connection to use this physical file
ConnectionManager::setConfig('test', [
    'className' => 'Cake\Database\Connection',
    'driver' => 'Cake\Database\Driver\Sqlite',
    'database' => $dbPath,
    'encoding' => 'utf8',
    'cacheMetadata' => false,
]);

// Apply the schema to the new database
$connection = ConnectionManager::get('test');
$schemaFile = dirname(__DIR__) . '/tests/schema.sql';

if (file_exists($schemaFile)) {
    $sql = file_get_contents($schemaFile);
    // Split by semicolon to execute each statement individually
    $statements = array_filter(array_map('trim', explode(';', $sql)));
    foreach ($statements as $statement) {
        $connection->execute($statement);
    }
}
