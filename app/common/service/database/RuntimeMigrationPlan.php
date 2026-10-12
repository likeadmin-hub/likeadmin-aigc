<?php
namespace app\common\service\database;

use RuntimeException;

/** New migrations only: additive static DDL is prepared; static DML joins the final transaction. */
final class RuntimeMigrationPlan
{
    public static function build(string $content): array
    {
        $prepare = []; $complete = []; $tables = [];
        foreach (SqlMigrationExecutor::split($content) as $sql) {
            // Inspect SQL syntax without matching action words or @ inside string data.
            $syntax = preg_replace("/'(?:''|\\\\.|[^'])*'/s", "''", $sql);
            $syntax = preg_replace('/"(?:""|\\\\.|[^"])*"/s', '""', $syntax);
            if (strpos($syntax, '@') !== false) throw new RuntimeException('新迁移不支持会话变量或动态 SQL，请使用静态迁移');
            if (preg_match('/^\s*(INSERT|UPDATE|DELETE|REPLACE)\b/i', $syntax)) {
                if (preg_match('/\b(?:OUTFILE|DUMPFILE)\b/i', $syntax)) throw new RuntimeException('迁移不允许写入数据库外文件');
                if (!preg_match('/^\s*(?:(?:INSERT(?:\s+IGNORE)?|REPLACE)\s+INTO|UPDATE|DELETE\s+FROM)\s+`?([a-z0-9_]+)`?(?=\s|\()/i', $syntax, $target)) throw new RuntimeException('迁移数据写入目标不明确，禁止执行');
                if (preg_match('/\b(?:JOIN|USING)\b/i', $syntax) || (preg_match('/^\s*UPDATE\b(.*?)\bSET\b/is', $syntax, $update) && strpos($update[1], ',') !== false)) throw new RuntimeException('新迁移不支持多表数据写入，请拆分为明确目标');
                $tables[] = $target[1];
                $complete[] = $sql;
            } elseif (preg_match('/^\s*CREATE\s+TABLE\s+IF\s+NOT\s+EXISTS\b/i', $syntax)
                && !preg_match('/\b(?:SELECT|LIKE)\b/i', $syntax)) {
                $prepare[] = $sql;
            } elseif (preg_match('/^\s*ALTER\s+TABLE\s+`?la_aigc_video_config`?\s+MODIFY\s+(?:COLUMN\s+)?`?config_json`?\s+MEDIUMTEXT\s*$/iD', $sql)) {
                // The executor reads the original column/engine and performs only
                // this reviewed, lossless capacity increase (or a true no-op).
                $prepare[] = $sql;
            } elseif (preg_match('/^\s*ALTER\s+TABLE\s+`?[a-z0-9_]+`?\s+ADD\s+(?:COLUMN\s+|(?:UNIQUE\s+)?(?:INDEX|KEY)\s+)/i', $syntax)
                && !preg_match('/\b(?:DROP|MODIFY|CHANGE|RENAME|TRUNCATE|ALTER|ADD)\b/i', preg_replace('/^\s*ALTER\s+TABLE\s+`?[a-z0-9_]+`?\s+ADD\b/i', '', $syntax))) {
                $prepare[] = $sql;
            } else {
                // Dynamic SQL and transaction control cannot be reliably separated and must fail closed.
                throw new RuntimeException('迁移包含无法安全分类的语句，禁止执行');
            }
        }
        return ['prepare'=>implode(";\n", $prepare), 'complete'=>implode(";\n", $complete), 'tables'=>array_values(array_unique($tables))];
    }
}
