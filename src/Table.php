<?php

namespace RebelCode\Cronarchy;

use Exception;
use wpdb;

/**
 * An abstraction class for WPDB tables.
 *
 * @since [*next-version*]
 */
class Table
{
    /**
     * The WordPress database adapter.
     *
     * @since [*next-version*]
     *
     * @var wpdb
     */
    protected $wpdb;

    /**
     * The name of the table.
     *
     * @since [*next-version*]
     *
     * @var string
     */
    protected $name;

    /**
     * The SQL for creating this table.
     * Any "{{table}}" tokens will be replaced with the table name when this query is executed.
     *
     * @since [*next-version*]
     *
     * @var string
     */
    protected $createSql;

    /**
     * Constructor.
     *
     * @since [*next-version*]
     *
     * @param wpdb   $wpdb      The WordPress database adapter instance.
     * @param string $name      The name of the table.
     * @param string $createSql The SQL for creating this table. Any "{{table}}" tokens will be replaced with the
     *                          table name when this query is executed.
     */
    public function __construct(wpdb $wpdb, $name, $createSql)
    {
        $this->wpdb      = $wpdb;
        $this->name      = $wpdb->base_prefix . $name;
        $this->createSql = $createSql;
    }

    /**
     * Initializes the table.
     *
     * @since [*next-version*]
     *
     * @throws Exception If an error occurs during initialization.
     */
    public function init()
    {
        $this->query(str_replace('{{table}}', $this->getName(), $this->createSql));
    }

    /**
     * Retrieves the name for this table.
     *
     * @since [*next-version*]
     *
     * @return string
     */
    public function getName()
    {
        return $this->name;
    }

    /**
     * Runs a generic query on this table.
     *
     * @since [*next-version*]
     *
     * @param string $query The SQL query string.
     * @param array  $vargs Optional interpolation arguments for $query.
     *
     * @throws Exception If an error occurred.
     *
     * @return int The number of affected rows.
     */
    public function query($query, $vargs = [])
    {
        $prepared = vsprintf($query, $this->escapeArgs($vargs));
        /** @var int|false $numRows */
        $numRows  = $this->wpdb->query($prepared);

        if ($numRows !== false) {
            return $numRows;
        }

        throw new Exception($this->wpdb->last_error);
    }

    /**
     * Fetches records from this table.
     *
     * @since [*next-version*]
     *
     * @param string $condition Optional WHERE condition string.
     * @param array  $vargs     Optional interpolation arguments for $condition.
     *
     * @throws Exception If an error occurred.
     *
     * @return Record[] A numeric array of fetched records, each as an object.
     */
    public function fetch($condition = '', $vargs = [])
    {
        $where = $this->buildWhere($condition, $vargs);
        $query = "SELECT * FROM `{$this->name}` " . $where;

        $results = $this->wpdb->get_results($query);

        if (is_array($results)) {
            return $results;
        }

        throw new Exception($this->wpdb->last_error);
    }

    /**
     * Inserts a record into this table.
     *
     * @since [*next-version*]
     *
     * @param array $data    The record to insert.
     * @param array $formats An array of data formats that correspond to each of $data's entries, as either "%d", "%f"
     *                       or "%s" for numeric, floating point or string.
     *
     * @throws Exception If an error occurred.
     *
     * @return int The number of affected rows.
     */
    public function insert($data, $formats = [])
    {
        $success = $this->wpdb->insert($this->name, $data, $formats);

        if ($success === false) {
            throw new Exception($this->wpdb->last_error);
        }

        return $this->wpdb->insert_id;
    }

    /**
     * Updates records in this table.
     *
     * @since [*next-version*]
     *
     * @param array       $data      An associative array that maps the columns to be updated to their new values.
     * @param array       $formats   An array of data formats that correspond to each of $data's entries, as either
     *                               "%d", "%f" or "%s" for numeric, floating point or string.
     * @param string|null $condition Optional WHERE condition string.
     * @param array       $vargs     Optional interpolation arguments for $condition.
     *
     * @throws Exception If an error occurred.
     *
     * @return int The number of affected rows.
     */
    public function update($data, $formats = [], $condition = null, $vargs = [])
    {
        $fields = $this->buildUpdateFields($data, $formats);
        $where  = $this->buildWhere($condition, $vargs);

        $query = "UPDATE `{$this->name}` SET $fields $where;";

        return $this->query($query);
    }

    /**
     * Deletes records from this table.
     *
     * @since [*next-version*]
     *
     * @param string $condition Optional WHERE condition string.
     * @param array  $vargs     Optional interpolation arguments for $condition.
     *
     * @throws Exception If an error occurred.
     *
     * @return int The number of deleted records.
     */
    public function delete($condition, $vargs = [])
    {
        $where = $this->buildWhere($condition, $vargs);
        $query = "DELETE FROM `{$this->name}` " . $where;

        return $this->query($query);
    }

    /**
     * Used internally to build the "WHERE" portion of a query.
     *
     * @since [*next-version*]
     *
     * @param string $condition Optional WHERE condition string.
     * @param array  $vargs     Optional interpolation arguments for $condition.
     *
     * @return string The built "WHERE" portion.
     */
    protected function buildWhere($condition, $vargs = [])
    {
        if (empty($condition)) {
            return '';
        }

        return 'WHERE ' . vsprintf($condition, $this->escapeArgs($vargs));
    }

    /**
     * Escapes query arguments.
     *
     * @since [*next-version*]
     *
     * @param array $args The arguments to escape.
     *
     * @return array The escaped arguments.
     */
    protected function escapeArgs(array $args)
    {
        return array_map('esc_sql', $args);
    }

    /**
     * Builds the fields for UPDATE queries.
     *
     * @since [*next-version*]
     *
     * @param array $data    The data, mapping column names to values.
     * @param array $formats The corresponding formats, mapping column names to either "%d", "%f" or "%s".
     *
     * @return string The build fields string.
     */
    protected function buildUpdateFields(array $data, array $formats)
    {
        $fields = [];

        foreach ($data as $key => $value) {
            if (is_string($value)) {
                $value = sprintf('"%s"', esc_sql($value));
            }

            $format = isset($formats[$key])
                ? $formats[$key]
                : '%s';
            $pattern = "%s = {$format}";

            $fields[] = sprintf($pattern, $key, $value);
        }

        return implode(', ', $fields);
    }
}
