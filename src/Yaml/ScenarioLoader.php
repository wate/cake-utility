<?php

declare(strict_types=1);

namespace CakeUtility\Yaml;

use Cake\ORM\Table;
use Cake\ORM\Locator\TableLocator;
use Cake\ORM\Exception\PersistenceFailedException;
use Cake\Datasource\EntityInterface;
use Cake\Datasource\ConnectionManager;
use RuntimeException;

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
     * @param TableLocator|null $tableLocator Optional TableLocator instance.
     * @param string $connectionName Connection name for table resolution.
     */
    public function __construct(?TableLocator $tableLocator = null, string $connectionName = 'default')
    {
        $this->tableLocator = $tableLocator ?? new TableLocator();
        $this->connectionName = $connectionName;
    }

    /**
     * Load a scenario from a specified file or directory.
     *
     * @param string $filePath Path to the YAML file or directory containing scenario files.
     * @param string $tableName The CakePHP table alias to target.
     * @return array<string, int> Associative array with 'records_inserted' and 'records_updated' counts.
     * @throws RuntimeException If loading fails.
     */
    public function load(string $filePath, string $tableName): array
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
     * Deletes records in reverse YAML order using `_keys` for lookup.
     * Uses refMap to track which `_ref` records were deleted.
     *
     * @param string $filePath Path to the YAML file or directory.
     * @param string $tableName The CakePHP table alias to target.
     * @return int Number of records deleted.
     * @throws RuntimeException On deletion failure.
     */
    public function clear(string $filePath, string $tableName): int
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
                        sprintf(
                            'Failed to delete record at index %d in %s: %s',
                            $i,
                            $filePath,
                            $e->getMessage()
                        )
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
                sprintf(
                    'Failed to save record at index %d in %s: %s',
                    $index,
                    $filePath,
                    $e->getMessage()
                )
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
     * Parse a YAML file into records.
     *
     * @param string $filePath Path to YAML file.
     * @return array<int, array<string, mixed>>
     * @throws RuntimeException If file is unreadable or invalid.
     */
    protected function parseYaml(string $filePath): array
    {
        if (!is_readable($filePath)) {
            throw new RuntimeException("Scenario file not readable: {$filePath}");
        }

        $loader = new Loader();
        return $loader->parse(file_get_contents($filePath));
    }
}
