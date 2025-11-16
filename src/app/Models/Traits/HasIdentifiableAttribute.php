<?php

namespace Backpack\CRUD\app\Models\Traits;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Schema;

trait HasIdentifiableAttribute
{
    /**
     * Get the name of the attribute that best defines the entry, from the user perspective.
     *
     * Rephrased: In most cases a user will NOT identify an Article because its ID is "4", but
     * because its name is "10 Ways to Burn Fat". This method returns the column in the database
     * that represents what is better to show to the user as an identifier rather than the ID.
     * Ex: name, title, label, description etc.
     *
     * @return string The name of the column that best defines this entry from the user perspective.
     */
    public function identifiableAttribute()
    {
        if (property_exists($this, 'identifiableAttribute')) {
            return $this->identifiableAttribute;
        }

        return static::guessIdentifiableColumnName();
    }

    /**
     * Get the most likely column in the db table that could be used as an identifiable attribute.
     *
     * @return string The name of the column in the database that is most likely to be a good indentifying attribute.
     */
    private static function guessIdentifiableColumnName()
    {
        $instance = new static();
        $table = $instance->getTable();
        
        // Get all columns using Laravel's Schema builder
        $columnsNames = Schema::getColumnListing($table);
        
        // Get indexed columns
        $indexes = Schema::getConnection()
            ->getSchemaBuilder()
            ->getIndexes($table);
        
        // these column names are sensible defaults for lots of use cases
        $sensibleDefaultNames = ['name', 'title', 'description', 'label'];

        // if any of the sensibleDefaultNames column exists
        // that's probably a good choice
        foreach ($sensibleDefaultNames as $defaultName) {
            if (in_array($defaultName, $columnsNames)) {
                return $defaultName;
            }
        }

        // Get all columns with their properties
        $columns = Schema::getConnection()
            ->getSchemaBuilder()
            ->getColumns($table);

        // get indexed columns in database table
        $indexedColumns = [];
        foreach ($indexes as $indexName => $index) {
            if (isset($index['columns']) && is_array($index['columns'])) {
                $indexedColumns = array_merge($indexedColumns, $index['columns']);
            }
        }

        // if none of the sensible defaults exists
        // we get the first column from database
        // that is NOT indexed (usually primary, foreign keys)
        foreach ($columns as $column) {
            $columnName = $column['name'];
            if (! in_array($columnName, $indexedColumns)) {
                //check for convention "field<_id>" in case developer didn't add foreign key constraints.
                if (strpos($columnName, '_id') !== false) {
                    continue;
                }

                return $columnName;
            }
        }

        // in case everything fails we just return the first column in database
        return Arr::first($columnsNames);
    }
}
