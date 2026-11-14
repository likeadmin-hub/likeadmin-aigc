<?php
namespace app\common\service\database;

use RuntimeException;
use think\facade\Db;

/** Additive, idempotent indexes reviewed against the discovery and list queries. */
final class QueryPerformanceSchema
{
    public const INDEXES = [
        'config' => [
            'idx_config_key' => ['type','name'],
        ],
        'tenant_config' => [
            'idx_tenant_config_key' => ['tenant_id','type','name'],
        ],
        'power_market_product' => [
            'idx_market_model_catalog' => ['resource_type','model_type','status','update_time','id'],
            'idx_market_resource_catalog' => ['resource_type','status','update_time','id'],
        ],
        'power_market_sku' => [
            'idx_product_available_sku' => ['product_id','status','sale_status','id'],
        ],
        'tenant_point_log' => [
            'idx_consume_page' => ['change_type','action','id'],
            'idx_tenant_consume_page' => ['tenant_id','change_type','action','id'],
        ],
        'user_account_log' => [
            'idx_tenant_source_bill' => ['tenant_id','source_sn_lookup(64)','action','change_type','delete_time'],
            'idx_user_bill_page' => ['tenant_id','user_id','delete_time','id'],
            'idx_tenant_user_consumption' => ['tenant_id','change_type','action','user_id'],
        ],
        'tenant_app_order' => [
            'idx_tenant_paid_expiry' => ['tenant_id','pay_status','app_code','after_expire_time'],
        ],
        'user' => [
            'idx_tenant_user_page' => ['tenant_id','delete_time','id'],
        ],
        'app_frontend_entry' => [
            'idx_terminal_enabled_sort' => ['terminal','status','sort','id'],
        ],
        'file' => [
            'idx_file_group_page' => ['delete_time','cid','id'],
            'idx_file_type_page' => ['delete_time','type','id'],
        ],
        'aigc_action_transfer_result' => [
            'idx_owner_history_page' => ['tenant_id','user_id','delete_time','id'],
        ],
        'aigc_action_transfer_task' => [
            'idx_owner_history_page' => ['tenant_id','user_id','delete_time','id'],
        ],
        'aigc_background_removal_result' => [
            'idx_owner_history_page' => ['tenant_id','user_id','delete_time','id'],
        ],
        'aigc_background_removal_task' => [
            'idx_owner_history_page' => ['tenant_id','user_id','delete_time','id'],
        ],
        'aigc_copywriting_task' => [
            'idx_owner_history_page' => ['tenant_id','user_id','delete_time','id'],
        ],
        'aigc_fashion_lookbook_result' => [
            'idx_owner_history_page' => ['tenant_id','user_id','delete_time','id'],
        ],
        'aigc_fashion_lookbook_task' => [
            'idx_owner_history_page' => ['tenant_id','user_id','delete_time','id'],
        ],
        'aigc_fitting_task' => [
            'idx_owner_history_page' => ['tenant_id','user_id','delete_time','id'],
        ],
        'aigc_image_result' => [
            'idx_owner_history_page' => ['tenant_id','user_id','delete_time','id'],
        ],
        'aigc_image_translate_result' => [
            'idx_owner_history_page' => ['tenant_id','user_id','delete_time','id'],
        ],
        'aigc_image_translate_task' => [
            'idx_owner_history_page' => ['tenant_id','user_id','delete_time','id'],
        ],
        'aigc_local_redraw_result' => [
            'idx_owner_history_page' => ['tenant_id','user_id','delete_time','id'],
        ],
        'aigc_local_redraw_task' => [
            'idx_owner_history_page' => ['tenant_id','user_id','delete_time','id'],
        ],
        'aigc_model_wear_result' => [
            'idx_owner_history_page' => ['tenant_id','user_id','delete_time','id'],
        ],
        'aigc_model_wear_task' => [
            'idx_owner_history_page' => ['tenant_id','user_id','delete_time','id'],
        ],
        'aigc_music_cover_result' => [
            'idx_owner_history_page' => ['tenant_id','user_id','delete_time','id'],
        ],
        'aigc_music_cover_task' => [
            'idx_owner_history_page' => ['tenant_id','user_id','delete_time','id'],
        ],
        'aigc_music_result' => [
            'idx_owner_history_page' => ['tenant_id','user_id','delete_time','id'],
        ],
        'aigc_music_task' => [
            'idx_owner_history_page' => ['tenant_id','user_id','delete_time','id'],
        ],
        'aigc_one_click_cleanup_result' => [
            'idx_owner_history_page' => ['tenant_id','user_id','delete_time','id'],
        ],
        'aigc_one_click_cleanup_task' => [
            'idx_owner_history_page' => ['tenant_id','user_id','delete_time','id'],
        ],
        'aigc_outpaint_result' => [
            'idx_owner_history_page' => ['tenant_id','user_id','delete_time','id'],
        ],
        'aigc_outpaint_task' => [
            'idx_owner_history_page' => ['tenant_id','user_id','delete_time','id'],
        ],
        'aigc_person_replacement_result' => [
            'idx_owner_history_page' => ['tenant_id','user_id','delete_time','id'],
        ],
        'aigc_person_replacement_task' => [
            'idx_owner_history_page' => ['tenant_id','user_id','delete_time','id'],
        ],
        'aigc_photo_restore_result' => [
            'idx_owner_history_page' => ['tenant_id','user_id','delete_time','id'],
        ],
        'aigc_photo_restore_task' => [
            'idx_owner_history_page' => ['tenant_id','user_id','delete_time','id'],
        ],
        'aigc_pic_lipsync_result' => [
            'idx_owner_history_page' => ['tenant_id','user_id','delete_time','id'],
        ],
        'aigc_pic_lipsync_task' => [
            'idx_owner_history_page' => ['tenant_id','user_id','delete_time','id'],
        ],
        'aigc_product_image_result' => [
            'idx_owner_history_page' => ['tenant_id','user_id','delete_time','id'],
        ],
        'aigc_product_image_task' => [
            'idx_owner_history_page' => ['tenant_id','user_id','delete_time','id'],
        ],
        'aigc_product_multi_angle_result' => [
            'idx_owner_history_page' => ['tenant_id','user_id','delete_time','id'],
        ],
        'aigc_product_multi_angle_task' => [
            'idx_owner_history_page' => ['tenant_id','user_id','delete_time','id'],
        ],
        'aigc_product_promo_video_result' => [
            'idx_owner_history_page' => ['tenant_id','user_id','delete_time','id'],
        ],
        'aigc_product_promo_video_task' => [
            'idx_owner_history_page' => ['tenant_id','user_id','delete_time','id'],
        ],
        'aigc_product_suite_result' => [
            'idx_owner_history_page' => ['tenant_id','user_id','delete_time','id'],
        ],
        'aigc_product_suite_task' => [
            'idx_owner_history_page' => ['tenant_id','user_id','delete_time','id'],
        ],
        'aigc_short_drama_episode_task' => [
            'idx_owner_history_page' => ['tenant_id','user_id','delete_time','id'],
        ],
        'aigc_short_drama_generation_task' => [
            'idx_owner_history_page' => ['tenant_id','user_id','delete_time','id'],
        ],
        'aigc_style_transfer_result' => [
            'idx_owner_history_page' => ['tenant_id','user_id','delete_time','id'],
        ],
        'aigc_style_transfer_task' => [
            'idx_owner_history_page' => ['tenant_id','user_id','delete_time','id'],
        ],
        'aigc_video_result' => [
            'idx_owner_history_page' => ['tenant_id','user_id','delete_time','id'],
        ],
        'aigc_video_task' => [
            'idx_owner_history_page' => ['tenant_id','user_id','delete_time','id'],
        ],
        'aigc_watermark_removal_result' => [
            'idx_owner_history_page' => ['tenant_id','user_id','delete_time','id'],
        ],
        'aigc_watermark_removal_task' => [
            'idx_owner_history_page' => ['tenant_id','user_id','delete_time','id'],
        ],
    ];
    public static function apply(string $prefix, $connection = null): array
    {
        if (!preg_match('/^[a-z0-9_]*$/iD',$prefix)) throw new RuntimeException('查询索引表前缀无效');
        $query=static fn(string $sql,array $params=[])=>$connection?$connection->query($sql,$params):Db::query($sql,$params);
        $execute=static fn(string $sql)=>$connection?$connection->execute($sql):Db::execute($sql);
        $tables=[];
        foreach($query('SELECT TABLE_NAME,ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE()') as $row)$tables[$row['TABLE_NAME']]=$row['ENGINE'];
        $suffixes=[''];
        if(isset($tables[$prefix.'tenant']))foreach($query('SELECT sn FROM `'.$prefix.'tenant`') as $row){
            $sn=(string)$row['sn'];if(preg_match('/^[a-z0-9]{1,32}$/iD',$sn))$suffixes[]='_'.$sn;
        }
        $actions=[];$skipped=[];
        foreach(self::INDEXES as $base=>$definitions)foreach(array_unique($suffixes) as $suffix){
            $table=$prefix.$base.$suffix;if(!isset($tables[$table]))continue;
            $columns=[];foreach($query('SHOW FULL COLUMNS FROM `'.$table.'`') as $column)$columns[$column['Field']]=$column;
            if($base==='user_account_log' && isset($columns['source_sn'])){
                if(!isset($columns['source_sn_lookup'])){
                    if(strcasecmp((string)$tables[$table],'InnoDB')!==0 || !preg_match('/^varchar\((\d+)\)$/iD',$columns['source_sn']['Type'],$length))throw new RuntimeException('流水关联索引要求 InnoDB 和 VARCHAR 来源单号');
                    $execute('ALTER TABLE `'.$table.'` ADD COLUMN `source_sn_lookup` varchar('.(int)$length[1].') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci GENERATED ALWAYS AS (CONVERT(`source_sn` USING utf8mb4)) VIRTUAL, ALGORITHM=INPLACE, LOCK=NONE');
                    $actions[]=['table'=>$table,'column'=>'source_sn_lookup'];
                    foreach($query('SHOW FULL COLUMNS FROM `'.$table.'`') as $column)$columns[$column['Field']]=$column;
                }
                $definition=[];
                foreach($query('SELECT COLUMN_NAME,COLLATION_NAME,CHARACTER_MAXIMUM_LENGTH,EXTRA,GENERATION_EXPRESSION FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME IN (\'source_sn\',\'source_sn_lookup\')',[$table])as$row)$definition[$row['COLUMN_NAME']]=$row;
                if(!\app\common\service\point\AccountLogSourceLookup::compatible($definition['source_sn'],$definition['source_sn_lookup']))throw new RuntimeException('流水关联列已存在但定义不兼容');
            }
            $indices=[];
            foreach($query('SHOW INDEX FROM `'.$table.'`')as$row){$column=$row['Column_name'];if($row['Sub_part']!==null)$column.='('.$row['Sub_part'].')';$indices[$row['Key_name']][]=$column;}
            $clauses=[];
            foreach($definitions as$name=>$wanted){
                $missing=array_filter($wanted,static fn($column)=>!isset($columns[preg_replace('/\(\d+\)$/','',$column)]));
                if($missing){$skipped[]=['table'=>$table,'index'=>$name,'missing_columns'=>array_values($missing)];continue;}
                if(self::covered($indices,$wanted,strcasecmp((string)$tables[$table],'InnoDB')===0))continue;
                if(isset($indices[$name]))throw new RuntimeException('索引名称已被不同定义使用：'.$table.'.'.$name);
                $parts=array_map(static function($column){preg_match('/^([a-z0-9_]+)(\(\d+\))?$/iD',$column,$match);return '`'.$match[1].'`'.($match[2]??'');},$wanted);
                $clauses[]='ADD INDEX `'.$name.'` ('.implode(',',$parts).')';
                $indices[$name]=$wanted;$actions[]=['table'=>$table,'index'=>$name,'columns'=>$wanted];
            }
            if($clauses)$execute('ALTER TABLE `'.$table.'` '.implode(', ',$clauses).', ALGORITHM=INPLACE, LOCK=NONE');
        }
        \app\common\service\point\AccountLogSourceLookup::clear();
        return ['actions'=>$actions,'skipped'=>$skipped];
    }

    /** Include InnoDB's implicit primary-key suffix when checking existing coverage. */
    public static function covered(array $indices,array $wanted,bool $innodb=true): bool
    {
        foreach($indices as $columns){
            if($innodb)foreach($indices['PRIMARY']??[]as$column)if(!in_array($column,$columns,true))$columns[]=$column;
            if(count($columns)<count($wanted))continue;
            foreach($wanted as$i=>$target){
                preg_match('/^([a-z0-9_]+)(?:\((\d+)\))?$/iD',$target,$a);
                preg_match('/^([a-z0-9_]+)(?:\((\d+)\))?$/iD',$columns[$i],$b);
                if(($a[1]??null)!==($b[1]??null))continue 2;
                if(isset($b[2])&&(!isset($a[2])||(int)$b[2]<(int)$a[2]))continue 2;
            }
            return true;
        }
        return false;
    }
}
