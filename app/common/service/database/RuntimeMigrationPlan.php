<?php
namespace app\common\service\database;

use RuntimeException;

/** New migrations only: additive static DDL is prepared; static DML joins the final transaction. */
final class RuntimeMigrationPlan
{
    public static function build(string $content): array
    {
        $prepare = []; $complete = [];
        foreach (SqlMigrationExecutor::split($content) as $sql) {
            // Inspect SQL syntax without matching action words or @ inside string data.
            $syntax = preg_replace("/'(?:''|\\\\.|[^'])*'/s", "''", $sql);
            $syntax = preg_replace('/"(?:""|\\\\.|[^"])*"/s', '""', $syntax);
            if (strpos($syntax, '@') !== false) throw new RuntimeException('新迁移不支持会话变量或动态 SQL，请使用静态迁移');
            if (preg_match('/^\s*(INSERT|UPDATE|DELETE|REPLACE)\b/i', $syntax)) {
                if (preg_match('/\b(?:OUTFILE|DUMPFILE)\b/i', $syntax)) throw new RuntimeException('迁移不允许写入数据库外文件');
                $complete[] = $sql;
            } elseif (preg_match('/^\s*CREATE\s+TABLE\s+IF\s+NOT\s+EXISTS\b/i', $syntax)
                && !preg_match('/\b(?:SELECT|LIKE)\b/i', $syntax)) {
                $prepare[] = $sql;
            } elseif (preg_match('/^\s*ALTER\s+TABLE\s+`?[a-z0-9_]+`?\s+ADD\s+(?:COLUMN\s+|(?:UNIQUE\s+)?(?:INDEX|KEY)\s+)/i', $syntax)
                && !preg_match('/\b(?:DROP|MODIFY|CHANGE|RENAME|TRUNCATE|ALTER|ADD)\b/i', preg_replace('/^\s*ALTER\s+TABLE\s+`?[a-z0-9_]+`?\s+ADD\b/i', '', $syntax))) {
                $prepare[] = $sql;
            } else {
                // Dynamic SQL and transaction control cannot be reliably separated and must fail closed.
                throw new RuntimeException('迁移包含无法安全分类的语句，禁止执行');
            }
        }
        return ['prepare'=>implode(";\n", $prepare), 'complete'=>implode(";\n", $complete)];
    }
}
