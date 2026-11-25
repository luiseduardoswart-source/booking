<?php

namespace App\Support\Backups;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use PDO;
use RuntimeException;

/**
 * The whole database as SQL, written by PHP itself. mysqldump would need a
 * separate process (proc_open), which shared hosting does not allow.
 *
 * The file can be imported as it is, for example in phpMyAdmin: per table
 * DROP TABLE, CREATE TABLE and the rows in INSERTs of ROWS_PER_INSERT rows.
 * The rows are read one by one (unbuffered), so a large table does not have
 * to fit in memory.
 */
final class DatabaseDump
{
    /** Tables whose rows rebuild themselves: only their structure is kept. */
    private const STRUCTURE_ONLY = ['cache', 'cache_locks', 'sessions', 'jobs', 'job_batches'];

    private const ROWS_PER_INSERT = 200;

    public static function writeTo(string $path, ?string $connection = null): void
    {
        $database = DB::connection($connection);

        if (! in_array($database->getDriverName(), ['mysql', 'mariadb'], true)) {
            throw new RuntimeException(__('A backup needs a MySQL or MariaDB database.'));
        }

        $file = fopen($path, 'wb');

        if ($file === false) {
            throw new RuntimeException(__('The backup could not be written.'));
        }

        // One consistent picture of all tables, also while guests book. Not
        // inside an open transaction: START TRANSACTION would commit it.
        $snapshot = $database->transactionLevel() === 0;

        try {
            if ($snapshot) {
                $database->statement('START TRANSACTION WITH CONSISTENT SNAPSHOT');
            }

            fwrite($file, "-- Backup of the database, made on ".now()->toIso8601String()."\n"
                ."SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS = 0;\nSET UNIQUE_CHECKS = 0;\nSET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';\n\n");

            foreach (self::tables($database) as $table) {
                self::writeTable($database, $file, $table);
            }

            fwrite($file, "SET FOREIGN_KEY_CHECKS = 1;\nSET UNIQUE_CHECKS = 1;\n");
        } finally {
            if ($snapshot) {
                $database->statement('COMMIT');
            }

            fclose($file);
        }
    }

    /** @return array<int, string> */
    private static function tables(Connection $database): array
    {
        return collect($database->select("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'"))
            ->map(fn (object $row): string => (string) array_values((array) $row)[0])
            ->all();
    }

    /** @param resource $file */
    private static function writeTable(Connection $database, $file, string $table): void
    {
        $name = self::identifier($table);
        $create = array_values((array) $database->selectOne("SHOW CREATE TABLE {$name}"))[1];

        fwrite($file, "DROP TABLE IF EXISTS {$name};\n{$create};\n\n");

        if (in_array($table, self::STRUCTURE_ONLY, true)) {
            return;
        }

        $pdo = $database->getPdo();
        $buffered = self::bufferedQueryAttribute();
        $wasBuffered = $pdo->getAttribute($buffered);
        $pdo->setAttribute($buffered, false);

        try {
            $rows = $pdo->query("SELECT * FROM {$name}", PDO::FETCH_NUM);
            $values = [];

            foreach ($rows as $row) {
                $values[] = '('.implode(',', array_map(fn ($value) => self::literal($pdo, $value), $row)).')';

                if (count($values) === self::ROWS_PER_INSERT) {
                    fwrite($file, "INSERT INTO {$name} VALUES\n".implode(",\n", $values).";\n");
                    $values = [];
                }
            }

            if ($values !== []) {
                fwrite($file, "INSERT INTO {$name} VALUES\n".implode(",\n", $values).";\n");
            }

            $rows->closeCursor();
        } finally {
            $pdo->setAttribute($buffered, $wasBuffered);
        }

        fwrite($file, "\n");
    }

    private static function literal(PDO $pdo, mixed $value): string
    {
        return match (true) {
            $value === null => 'NULL',
            is_bool($value) => $value ? '1' : '0',
            is_int($value), is_float($value) => (string) $value,
            // Binary data (a hash, an encrypted value) as hex, so the file stays valid text.
            ! mb_check_encoding((string) $value, 'UTF-8') => '0x'.bin2hex((string) $value),
            default => $pdo->quote((string) $value),
        };
    }

    private static function identifier(string $name): string
    {
        return '`'.str_replace('`', '``', $name).'`';
    }

    private static function bufferedQueryAttribute(): int
    {
        return class_exists(\Pdo\Mysql::class) ? \Pdo\Mysql::ATTR_USE_BUFFERED_QUERY : PDO::MYSQL_ATTR_USE_BUFFERED_QUERY;
    }
}
