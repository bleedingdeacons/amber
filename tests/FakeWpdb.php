<?php

declare(strict_types=1);

namespace Amber\Tests;

/**
 * A recording stand-in for WordPress's global $wpdb.
 *
 * Several admin screens build SQL by hand — attendance registers, the
 * developer dashboard's table inspection, the member and position list
 * tables' sort/filter joins. What matters in a test is therefore *what was
 * asked of the database*: which columns were selected, whether a filter
 * became a WHERE clause. This records every statement and hands back
 * whatever the test queued.
 *
 * prepare() interpolates naively — enough to assert on the shape of a
 * statement, without pretending to be WordPress's escaping.
 */
final class FakeWpdb
{
    public string $prefix = 'wp_';
    public string $posts = 'wp_posts';
    public string $postmeta = 'wp_postmeta';
    public string $users = 'wp_users';
    public string $last_error = '';
    public int $insert_id = 1;

    /** Every statement passed to a query method, in order. */
    public array $queries = [];

    /** Rows returned by get_results(). */
    public array $results = [];

    /** Row returned by get_row(). */
    public mixed $row = null;

    /** Column returned by get_col(). */
    public array $col = [];

    /** Scalar returned by get_var(). */
    public mixed $var = '0';

    public array $inserts = [];
    public array $updates = [];
    public array $deletes = [];

    public mixed $insertResult = 1;
    public mixed $updateResult = 1;
    public mixed $deleteResult = 1;
    public mixed $queryResult = 1;

    public function prepare(string $query, mixed ...$args): string
    {
        if (count($args) === 1 && is_array($args[0])) {
            $args = $args[0];
        }

        foreach ($args as $arg) {
            $replacement = is_int($arg) || is_float($arg) ? (string) $arg : "'" . $arg . "'";
            $query = preg_replace('/%[sdfF]/', $replacement, $query, 1) ?? $query;
        }

        return $query;
    }

    public function get_results(string $query, mixed $output = null): array
    {
        $this->queries[] = $query;

        return $this->results;
    }

    public function get_row(string $query, mixed $output = null, int $y = 0): mixed
    {
        $this->queries[] = $query;

        return $this->row;
    }

    public function get_col(string $query, int $x = 0): array
    {
        $this->queries[] = $query;

        return $this->col;
    }

    public function get_var(string $query, int $x = 0, int $y = 0): mixed
    {
        $this->queries[] = $query;

        return $this->var;
    }

    public function query(string $query): mixed
    {
        $this->queries[] = $query;

        return $this->queryResult;
    }

    public function insert(string $table, array $data, mixed $formats = null): mixed
    {
        $this->inserts[] = [$table, $data, $formats];

        return $this->insertResult;
    }

    public function update(string $table, array $data, array $where, mixed $f = null, mixed $wf = null): mixed
    {
        $this->updates[] = [$table, $data, $where, $f, $wf];

        return $this->updateResult;
    }

    public function delete(string $table, array $where, mixed $formats = null): mixed
    {
        $this->deletes[] = [$table, $where, $formats];

        return $this->deleteResult;
    }

    public function get_charset_collate(): string
    {
        return 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';
    }

    public function esc_like(string $text): string
    {
        return addcslashes($text, '_%\\');
    }

    /** The most recent statement, for terse assertions. */
    public function lastQuery(): string
    {
        return $this->queries === [] ? '' : (string) end($this->queries);
    }

    /** Forget everything recorded and queued. */
    public function reset(): void
    {
        $this->queries = [];
        $this->results = [];
        $this->row = null;
        $this->col = [];
        $this->var = '0';
        $this->inserts = [];
        $this->updates = [];
        $this->deletes = [];
        $this->insertResult = 1;
        $this->updateResult = 1;
        $this->deleteResult = 1;
        $this->queryResult = 1;
        $this->last_error = '';
    }
}
