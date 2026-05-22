<?php

namespace app\common\service\database;

use think\facade\Db;
use Throwable;

class SqlMigrationExecutor
{
    /**
     * Execute SQL statements after stripping SQL comments and splitting on
     * semicolons outside quoted strings.
     */
    public static function execute(
        string $content,
        string $prefix,
        $connection = null,
        bool $ignoreDuplicateColumn = true,
        string $prefixStyle = 'mysql'
    ): void {
        foreach (self::split($content) as $sql) {
            $statement = self::applyPrefix($sql, $prefix, $prefixStyle) . ';';
            try {
                if ($connection) {
                    $connection->execute($statement);
                } else {
                    Db::execute($statement);
                }
            } catch (Throwable $e) {
                if ($ignoreDuplicateColumn && self::isDuplicateAddColumn($statement, $e)) {
                    continue;
                }
                if ($ignoreDuplicateColumn && self::isAddColumnDuplicateError($statement, $e) && self::executeAddColumnsIndividually($statement, $connection)) {
                    continue;
                }
                throw $e;
            }
        }
    }

    public static function split(string $content): array
    {
        $statements = [];
        $statement = '';
        $length = strlen($content);
        $quote = null;

        for ($i = 0; $i < $length; $i++) {
            $char = $content[$i];
            $next = $content[$i + 1] ?? '';

            if ($quote !== null) {
                $statement .= $char;

                if ($char === '\\' && ($quote === '\'' || $quote === '"') && $next !== '') {
                    $statement .= $next;
                    $i++;
                    continue;
                }

                if ($char === $quote) {
                    if ($next === $quote) {
                        $statement .= $next;
                        $i++;
                        continue;
                    }
                    $quote = null;
                }
                continue;
            }

            if ($char === '\'' || $char === '"' || $char === '`') {
                $quote = $char;
                $statement .= $char;
                continue;
            }

            if ($char === '-' && $next === '-' && self::isCommentBoundary($content[$i + 2] ?? '')) {
                $i = self::skipLineComment($content, $i + 2);
                $statement .= "\n";
                continue;
            }

            if ($char === '#') {
                $i = self::skipLineComment($content, $i + 1);
                $statement .= "\n";
                continue;
            }

            if ($char === '/' && $next === '*') {
                $i = self::skipBlockComment($content, $i + 2);
                $statement .= ' ';
                continue;
            }

            if ($char === ';') {
                $sql = trim($statement);
                if ($sql !== '') {
                    $statements[] = $sql;
                }
                $statement = '';
                continue;
            }

            $statement .= $char;
        }

        $sql = trim($statement);
        if ($sql !== '') {
            $statements[] = $sql;
        }

        return $statements;
    }

    private static function skipLineComment(string $content, int $offset): int
    {
        $newline = strpos($content, "\n", $offset);
        if ($newline === false) {
            return strlen($content);
        }
        return $newline;
    }

    private static function skipBlockComment(string $content, int $offset): int
    {
        $end = strpos($content, '*/', $offset);
        if ($end === false) {
            return strlen($content);
        }
        return $end + 1;
    }

    private static function isCommentBoundary(string $char): bool
    {
        return $char === '' || ctype_space($char);
    }

    private static function isDuplicateAddColumn(string $statement, Throwable $e): bool
    {
        if (substr_count(strtoupper($statement), 'ADD COLUMN') !== 1) {
            return false;
        }

        return self::isAddColumnDuplicateError($statement, $e);
    }

    private static function isAddColumnDuplicateError(string $statement, Throwable $e): bool
    {
        if (!preg_match('/^\s*ALTER\s+TABLE\s+`?[\w]+`?\s+ADD\s+COLUMN\s+/i', $statement)) {
            return false;
        }

        $message = $e->getMessage();
        return strpos($message, '1060') !== false
            || stripos($message, 'Duplicate column') !== false
            || stripos($message, '42S21') !== false;
    }

    private static function executeAddColumnsIndividually(string $statement, $connection = null): bool
    {
        if (!preg_match('/^\s*ALTER\s+TABLE\s+(`?[\w]+`?)\s+(.+);?\s*$/is', rtrim($statement, ';'), $matches)) {
            return false;
        }

        $clauses = self::splitCommaClauses($matches[2]);
        if (count($clauses) <= 1) {
            return false;
        }

        $handled = false;
        foreach ($clauses as $clause) {
            $clause = trim($clause);
            if (!preg_match('/^ADD\s+COLUMN\s+/i', $clause)) {
                return false;
            }

            $single = 'ALTER TABLE ' . $matches[1] . ' ' . $clause . ';';
            try {
                if ($connection) {
                    $connection->execute($single);
                } else {
                    Db::execute($single);
                }
                $handled = true;
            } catch (Throwable $e) {
                if (!self::isDuplicateAddColumn($single, $e)) {
                    throw $e;
                }
                $handled = true;
            }
        }

        return $handled;
    }

    private static function splitCommaClauses(string $content): array
    {
        $clauses = [];
        $clause = '';
        $length = strlen($content);
        $quote = null;
        $depth = 0;

        for ($i = 0; $i < $length; $i++) {
            $char = $content[$i];
            $next = $content[$i + 1] ?? '';

            if ($quote !== null) {
                $clause .= $char;
                if ($char === '\\' && ($quote === '\'' || $quote === '"') && $next !== '') {
                    $clause .= $next;
                    $i++;
                    continue;
                }
                if ($char === $quote) {
                    if ($next === $quote) {
                        $clause .= $next;
                        $i++;
                        continue;
                    }
                    $quote = null;
                }
                continue;
            }

            if ($char === '\'' || $char === '"' || $char === '`') {
                $quote = $char;
                $clause .= $char;
                continue;
            }

            if ($char === '(') {
                $depth++;
                $clause .= $char;
                continue;
            }

            if ($char === ')' && $depth > 0) {
                $depth--;
                $clause .= $char;
                continue;
            }

            if ($char === ',' && $depth === 0) {
                $clauses[] = trim($clause);
                $clause = '';
                continue;
            }

            $clause .= $char;
        }

        $clause = trim($clause);
        if ($clause !== '') {
            $clauses[] = $clause;
        }

        return $clauses;
    }

    private static function applyPrefix(string $sql, string $prefix, string $style): string
    {
        if ($style === 'pgsql') {
            return str_replace('la_', $prefix, $sql);
        }

        return str_replace('`la_', '`' . $prefix, $sql);
    }
}
