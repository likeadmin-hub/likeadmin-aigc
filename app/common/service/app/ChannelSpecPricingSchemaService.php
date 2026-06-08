<?php

namespace app\common\service\app;

use think\facade\Db;

class ChannelSpecPricingSchemaService
{
    private static array $checked = [];

    public static function ensure(string $table, string $upstreamComment = '上游成本单价'): void
    {
        if (!preg_match('/^[a-z0-9_]+$/', $table)) {
            return;
        }
        $fullTable = env('database.prefix', 'la_') . $table;
        if (isset(self::$checked[$fullTable])) {
            return;
        }
        if (!self::tableExists($fullTable)) {
            self::$checked[$fullTable] = true;
            return;
        }
        self::addColumnIfMissing(
            $fullTable,
            'upstream_unit_cost',
            "`upstream_unit_cost` decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT '" . self::quoteComment($upstreamComment) . "' AFTER `height`"
        );
        self::addColumnIfMissing(
            $fullTable,
            'upstream_cost_text',
            "`upstream_cost_text` varchar(500) NOT NULL DEFAULT '' COMMENT '上游成本说明' AFTER `tenant_unit_price`"
        );
        self::addColumnIfMissing(
            $fullTable,
            'cost_source_url',
            "`cost_source_url` varchar(500) NOT NULL DEFAULT '' COMMENT '成本来源链接' AFTER `upstream_cost_text`"
        );
        self::$checked[$fullTable] = true;
    }

    private static function tableExists(string $fullTable): bool
    {
        return !empty(Db::query(
            "SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{$fullTable}' LIMIT 1"
        ));
    }

    private static function addColumnIfMissing(string $fullTable, string $column, string $definition): void
    {
        if (!empty(Db::query(
            "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{$fullTable}' AND COLUMN_NAME = '{$column}' LIMIT 1"
        ))) {
            return;
        }
        Db::execute("ALTER TABLE `{$fullTable}` ADD COLUMN {$definition}");
    }

    private static function quoteComment(string $comment): string
    {
        return str_replace("'", "''", $comment);
    }
}
