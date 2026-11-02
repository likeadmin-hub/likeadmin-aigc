<?php
namespace app\common\service\ai;

use think\facade\Db;

/** A model task is an execution record; its owning tool remains the work's source. */
final class GenerationWorkSourceService
{
    private const IMAGE_TOOLS = ['product_image','style_transfer','photo_restore','model_wear','background_removal','image_translate','one_click_cleanup','product_suite','product_multi_angle','fashion_lookbook','outpaint','local_redraw','fitting'];

    private static function relations(string $app): array
    {
        $image = $app === 'aigc_image';
        $relations = $image ? array_map(static fn($tool) => ['app'=>'aigc_'.$tool,'table'=>'aigc_'.$tool.'_task','fields'=>['image_task_id','image_task_ids']], self::IMAGE_TOOLS)
            : [['app'=>'aigc_product_promo_video','table'=>'aigc_product_promo_video_task','fields'=>['video_task_id']]];
        $relations[] = ['app'=>'aigc_short_drama','table'=>'aigc_short_drama_canvas_run','fields'=>['provider_task_id'],'type'=>['node_type',$image ? 'image' : 'video'],'prefix'=>'canvas_run_'];
        $relations[] = ['app'=>'aigc_canvas','table'=>'aigc_canvas_run','fields'=>['source_task_id'],'type'=>['run_type',$image ? 'image' : 'video'],'where'=>['source_app_code'=>$app]];
        // Only explicit child IDs are local tasks; a numeric provider receipt is not sufficient.
        if ($image) $relations[] = ['app'=>'aigc_short_drama','table'=>'aigc_short_drama_generation_task','fields'=>['result_json'],'type'=>['task_type','%image%'],'key'=>'task_id'];
        $result = [];
        foreach ($relations as $relation) {
            $fields = self::fields($relation['table']);
            $relation['fields'] = array_values(array_intersect($relation['fields'], $fields));
            if ($relation['fields'] && in_array('tenant_id',$fields,true) && in_array('user_id',$fields,true)) $result[] = $relation;
        }
        return $result;
    }

    private static function fields(string $table): array
    {
        static $cache = [];
        if (!isset($cache[$table])) {
            try { $cache[$table] = array_keys(Db::name($table)->getFields()); }
            catch (\Throwable) { $cache[$table] = []; }
        }
        return $cache[$table];
    }

    private static function matchSql(array $relation, string $id): string
    {
        $parts = [];
        foreach ($relation['fields'] as $field) {
            $column = 's.`'.$field.'`';
            if ($field === 'result_json') {
                $json = "IF(JSON_VALID($column),$column,'{}')";
                $parts[] = "CAST(JSON_UNQUOTE(JSON_EXTRACT($json,'$.image_task_id')) AS UNSIGNED)=$id";
            } elseif ($field === 'image_task_ids') {
                // Historical JSON contains either integer or string IDs. Invalid legacy JSON is empty.
                $json = "IF(JSON_VALID($column),$column,'[]')";
                $parts[] = "(JSON_CONTAINS($json,CAST($id AS CHAR)) OR JSON_CONTAINS($json,JSON_QUOTE(CAST($id AS CHAR))))";
            } else $parts[] = "($column REGEXP '^[0-9]+$' AND CAST($column AS UNSIGNED)=$id)";
        }
        return '('.implode(' OR ',$parts).')';
    }

    private static function constrain($query, array $relation): void
    {
        foreach ($relation['where'] ?? [] as $field=>$value) $query->where('s.'.$field,$value);
        if (isset($relation['type'])) {
            [$field,$value] = $relation['type'];
            $query->where('s.'.$field, str_contains($value,'%') ? 'like' : '=', $value);
        }
    }

    /** Apply before count/limit so another tool's jobs cannot consume history pages. */
    public static function ownOnly($query, string $app, string $alias = ''): void
    {
        $outer = $alias !== '' ? $alias : Db::name($app.'_task')->getTable();
        $column = static fn($field) => '`'.$outer.'`.`'.$field.'`';
        foreach (self::relations($app) as $relation) {
            $query->whereNotExists(function($sub) use ($relation,$column) {
                $sub->name($relation['table'])->alias('s')->fieldRaw('1')
                    ->whereRaw('s.tenant_id='.$column('tenant_id').' AND s.user_id='.$column('user_id'))
                    ->whereRaw(self::matchSql($relation,$column('id')));
                self::constrain($sub,$relation);
            });
        }
        if ($app === 'aigc_image') {
            $styles = array_merge(self::IMAGE_TOOLS,['hairstyle']);
            $query->whereNotIn(($alias !== '' ? $alias.'.' : '').'style',$styles);
        }
    }

    /** Batch source relationships, never one lookup for every displayed work. */
    public static function appendRows(array $rows, string $app): array
    {
        $owners = [];
        foreach ($rows as $row) $owners[(int)$row['tenant_id'].':'.(int)$row['user_id']][] = (int)$row['id'];
        $mapped = [];
        foreach ($owners as $owner => $ids) {
            [$tenant,$user] = array_map('intval',explode(':',$owner));
            foreach (self::relations($app) as $relation) {
                $query = Db::name($relation['table'])->alias('s')->where(['s.tenant_id'=>$tenant,'s.user_id'=>$user]);
                self::constrain($query,$relation);
                $query->where(function($q) use ($relation,$ids) { foreach ($ids as $id) $q->whereOrRaw(self::matchSql($relation,(string)$id)); });
                $fields = array_unique(array_merge(['id'], $relation['fields'], isset($relation['key']) ? [$relation['key']] : []));
                foreach ($query->field($fields)->order('id','desc')->select()->toArray() as $source) {
                    $children = [];
                    foreach ($relation['fields'] as $field) {
                        $value = $source[$field] ?? '';
                        if ($field === 'result_json') $children[] = (int)((json_decode((string)$value,true) ?: [])['image_task_id'] ?? 0);
                        elseif ($field === 'image_task_ids') $children = array_merge($children,(array)(json_decode((string)$value,true) ?: []));
                        elseif (ctype_digit((string)$value)) $children[] = (int)$value;
                    }
                    foreach (array_intersect($ids,array_map('intval',$children)) as $id) {
                        $mapped[$owner][$id] ??= ['app_code'=>$relation['app'],'task_id'=>(string)($relation['prefix'] ?? '').(string)($source[$relation['key'] ?? 'id'] ?? $source['id'])];
                    }
                }
            }
        }
        foreach ($rows as &$row) {
            $owner = (int)$row['tenant_id'].':'.(int)$row['user_id'];
            $row['creation_source'] = $mapped[$owner][(int)$row['id']] ?? ['app_code'=>$app === 'aigc_image' && in_array($row['style'] ?? '',array_merge(self::IMAGE_TOOLS,['hairstyle']),true) ? 'aigc_'.$row['style'] : $app,'task_id'=>(string)$row['id']];
        }
        unset($row);
        return $rows;
    }
}
