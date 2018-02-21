<?php

namespace Directus\Services;

use Directus\Database\Exception\ColumnAlreadyExistsException;
use Directus\Database\Exception\ColumnNotFoundException;
use Directus\Database\Exception\TableAlreadyExistsException;
use Directus\Database\Exception\TableNotFoundException;
use Directus\Database\Object\Field;
use Directus\Database\Object\FieldRelationship;
use Directus\Database\RowGateway\BaseRowGateway;
use Directus\Database\Schema\SchemaFactory;
use Directus\Database\TableSchema;
use Directus\Exception\BadRequestException;
use Directus\Exception\ErrorException;
use Directus\Hook\Emitter;
use Directus\Util\ArrayUtils;
use Directus\Validator\Exception\InvalidRequestException;

class TablesService extends AbstractService
{
    /**
     *
     * @param string $name
     * @param array $data
     *
     * @return BaseRowGateway
     *
     * @throws ErrorException
     * @throws InvalidRequestException
     * @throws TableAlreadyExistsException
     */
    public function createTable($name, array $data = [])
    {
        if ($this->getSchemaManager()->tableExists($name)) {
            throw new TableAlreadyExistsException($name);
        }

        if (!$this->isValidName($name)) {
            throw new InvalidRequestException('Invalid collection name');
        }

        $success = $this->createTableSchema($name, $data);
        if (!$success) {
            throw new ErrorException('Error creating the collection');
        }

        $collectionsTableGateway = $this->createTableGateway('directus_collections');

        $columns = ArrayUtils::get($data, 'fields');
        $this->addColumnsInfo($name, $columns);

        $item = ArrayUtils::omit($data, 'fields');
        $item['collection'] = $name;

        return $collectionsTableGateway->updateRecord($item);
    }

    /**
     * Updates a table
     *
     * @param $name
     * @param array $data
     *
     * @return BaseRowGateway
     *
     * @throws ErrorException
     * @throws TableNotFoundException
     */
    public function updateTable($name, array $data)
    {
        if (!$this->getSchemaManager()->tableExists($name)) {
            throw new TableNotFoundException($name);
        }

        $tableObject = $this->getSchemaManager()->getTableSchema($name);
        $columns = ArrayUtils::get($data, 'fields', []);
        foreach ($columns as $i => $column) {
            $columnObject = $tableObject->getField($column['field']);
            if ($columnObject) {
                $currentColumnData = $columnObject->toArray();
                $columns[$i] = array_merge($currentColumnData, $columns[$i]);
            }
        }

        $data['fields'] = $columns;
        $success = $this->updateTableSchema($name, $data);
        if (!$success) {
            throw new ErrorException('Error creating the table');
        }

        $collectionsTableGateway = $this->createTableGateway('directus_collections');

        $columns = ArrayUtils::get($data, 'fields', []);
        if (!empty($columns)) {
            $this->addColumnsInfo($name, $columns);
        }

        $item = ArrayUtils::omit($data, 'fields');
        $item['collection'] = $name;

        return $collectionsTableGateway->updateRecord($item);
    }

    /**
     * Adds a column to an existing table
     *
     * @param $collectionName
     * @param $columnName
     * @param array $data
     *
     * @return BaseRowGateway
     *
     * @throws ColumnAlreadyExistsException
     *
     * @throws TableNotFoundException
     */
    public function addColumn($collectionName, $columnName, array $data)
    {
        $tableObject = $this->getSchemaManager()->getTableSchema($collectionName);
        if (!$tableObject) {
            throw new TableNotFoundException($collectionName);
        }

        $columnObject = $tableObject->getField($columnName);
        if ($columnObject) {
            throw new ColumnAlreadyExistsException($columnName);
        }

        $columnData = array_merge($data, [
            'field' => $columnName
        ]);

        $this->updateTableSchema($collectionName, [
            'fields' => [$columnData]
        ]);

        return $this->addColumnInfo($collectionName, $columnData);
    }

    /**
     * Adds a column to an existing table
     *
     * @param $collectionName
     * @param $columnName
     * @param array $data
     *
     * @return BaseRowGateway
     *
     * @throws ColumnNotFoundException
     * @throws TableNotFoundException
     */
    public function changeColumn($collectionName, $columnName, array $data)
    {
        $tableObject = $this->getSchemaManager()->getTableSchema($collectionName);
        if (!$tableObject) {
            throw new TableNotFoundException($collectionName);
        }

        $columnObject = $tableObject->getField($columnName);
        if (!$columnObject) {
            throw new ColumnNotFoundException($columnName);
        }

        $columnData = array_merge($columnObject->toArray(), $data);
        $this->updateTableSchema($collectionName, [
            'fields' => [$columnData]
        ]);

        return $this->addColumnInfo($collectionName, $columnData);
    }

    public function dropColumn($collectionName, $fieldName)
    {
        $tableObject = $this->getSchemaManager()->getTableSchema($collectionName);
        if (!$tableObject) {
            throw new TableNotFoundException($collectionName);
        }

        $columnObject = $tableObject->getField($fieldName);
        if (!$columnObject) {
            throw new ColumnNotFoundException($fieldName);
        }

        if (count($tableObject->getFields()) === 1) {
            throw new BadRequestException('Cannot delete the last field');
        }

        if (!$this->dropColumnSchema($collectionName, $fieldName)) {
            throw new ErrorException('Error deleting the field');
        }

        if (!$this->removeColumnInfo($collectionName, $fieldName)) {
            throw new ErrorException('Error deleting the field information');
        }
    }

    /**
     * Add columns information to the fields table
     *
     * @param $collectionName
     * @param array $columns
     *
     * @return BaseRowGateway[]
     */
    public function addColumnsInfo($collectionName, array $columns)
    {
        $resultsSet = [];
        foreach ($columns as $column) {
            $resultsSet[] = $this->addColumnInfo($collectionName, $column);
        }

        return $resultsSet;
    }

    /**
     * Add field information to the field system table
     *
     * @param $collectionName
     * @param array $column
     *
     * @return BaseRowGateway
     */
    public function addColumnInfo($collectionName, array $column)
    {
        // TODO: Let's make this info a string ALL the time at this level
        $options = ArrayUtils::get($column, 'options', []);
        $data = [
            'collection' => $collectionName,
            'field' => $column['field'],
            'type' => $column['type'],
            'interface' => $column['interface'],
            'required' => ArrayUtils::get($column, 'required', false),
            'sort' => ArrayUtils::get($column, 'sort', false),
            'comment' => ArrayUtils::get($column, 'comment', false),
            'hidden_input' => ArrayUtils::get($column, 'hidden_input', false),
            'hidden_list' => ArrayUtils::get($column, 'hidden_list', false),
            'options' => is_array($options) ? json_encode($options) : $options
        ];

        $fieldsTableGateway = $this->createTableGateway('directus_fields');
        $row = $fieldsTableGateway->findOneByArray([
            'collection' => $collectionName,
            'field' => $column['field']
        ]);

        if ($row) {
            $data['id'] = $row['id'];
        }

        return $fieldsTableGateway->updateRecord($data);
    }

    /**
     * @param $collectionName
     * @param $fieldName
     *
     * @return int
     */
    public function removeColumnInfo($collectionName, $fieldName)
    {
        $fieldsTableGateway = $this->createTableGateway('directus_fields');

        return $fieldsTableGateway->delete([
            'collection' => $collectionName,
            'field' => $fieldName
        ]);
    }

    /**
     * @param $collectionName
     * @param $fieldName
     *
     * @return bool
     */
    protected function dropColumnSchema($collectionName, $fieldName)
    {
        /** @var SchemaFactory $schemaFactory */
        $schemaFactory = $this->container->get('schema_factory');
        $table = $schemaFactory->alterTable($collectionName, [
            'drop' => [
                $fieldName
            ]
        ]);

        return $schemaFactory->buildTable($table) ? true : false;
    }

    /**
     * Drops the given table and its table and columns information
     *
     * @param $name
     *
     * @return bool
     *
     * @throws TableNotFoundException
     */
    public function dropTable($name)
    {
        if (!$this->getSchemaManager()->tableExists($name)) {
            throw new TableNotFoundException($name);
        }

        $tableGateway = $this->createTableGateway($name);

        return $tableGateway->drop();
    }

    /**
     * Checks whether the given name is a valid clean table name
     *
     * @param $name
     *
     * @return bool
     */
    public function isValidName($name)
    {
        $isTableNameAlphanumeric = preg_match("/[a-z0-9]+/i", $name);
        $zeroOrMoreUnderscoresDashes = preg_match("/[_-]*/i", $name);

        return $isTableNameAlphanumeric && $zeroOrMoreUnderscoresDashes;
    }

    /**
     * Gets the table object representation
     *
     * @param $tableName
     *
     * @return \Directus\Database\Object\Collection
     */
    public function getTableObject($tableName)
    {
        return TableSchema::getTableSchema($tableName);
    }

    /**
     * @param string $name
     * @param array $data
     *
     * @return bool
     */
    protected function createTableSchema($name, array $data)
    {
        /** @var SchemaFactory $schemaFactory */
        $schemaFactory = $this->container->get('schema_factory');

        $columns = ArrayUtils::get($data, 'fields', []);
        $this->validateSystemFields($columns);
        $table = $schemaFactory->createTable($name, $columns);

        /** @var Emitter $hookEmitter */
        $hookEmitter = $this->container->get('hook_emitter');
        $hookEmitter->run('table.create:before', $name);

        $result = $schemaFactory->buildTable($table);

        $hookEmitter->run('table.create', $name);
        $hookEmitter->run('table.create:after', $name);

        return $result ? true : false;
    }

    /**
     * @param $name
     * @param array $data
     *
     * @return bool
     */
    protected function updateTableSchema($name, array $data)
    {
        /** @var SchemaFactory $schemaFactory */
        $schemaFactory = $this->container->get('schema_factory');

        $columns = ArrayUtils::get($data, 'fields', []);
        $this->validateSystemFields($columns);

        $toAdd = $toChange = $aliasColumn = [];
        $tableObject = $this->getSchemaManager()->getTableSchema($name);
        foreach ($columns as $i => $column) {
            $columnObject = $tableObject->getField($column['field']);
            $type = ArrayUtils::get($column, 'type');
            if ($columnObject) {
                $toChange[] = array_merge($columnObject->toArray(), $column);
            } else if (strtoupper($type) !== 'ALIAS') {
                $toAdd[] = $column;
            } else {
                $aliasColumn[] = $column;
            }
        }

        $table = $schemaFactory->alterTable($name, [
            'add' => $toAdd,
            'change' => $toChange
        ]);

        /** @var Emitter $hookEmitter */
        $hookEmitter = $this->container->get('hook_emitter');
        $hookEmitter->run('table.update:before', $name);

        $result = $schemaFactory->buildTable($table);
        $this->updateColumnsRelation($name, array_merge($toAdd, $toChange, $aliasColumn));

        $hookEmitter->run('table.update', $name);
        $hookEmitter->run('table.update:after', $name);

        return $result ? true : false;
    }

    protected function updateColumnsRelation($collectionName, array $columns)
    {
        $result = [];
        foreach ($columns as $column) {
            $result[] = $this->updateColumnRelation($collectionName, $column);
        }

        return $result;
    }

    protected function updateColumnRelation($collectionName, array $column)
    {
        $relationData = ArrayUtils::get($column, 'relation', []);
        if (!$relationData) {
            return false;
        }

        $relationshipType = ArrayUtils::get($relationData, 'relationship_type', '');
        $collectionBName = ArrayUtils::get($relationData, 'collection_b');
        $storeCollectionName = ArrayUtils::get($relationData, 'store_collection');
        $collectionBObject = $this->getSchemaManager()->getTableSchema($collectionBName);
        $relationsTableGateway = $this->createTableGateway('directus_relations');

        $data = [];
        switch ($relationshipType) {
            case FieldRelationship::MANY_TO_ONE:
                $data['relationship_type'] = FieldRelationship::MANY_TO_ONE;
                $data['collection_a'] = $collectionName;
                $data['collection_b'] = $collectionBName;
                $data['store_key_a'] = $column['field'];
                $data['store_key_b'] = $collectionBObject->getPrimaryColumn();
                break;
            case FieldRelationship::ONE_TO_MANY:
                $data['relationship_type'] = FieldRelationship::ONE_TO_MANY;
                $data['collection_a'] = $collectionName;
                $data['collection_b'] = $collectionBName;
                $data['store_key_a'] = $collectionBObject->getPrimaryColumn();
                $data['store_key_b'] = $column['field'];
                break;
            case FieldRelationship::MANY_TO_MANY:
                $data['relationship_type'] = FieldRelationship::MANY_TO_MANY;
                $data['collection_a'] = $collectionName;
                $data['store_collection'] = $storeCollectionName;
                $data['collection_b'] = $collectionBName;
                $data['store_key_a'] = $relationData['store_key_a'];
                $data['store_key_b'] = $relationData['store_key_b'];
                break;
        }


        $row = $relationsTableGateway->findOneByArray([
            'collection_a' => $collectionName,
            'store_key_a' => $column['field']
        ]);

        if ($row) {
            $data['id'] = $row['id'];
        }

        return $relationsTableGateway->updateRecord($data);
    }

    /**
     * @param array $columns
     *
     * @throws InvalidRequestException
     */
    protected function validateSystemFields(array $columns)
    {
        $found = [];

        foreach ($columns as $column) {
            $interface = ArrayUtils::get($column, 'interface');
            if ($this->getSchemaManager()->isSystemField($interface)) {
                if (!isset($found[$interface])) {
                    $found[$interface] = 0;
                }

                $found[$interface]++;
            }
        }

        $interfaces = [];
        foreach ($found as $interface => $count) {
            if ($count > 1) {
                $interfaces[] = $interface;
            }
        }

        if (!empty($interfaces)) {
            throw new InvalidRequestException(
                'Only one system interface permitted per table: ' . implode(', ', $interfaces)
            );
        }
    }

    /**
     * @param array $columns
     *
     * @return array
     *
     * @throws InvalidRequestException
     */
    protected function parseColumns(array $columns)
    {
        $result = [];
        foreach ($columns as $column) {
            if (!isset($column['type']) || !isset($column['field'])) {
                throw new InvalidRequestException(
                    'All column requires a name and a type.'
                );
            }

            $result[$column['field']] = ArrayUtils::omit($column, 'field');
        }
    }
}
