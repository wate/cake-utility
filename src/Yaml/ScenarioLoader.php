<?php

declare(strict_types=1);

namespace CakeUtility\Yaml;

use Cake\Core\Configure;
use Cake\ORM\Table;
use Cake\ORM\Locator\TableLocator;
use Cake\ORM\Exception\PersistenceFailedException;
use Cake\Datasource\EntityInterface;
use Cake\Datasource\ConnectionManager;
use Cake\Utility\Inflector;
use RuntimeException;

use function Cake\I18n\__d;

/**
 * ScenarioLoader: Orchestrates the loading of YAML scenario data into the database.
 *
 * This class receives parsed YAML data from Yaml\Loader and manages the database
 * persistence flow, including reference resolution via refMaps, idempotent upserts,
 * and transaction control.
 */
class ScenarioLoader
{
    /**
     * Base directory path for scenario files.
     */
    protected string $basePath;

    /**
     * Reference map: maps _ref labels to their corresponding database IDs.
     *
     * @var array<string, mixed>
     */
    protected array $refMap = [];

    /**
     * Table locator instance.
     */
    protected TableLocator $tableLocator;

    /**
     * Connection name for table resolution.
     */
    protected string $connectionName = 'default';

    /**
     * Constructor.
     *
     * @param string|null $basePath Base directory path for scenario files.
     *   null の場合は `Configure::read('Scenario.baseDir')` から読み込む。
     *   それも未設定の場合は '{CACHE}scenarios' を使用する。
     * @param TableLocator|null $tableLocator Optional TableLocator instance.
     * @param string $connectionName Connection name for table resolution.
     */
    public function __construct(?string $basePath = null, ?TableLocator $tableLocator = null, string $connectionName = 'default')
    {
        $resolvedPath = $basePath
            ?? Configure::read('Scenario.baseDir')
            ?? CACHE . 'scenarios';
        $this->basePath = rtrim($resolvedPath, DS);
        $this->tableLocator = $tableLocator ?? new TableLocator();
        $this->connectionName = $connectionName;
    }

    /**
     * Load a scenario from a specified scenario name.
     *
     * @param string $scenarioName Scenario name (directory containing YAML files).
     * @param string|array<string>|null $tableNames Target table name(s). Omit to load all tables in scenario.
     * @return array<string, int> Associative array with 'records_inserted' and 'records_updated' counts.
     * @throws RuntimeException If loading fails.
     */
    public function load(string $scenarioName, string|array|null $tableNames = null): array
    {
        $scenarioPath = $this->resolvePath($scenarioName);
        $yamlFiles = $this->collectYamlFilesFromScenario($scenarioPath);

        // Phase 1: Build dependency graph and sort files based on data dependencies
        $yamlFiles = $this->orderFilesByDependency($yamlFiles);

        // Normalize $tableNames to array
        $tableFilter = $tableNames ? (array)$tableNames : null;

        $totalInserted = 0;
        $totalUpdated = 0;

        foreach ($yamlFiles as $filePath) {
            // Infer table name from file name
            $inferredTableName = $this->resolveTableNameFromFile($filePath);

            // Filter by specified table names if provided
            if ($tableFilter !== null && !in_array($inferredTableName, $tableFilter, true)) {
                continue;
            }

            $result = $this->loadTable($filePath, $inferredTableName);
            $totalInserted += $result['records_inserted'];
            $totalUpdated += $result['records_updated'];
        }

        return [
            'records_inserted' => $totalInserted,
            'records_updated' => $totalUpdated,
        ];
    }

    /**
     * Load a single table from a YAML file.
     *
     * @param string $filePath Path to the YAML file (absolute path).
     * @param string $tableName The CakePHP table alias to target.
     * @return array<string, int> Associative array with 'records_inserted' and 'records_updated' counts.
     * @throws RuntimeException If loading fails.
     */
    protected function loadTable(string $filePath, string $tableName): array
    {
        $table = $this->getTable($tableName);
        $records = $this->parseYaml($filePath);

        $loader = new Loader();
        // Current refMap is passed to the loader for reference resolution
        $resolvedRecords = $loader->resolve($records, $this->refMap, $table);

        $inserted = 0;
        $updated = 0;

        $table->getConnection()->transactional(function () use ($table, $resolvedRecords, $filePath, &$inserted, &$updated) {
            foreach ($resolvedRecords as $index => $record) {
                $this->persistRecord($table, $record, $filePath, $index, $inserted, $updated);
            }
        });

        return [
            'records_inserted' => $inserted,
            'records_updated' => $updated,
        ];
    }

    /**
     * Clear a scenario from the database.
     *
     * Deletes records in reverse order.
     *
     * @param string $scenarioName Scenario name (directory containing YAML files).
     * @param string|array<string>|null $tableNames Target table name(s). Omit to clear all tables in scenario.
     * @return int Number of records deleted.
     * @throws RuntimeException On deletion failure.
     */
    public function clear(string $scenarioName, string|array|null $tableNames = null): int
    {
        $scenarioPath = $this->resolvePath($scenarioName);
        $yamlFiles = $this->collectYamlFilesFromScenario($scenarioPath);

        // Reverse order for deletion (dependents first)
        $yamlFiles = array_reverse($yamlFiles);

        // Normalize $tableNames to array
        $tableFilter = $tableNames ? (array)$tableNames : null;

        $totalDeleted = 0;

        foreach ($yamlFiles as $filePath) {
            // Infer table name from file name
            $inferredTableName = $this->resolveTableNameFromFile($filePath);

            // Filter by specified table names if provided
            if ($tableFilter !== null && !in_array($inferredTableName, $tableFilter, true)) {
                continue;
            }

            $deleted = $this->clearTable($filePath, $inferredTableName);
            $totalDeleted += $deleted;
        }

        return $totalDeleted;
    }

    /**
     * Clear a single table from a YAML file.
     *
     * @param string $filePath Path to the YAML file (absolute path).
     * @param string $tableName The CakePHP table alias to target.
     * @return int Number of records deleted.
     * @throws RuntimeException On deletion failure.
     */
    protected function clearTable(string $filePath, string $tableName): int
    {
        $table = $this->getTable($tableName);
        $records = $this->parseYaml($filePath);

        // Extract keys and refs from original YAML records (in reverse order)
        $loader = new Loader();
        $parsedRecords = $loader->parse(file_get_contents($filePath));

        $deleted = 0;

        $table->getConnection()->transactional(function () use ($table, $parsedRecords, $filePath, &$deleted) {
            // Delete in reverse order (dependents first)
            for ($i = count($parsedRecords) - 1; $i >= 0; $i--) {
                $record = $parsedRecords[$i];
                $keys = $record['_keys'] ?? null;
                $ref = $record['_ref'] ?? null;

                if ($keys === null) {
                    continue;
                }

                // Find entity by keys
                $entity = $this->findOrCreateEntity($table, $record, $keys);
                if ($entity->isNew()) {
                    continue; // Nothing to delete
                }

                try {
                    $table->deleteOrFail($entity);
                    $deleted++;
                } catch (\InvalidArgumentException $e) {
                    throw new RuntimeException(
                        __d('cake_utility', 'Failed to delete record at index {0} in {1}: {2}', $i, $filePath, $e->getMessage())
                    );
                }

                // Update refMap to indicate deleted
                if ($ref !== null) {
                    $this->refMap[$ref] = null; // null = deleted
                }
            }
        });

        return $deleted;
    }

    /**
     * Reset the internal refMap.
     */
    public function resetRefMap(): void
    {
        $this->refMap = [];
    }

    /**
     * Get a Table instance.
     *
     * @param string $tableName CakePHP table alias.
     * @return Table
     */
    protected function getTable(string $tableName): Table
    {
        return $this->tableLocator->get($tableName, ['connectionName' => $this->connectionName]);
    }

    /**
     * Persist a single record to the database using upsert logic.
     *
     * @param Table $table Target table.
     * @param array<string, mixed> $record Resolved record data.
     * @param string $filePath Original file path for error reporting.
     * @param int $index Record index for error reporting.
     * @param int &$inserted Reference counter for inserted records.
     * @param int &$updated Reference counter for updated records.
     * @return void
     * @throws RuntimeException On persistence failure.
     */
    protected function persistRecord(Table $table, array $record, string $filePath, int $index, int &$inserted, int &$updated): void
    {
        // Note: $record is already resolved by Loader::resolve in load()

        // Get the _ref label from the original YAML to update the refMap
        $originalRecords = $this->parseYaml($filePath);
        $originalRecord = $originalRecords[$index] ?? [];
        $ref = $originalRecord['_ref'] ?? null;

        // Get the lookup keys from the original YAML
        $keys = $originalRecord['_keys'] ?? null;

        // Serialize JSON-type columns before setting on Entity
        $record = $this->serializeJsonColumns($table, $record);

        // ID確定: id指定あり(Fixture的)または_keys-based(Seed的)
        $entity = $this->resolveEntity($table, $record, $originalRecord, $keys);
        $entity->patch($record);

        // Note: saveOrFail() sets isNew(false) after successful save, so capture it first
        $wasNew = $entity->isNew();

        try {
            $saved = $table->saveOrFail($entity);
        } catch (PersistenceFailedException $e) {
            throw new RuntimeException(
                __d('cake_utility', 'Failed to save record at index {0} in {1}: {2}', (string)$index, $filePath, $e->getMessage())
            );
        }

        if ($wasNew) {
            $inserted++;
        } else {
            $updated++;
        }

        if ($ref !== null) {
            $pk = $table->getPrimaryKey();
            if (is_array($pk)) {
                $id = [];
                foreach ($pk as $key) {
                    $id[$key] = $saved->get($key);
                }
            } else {
                $id = $saved->get($pk);
            }
            $this->refMap[$ref] = $id;
        }
    }

    /**     * Resolve an entity for persistence based on ID strategy.
     *
     * - Fixture-style: when `id` is explicitly set in YAML, use that ID.
     *   Load existing by primary key, or create new with specified ID.
     * - Seed-style: when `id` is omitted, use `_keys`-based lookup.
     *   Load by key conditions, or create empty entity.
     *
     * @param \Cake\ORM\Table $table Target table.
     * @param array<string, mixed> $record Resolved record data.
     * @param array<string, mixed> $originalRecord Original YAML record (before resolution).
     * @param string|array<string>|null $keys Key fields for Seed-style lookup.
     * @return \Cake\ORM\Entity
     */
    protected function resolveEntity(Table $table, array $record, array $originalRecord, string|array|null $keys): EntityInterface
    {
        $pk = $table->getPrimaryKey();
        $hasId = isset($record['id']) && $record['id'] !== null;

        // Case 1: id is explicitly specified (Fixture-style)
        if ($hasId) {
            $id = $record['id'];

            // Build conditions using primary key(s)
            $conditions = [];
            if (is_string($pk) && $pk === 'id') {
                $conditions[$pk] = $id;
            } elseif (is_array($pk)) {
                // Composite primary key: id must be associative array
                if (is_array($id)) {
                    foreach ($pk as $key) {
                        $conditions[$key] = $id[$key] ?? null;
                    }
                }
            }

            if (!empty($conditions)) {
                $entity = $table->find()->where($conditions)->first();
                if ($entity) {
                    return $entity;
                }
            }

            // Entity not found - create new with specified ID
            $entity = $table->newEmptyEntity();
            if (is_string($pk)) {
                $entity->set($pk, $id);
            } elseif (is_array($pk) && is_array($id)) {
                foreach ($pk as $key) {
                    if (isset($id[$key])) {
                        $entity->set($key, $id[$key]);
                    }
                }
            }

            return $entity;
        }

        // Case 2: id is not specified (Seed-style) - use _keys-based lookup
        return $this->findOrCreateEntity($table, $record, $keys);
    }

    /**     * Find an existing entity by keys or return a new one.
     *
     * @param Table $table Target table.
     * @param array<string, mixed> $data Resolved data.
     * @param string|array<string>|null $keys Key fields for lookup.
     * @return \Cake\ORM\Entity
     */
    protected function findOrCreateEntity(Table $table, array $data, string|array|null $keys)
    {
        if ($keys === null) {
            return $table->newEmptyEntity();
        }

        $keys = is_string($keys) ? [$keys] : $keys;
        $conditions = [];

        foreach ($keys as $key) {
            if (array_key_exists($key, $data)) {
                $conditions[$key] = $data[$key];
            }
        }

        if (empty($conditions)) {
            return $table->newEmptyEntity();
        }

        $entity = $table->find()->where($conditions)->first();

        return $entity ?? $table->newEmptyEntity();
    }

    /**
     * Serialize JSON-type columns before persistence.
     *
     * Only 'text' columns need manual encoding here: 'json' columns are already
     * handled by the ORM's own JsonType at save time, so pre-encoding those would
     * double-encode the value (array -> string -> re-encoded string).
     *
     * @param Table $table Target table.
     * @param array<string, mixed> $record Record data.
     * @return array<string, mixed>
     */
    protected function serializeJsonColumns(Table $table, array $record): array
    {
        $schema = $table->getSchema();
        foreach ($record as $column => $value) {
            if (is_array($value)) {
                $columnType = $schema->getColumnType($column);
                if ($columnType === 'text') {
                    $record[$column] = json_encode($value, JSON_THROW_ON_ERROR);
                }
            }
        }
        return $record;
    }

    /**
     * Collect all YAML files from a scenario directory.
     *
     * @param string $scenarioPath Path to the scenario directory.
     * @return array<string> Sorted array of YAML file paths.
     */
    protected function collectYamlFilesFromScenario(string $scenarioPath): array
    {
        $files = [];

        if (is_file($scenarioPath)) {
            // Single file
            return [$scenarioPath];
        }

        if (!is_dir($scenarioPath)) {
            return $files;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($scenarioPath, \RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->getExtension() === 'yml' || $file->getExtension() === 'yaml') {
                $files[] = $file->getPathname();
            }
        }

        sort($files);
        return $files;
    }

    /**
     * Order YAML files based on data dependencies extracted from ref: references.
     *
     * Builds a dependency graph where each file's dependencies are determined by
     * the ref: labels it references, and returns files in topological sort order.
     *
     * @param array<string> $yamlFiles List of YAML file paths.
     * @return array<string> Files ordered by dependency (dependents first).
     * @throws RuntimeException On circular dependency detection.
     */
    protected function orderFilesByDependency(array $yamlFiles): array
    {
        if (count($yamlFiles) <= 1) {
            return $yamlFiles;
        }

        $graph = $this->buildDependencyGraph($yamlFiles);
        return $this->topologicalSort($yamlFiles, $graph);
    }

    /**
     * Build a dependency graph where each file maps to the files it depends on.
     *
     * For each file, extract all ref: references, then determine which other files
     * contain records with those _ref labels. Those source files are dependencies.
     *
     * @param array<string> $yamlFiles List of YAML file paths.
     * @return array<string, array<string>> Dependency graph: filePath => [dependencyFilePaths].
     */
    protected function buildDependencyGraph(array $yamlFiles): array
    {
        $graph = [];
        $refToFile = []; // Maps each _ref label to the file that defines it

        // Phase 1: Scan all files to build _ref => file mapping
        $loader = new Loader();
        foreach ($yamlFiles as $filePath) {
            $parsed = $loader->parse(file_get_contents($filePath));
            if (!is_array($parsed)) {
                continue;
            }

            // Handle both single record (associative array) and multiple records (array of arrays)
            $records = isset($parsed[0]) && is_array($parsed[0]) ? $parsed : [$parsed];

            foreach ($records as $record) {
                if (is_array($record) && isset($record['_ref'])) {
                    $ref = $record['_ref'];
                    $refToFile[$ref] = $filePath;
                }
            }
        }

        // Phase 2: For each file, extract ref: references and build dependency list
        foreach ($yamlFiles as $filePath) {
            $graph[$filePath] = [];
            $references = $loader->extractReferences($filePath);

            foreach ($references as $ref) {
                // If this reference is defined in another file, that file is a dependency
                if (isset($refToFile[$ref]) && $refToFile[$ref] !== $filePath) {
                    $dependencyFile = $refToFile[$ref];
                    if (!in_array($dependencyFile, $graph[$filePath], true)) {
                        $graph[$filePath][] = $dependencyFile;
                    }
                }
            }
        }

        return $graph;
    }

    /**
     * Perform topological sort on the dependency graph using Kahn's algorithm.
     *
     * @param array<string> $yamlFiles List of YAML file paths.
     * @param array<string, array<string>> $graph Dependency graph.
     * @return array<string> Sorted file list (dependencies before dependents).
     * @throws RuntimeException On circular dependency detection.
     */
    protected function topologicalSort(array $yamlFiles, array $graph): array
    {
        // Build in-degree count for each file
        $inDegree = array_combine($yamlFiles, array_fill(0, count($yamlFiles), 0));
        $adjacencyList = array_combine($yamlFiles, array_fill(0, count($yamlFiles), []));

        foreach ($graph as $file => $dependencies) {
            foreach ($dependencies as $dep) {
                if (isset($adjacencyList[$dep])) {
                    $adjacencyList[$dep][] = $file;
                    $inDegree[$file]++;
                }
            }
        }

        // Collect nodes with in-degree 0
        $queue = array_filter($yamlFiles, fn($file) => $inDegree[$file] === 0);
        $sorted = [];

        while (!empty($queue)) {
            $file = array_shift($queue);
            $sorted[] = $file;

            foreach ($adjacencyList[$file] as $neighbor) {
                $inDegree[$neighbor]--;
                if ($inDegree[$neighbor] === 0) {
                    $queue[] = $neighbor;
                }
            }
        }

        // Check for circular dependencies
        if (count($sorted) !== count($yamlFiles)) {
            throw new RuntimeException(__d('cake_utility', 'Circular dependency detected in YAML scenario files'));
        }

        return $sorted;
    }

    /**
     * Resolve table name from a YAML file path.
     *
     * Removes any leading numeric prefix (e.g., "01_", "01-", "02_", "02-") from the filename
     * before converting to table name format. This allows organizing files with
     * sequence numbers while maintaining clean table names.
     *
     * Examples:
     *   01_groups.yml       → groups
     *   01-groups.yml       → groups
     *   02_users.yml        → users
     *   02-users.yml        → users
     *   03_shop_products.yml → shop_products
     *   03-shop_products.yml → shop_products
     *
     * @param string $filePath Absolute path to the YAML file.
     * @return string Table name (CakePHP table alias).
     */
    protected function resolveTableNameFromFile(string $filePath): string
    {
        $basename = basename($filePath, '.' . pathinfo($filePath, PATHINFO_EXTENSION));

        // Remove leading numeric prefix with underscore or hyphen (e.g., "01_", "01-", "02_", etc.)
        // This allows organizing scenario files with sequence numbers for clarity
        // Users can choose their preferred separator: underscore or hyphen
        $basename = preg_replace('/^\d+[_-]/', '', $basename);

        return Inflector::tableize($basename);
    }

    /**
     * Resolve scenario name to absolute file path.
     *
     * @param string $scenarioName Scenario name (filename without extension or directory).
     * @return string Absolute path to the YAML file.
     * @throws RuntimeException If path cannot be resolved.
     */
    protected function resolvePath(string $scenarioName): string
    {
        // Try with .yaml extension
        $yamlPath = $this->basePath . DS . $scenarioName . '.yaml';
        if (is_file($yamlPath)) {
            return $yamlPath;
        }

        // Try with .yml extension
        $ymlPath = $this->basePath . DS . $scenarioName . '.yml';
        if (is_file($ymlPath)) {
            return $ymlPath;
        }

        // Try as directory
        $dirPath = $this->basePath . DS . $scenarioName;
        if (is_dir($dirPath)) {
            return $dirPath;
        }

        throw new RuntimeException(
            __d('cake_utility', 'Scenario not found: {0} (tried in {1})', $scenarioName, $this->basePath)
        );
    }

    /**
     * Parse a YAML file into records.
     *
     * @param string $filePath Path to YAML file.
     * @return array<int, array<string, mixed>>
     * @throws RuntimeException If file is unreadable or invalid.
     */
    protected function parseYaml(string $filePath): array
    {
        if (!is_readable($filePath)) {
            throw new RuntimeException(
                __d('cake_utility', 'Scenario file not readable: {0}', $filePath)
            );
        }

        $loader = new Loader();
        return $loader->parse(file_get_contents($filePath));
    }
}
