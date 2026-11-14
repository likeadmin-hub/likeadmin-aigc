<?php
namespace app\common\service\point;

use InvalidArgumentException;
use think\facade\Db;

/** Keep the historical Unicode comparison while making the bill side indexable. */
final class AccountLogSourceLookup
{
    private static array $available=[];

    public static function compatible(array $source,array $lookup): bool
    {
        $expression=strtolower(preg_replace('/[`\s]+/','',(string)($lookup['GENERATION_EXPRESSION']??'')));
        return ($lookup['COLLATION_NAME']??'')==='utf8mb4_unicode_ci'
            && stripos((string)($lookup['EXTRA']??''),'GENERATED')!==false
            && $expression==='convert(source_snusingutf8mb4)'
            && (int)($lookup['CHARACTER_MAXIMUM_LENGTH']??0)>=(int)($source['CHARACTER_MAXIMUM_LENGTH']??PHP_INT_MAX);
    }

    public static function expression(string $alias='ual'): string
    {
        if(!preg_match('/^[a-z_][a-z0-9_]*$/iD',$alias))throw new InvalidArgumentException('流水关联别名无效');
        $table=Db::name('user_account_log')->getTable();
        if(!array_key_exists($table,self::$available)){
            $columns=[];
            foreach(Db::query('SELECT COLUMN_NAME,COLLATION_NAME,CHARACTER_MAXIMUM_LENGTH,EXTRA,GENERATION_EXPRESSION FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME IN (\'source_sn\',\'source_sn_lookup\')',[$table]) as$row)$columns[$row['COLUMN_NAME']]=$row;
            self::$available[$table]=isset($columns['source_sn'],$columns['source_sn_lookup'])&&self::compatible($columns['source_sn'],$columns['source_sn_lookup']);
        }
        return self::$available[$table]?$alias.'.source_sn_lookup':self::legacyExpression($alias);
    }

    public static function legacyExpression(string $alias='ual'): string
    {
        if(!preg_match('/^[a-z_][a-z0-9_]*$/iD',$alias))throw new InvalidArgumentException('流水关联别名无效');
        return 'CONVERT('.$alias.'.source_sn USING utf8mb4) COLLATE utf8mb4_unicode_ci';
    }

    public static function clear(): void {self::$available=[];}
}
