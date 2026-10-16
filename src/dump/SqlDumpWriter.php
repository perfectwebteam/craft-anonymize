<?php

namespace perfectwebteam\anonymize\dump;

use PDO;
use RuntimeException;
use yii\db\Connection;

/**
 * Writes a dump of the database a connection points at, as mariadb-dump
 * would, with nothing but PHP: no client binaries. A callback may change or
 * drop the rows of the tables it names (the Anonymizer); the rest goes out as
 * it is. A dump without callbacks, imported, gave the same CHECKSUM TABLE as
 * its source for every table, on MariaDB 11.8 and on MySQL 8.
 *
 * One transaction with a consistent snapshot (`--single-transaction`), opened
 * by begin(): every table, and whatever the rules look up on the same
 * connection before run(), sees the database as it was at that moment. Rows are
 * read unbuffered, one at a time, so memory stays flat whatever the size of a
 * table; while a table streams the connection takes no other query, so a rule
 * that tries one stops the dump instead of reading outside the snapshot.
 *
 * Reads and writes utf8mb4 and UTC (TIMESTAMP columns), lists the columns of
 * every INSERT (so invisible columns come along and generated ones stay out),
 * writes binary columns as hex, and refuses a database with views, sequences,
 * versioned tables, triggers, routines or events: what it does not dump, it
 * does not leave out quietly. Like mariadb-dump it sets its own sql_mode, so
 * the server's cannot change what SHOW CREATE TABLE writes.
 * Import with the mariadb client or `craft db/restore`.
 */
final class SqlDumpWriter
{
    /** mysql_real_escape_string(). */
    private const ESCAPE = ['\\' => '\\\\', "\0" => '\\0', "\n" => '\\n', "\r" => '\\r', "'" => "\\'", '"' => '\\"', "\x1a" => '\\Z'];

    /** BIT too: the driver hands it over as a decimal number, not as bytes. */
    private const NUMERIC = ['tinyint', 'smallint', 'mediumint', 'int', 'bigint', 'decimal', 'float', 'double', 'year', 'bit'];
    private const BINARY = ['binary', 'varbinary', 'tinyblob', 'blob', 'mediumblob', 'longblob'];

    private const FLUSH_AT = 1 << 20;

    /** Rows per INSERT up to about this many bytes, as net_buffer_length does for mariadb-dump. */
    private const STATEMENT_BYTES = 1 << 20;

    /** @var array<string, callable(array<string, string|null>): (array<string, string|null>|false)> */
    private array $filters = [];

    /** @var array<string, list<string>> */
    private array $needs = [];

    /** @var array<string, true> */
    private array $emptied = [];

    /** @var array<string, callable(): void> */
    private array $before = [];

    /** @var list<string> */
    private array $first = [];

    /** @var array<string, array{rows: int, changed: int, dropped: int}> */
    public array $stats = [];

    /**
     * Whether a table keeps its default collation (`COLLATE=` after its
     * columns). Without it, the database that imports gives every table the
     * default collation of its character set, the same for all, so tables
     * that differed (SPAR's own next to Craft's) cannot clash in a join. The
     * collation a single column sets stays either way.
     */
    public bool $tableCollations = true;

    /**
     * @var (callable(string, string, string): void)|null gets every text value
     * with an `@` as it is written (table, column, value): the PII scanner
     */
    public $inspect = null;

    /** SQL written after the last table, before the footer: the marker of an anonymized copy. */
    public string $trailer = '';

    /** @var array<string, true> tables left out altogether */
    private array $skipped = [];

    private PDO $pdo;
    private string $buffer = '';
    private Output $out;

    public function __construct(private readonly Connection $db)
    {
    }

    /**
     * Gives every row of $table to $filter, decoded (column => string|null),
     * which returns the values to change, [] to leave the row as it is, or false
     * to drop it. $needs are the columns it reads: a table without one stops the
     * dump rather than going out as it is.
     *
     * @param list<string> $needs
     * @param callable(array<string, string|null>): (array<string, string|null>|false) $filter
     */
    public function filter(string $table, array $needs, callable $filter): void
    {
        $this->filters[$table] = $filter;
        $this->needs[$table] = $needs;
    }

    /** Writes the table's structure and none of its rows. */
    public function empty(string $table): void
    {
        $this->emptied[$table] = true;
    }

    /** Leaves the table out altogether: not even its structure. */
    public function skip(string $table): void
    {
        $this->skipped[$table] = true;
    }

    /** Calls $hook just before the rows of $table. */
    public function before(string $table, callable $hook): void
    {
        $this->before[$table] = $hook;
    }

    /**
     * These tables first, in this order, the rest after them alphabetically:
     * for rules that learn from one table what they need for another.
     *
     * @param list<string> $tables
     */
    public function first(array $tables): void
    {
        $this->first = $tables;
    }

    /**
     * Opens the snapshot. Call it before anything that the dump has to agree with
     * is read on this connection.
     */
    public function begin(): void
    {
        $this->pdo = $this->db->getMasterPdo();
        if ($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') {
            throw new RuntimeException('Alleen MySQL en MariaDB');
        }

        $other = $this->pdo->query("SELECT
            (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_type <> 'BASE TABLE')
          + (SELECT COUNT(*) FROM information_schema.triggers WHERE trigger_schema = DATABASE())
          + (SELECT COUNT(*) FROM information_schema.routines WHERE routine_schema = DATABASE())
          + (SELECT COUNT(*) FROM information_schema.events WHERE event_schema = DATABASE())")->fetchColumn();
        if ((int)$other > 0) {
            throw new RuntimeException('Deze database heeft views, sequences, versioned tables, triggers, routines of events; die schrijft dit niet mee');
        }

        // What mariadb-dump sets: no ANSI_QUOTES or NO_TABLE_OPTIONS of the
        // server's in SHOW CREATE TABLE, and no time limit on the long reads.
        $this->pdo->exec("SET SESSION sql_mode = '', SESSION sql_quote_show_create = 1");
        $mariadb = str_contains((string)$this->pdo->getAttribute(PDO::ATTR_SERVER_VERSION), 'MariaDB');
        $this->pdo->exec($mariadb ? 'SET SESSION max_statement_time = 0' : 'SET SESSION max_execution_time = 0');
        $this->pdo->exec('SET NAMES utf8mb4');
        $this->pdo->exec("SET time_zone = '+00:00'");
        $this->pdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $this->pdo->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT, READ ONLY');
        // Every value as the server writes it: a float through PHP loses digits.
        $this->pdo->setAttribute(PDO::ATTR_STRINGIFY_FETCHES, true);
    }

    /**
     * Writes the dump to $out.
     *
     * @return list<string> the filtered tables the database does not have
     */
    public function run(Output $out): array
    {
        $this->out = $out;
        // begin() refused any other kind of table.
        $tables = $this->pdo->query("SELECT table_name FROM information_schema.tables
            WHERE table_schema = DATABASE() ORDER BY table_name")->fetchAll(PDO::FETCH_COLUMN);
        $ordered = array_values(array_unique(array_merge(array_intersect($this->first, $tables), $tables)));

        $database = $this->pdo->query('SELECT DATABASE()')->fetchColumn();
        $this->write("-- Anonymized dump of `{$database}` by craft-anonymize\n\n"
            . "/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;\n"
            . "/*!40101 SET NAMES utf8mb4 */;\n"
            . "/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;\n"
            . "/*!40103 SET TIME_ZONE='+00:00' */;\n"
            . "/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;\n"
            . "/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;\n"
            . "/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;\n");

        foreach ($ordered as $table) {
            if (!isset($this->skipped[$table])) {
                $this->table($table);
            }
        }
        $this->write($this->trailer);

        $this->write("\n/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;\n"
            . "/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;\n"
            . "/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;\n"
            . "/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;\n"
            . "/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;\n\n"
            . '-- Dump completed on ' . gmdate('Y-m-d H:i:s') . " UTC\n");
        $this->flush();

        return array_values(array_diff(array_keys($this->filters), $tables));
    }

    /** Closes the snapshot. */
    public function end(): void
    {
        $this->pdo->exec('COMMIT');
    }

    private function table(string $table): void
    {
        $name = self::name($table);
        $create = $this->pdo->query("SHOW CREATE TABLE {$name}")->fetch(PDO::FETCH_NUM)[1];
        if (!$this->tableCollations) {
            $create = self::withoutTableCollation($create);
        }
        $this->write("\n--\n-- {$name}\n--\n\nDROP TABLE IF EXISTS {$name};\n{$create};\n");
        if (isset($this->emptied[$table])) {
            return;
        }

        $columns = $kinds = [];
        $query = $this->pdo->prepare("SELECT column_name, data_type, generation_expression FROM information_schema.columns
            WHERE table_schema = DATABASE() AND table_name = ? ORDER BY ordinal_position");
        $query->execute([$table]);
        foreach ($query->fetchAll(PDO::FETCH_NUM) as [$column, $type, $generated]) {
            if ($generated !== null && $generated !== '') {
                continue;
            }
            $columns[] = $column;
            $kinds[] = in_array($type, self::BINARY, true) ? 2 : (in_array($type, self::NUMERIC, true) ? 1 : 0);
        }
        $missing = array_diff($this->needs[$table] ?? [], $columns);
        if ($missing) {
            throw new RuntimeException("{$table} heeft geen kolom " . implode(', ', $missing) . '; de regels kennen deze database niet');
        }

        $query = $this->pdo->prepare("SELECT column_name FROM information_schema.statistics
            WHERE table_schema = DATABASE() AND table_name = ? AND index_name = 'PRIMARY' ORDER BY seq_in_index");
        $query->execute([$table]);
        $key = $query->fetchAll(PDO::FETCH_COLUMN);

        if (isset($this->before[$table])) {
            ($this->before[$table])();
        }
        $filter = $this->filters[$table] ?? null;
        if ($filter) {
            $this->stats[$table] = ['rows' => 0, 'changed' => 0, 'dropped' => 0];
        }

        $list = implode(',', array_map(self::name(...), $columns));
        // In key order: the rules count on a canonical element before its drafts.
        $select = "SELECT {$list} FROM {$name}" . ($key ? ' ORDER BY ' . implode(',', array_map(self::name(...), $key)) : '');
        $header = "INSERT INTO {$name} ({$list}) VALUES\n";

        $this->write("\nLOCK TABLES {$name} WRITE;\n/*!40000 ALTER TABLE {$name} DISABLE KEYS */;\n");
        $buffered = self::bufferedAttribute();
        $this->pdo->setAttribute($buffered, false);
        try {
            $rows = $this->pdo->query($select);
            $bytes = 0;
            while (($row = $rows->fetch(PDO::FETCH_NUM)) !== false) {
                if ($filter) {
                    $this->stats[$table]['rows']++;
                    $named = array_combine($columns, $row);
                    $changes = $filter($named);
                    if ($changes === false) {
                        $this->stats[$table]['dropped']++;
                        continue;
                    }
                    if ($changes) {
                        foreach ($changes as $column => $value) {
                            if (!array_key_exists($column, $named)) {
                                throw new RuntimeException("de regels schrijven {$table}.{$column}, die er niet is");
                            }
                            $named[$column] = $value;
                        }
                        $row = array_values($named);
                        $this->stats[$table]['changed']++;
                    }
                }

                $values = [];
                foreach ($row as $i => $value) {
                    if ($this->inspect !== null && $kinds[$i] === 0 && $value !== null && str_contains($value, '@')) {
                        ($this->inspect)($table, $columns[$i], $value);
                    }
                    $values[] = match (true) {
                        $value === null => 'NULL',
                        $kinds[$i] === 1 && is_numeric($value) => $value,
                        $kinds[$i] === 2 => $value === '' ? "''" : '0x' . bin2hex($value),
                        default => "'" . strtr($value, self::ESCAPE) . "'",
                    };
                }
                $tuple = '(' . implode(',', $values) . ')';

                $this->write($bytes === 0 ? $header : ",\n");
                $this->write($tuple);
                $bytes += strlen($tuple);
                if ($bytes >= self::STATEMENT_BYTES) {
                    $this->write(";\n");
                    $bytes = 0;
                }
            }
            if ($bytes > 0) {
                $this->write(";\n");
            }
            $rows->closeCursor();
        } finally {
            $this->pdo->setAttribute($buffered, true);
        }
        $this->write("/*!40000 ALTER TABLE {$name} ENABLE KEYS */;\nUNLOCK TABLES;\n");
    }

    /**
     * The table options without `COLLATE=…`: they follow the closing
     * parenthesis of the column list, where a column's own `COLLATE x` (no
     * `=`) cannot be.
     */
    private static function withoutTableCollation(string $create): string
    {
        $end = strrpos($create, "\n)");
        if ($end === false) {
            return $create;
        }

        return substr($create, 0, $end) . preg_replace('/ COLLATE=\w+/', '', substr($create, $end), 1);
    }

    /** Pdo\Mysql's from PHP 8.4, where the PDO constant is deprecated. */
    private static function bufferedAttribute(): int
    {
        return defined('Pdo\Mysql::ATTR_USE_BUFFERED_QUERY') ? constant('Pdo\Mysql::ATTR_USE_BUFFERED_QUERY') : PDO::MYSQL_ATTR_USE_BUFFERED_QUERY;
    }

    private static function name(string $name): string
    {
        return '`' . str_replace('`', '``', $name) . '`';
    }

    private function write(string $text): void
    {
        if (strlen($text) >= self::FLUSH_AT) {
            $this->flush();
            $this->out->write($text);

            return;
        }
        $this->buffer .= $text;
        if (strlen($this->buffer) >= self::FLUSH_AT) {
            $this->flush();
        }
    }

    private function flush(): void
    {
        if ($this->buffer !== '') {
            $this->out->write($this->buffer);
            $this->buffer = '';
        }
    }
}
