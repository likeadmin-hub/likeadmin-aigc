<?php
declare(strict_types=1);
namespace app\common\service\distribution;

use PDO;
use RuntimeException;
use think\facade\Db;

/** Shared seed for installer, tenant creation, upgrades and first list access. */
final class BuiltinPosterService
{
    public const CATALOG = [
        'nocturne'=>['name'=>'夜色灵感','title'=>'让想象，成为作品。','color'=>'#f4f7f6'],
        'atelier'=>['name'=>'灵感工坊','title'=>'灵感从这里开始。','color'=>'#182b3b'],
        'playground'=>['name'=>'创意游乐场','title'=>'把创意玩出花样。','color'=>'#182b3b'],
        'odyssey'=>['name'=>'星际叙事','title'=>'你的故事，值得成片。','color'=>'#f4f7f6'],
    ];

    public static function layout(string $key, int $assetId): array
    {
        $item=self::CATALOG[$key] ?? null;
        if (!$item) throw new RuntimeException('内置海报不存在');
        $text=['color'=>$item['color'],'align'=>'left','user_move'=>false,'user_resize'=>false,'user_edit'=>false];
        $layers=[];
        // These two backgrounds are artwork; their marketing copy remains
        // editable in the tenant editor. The other two are complete poster art.
        if (in_array($key,['nocturne','atelier'],true)) {
            $layers[]=['id'=>'eyebrow','type'=>'text','x'=>.075,'y'=>.035,'w'=>.85,'h'=>.035,'z'=>7,'text'=>'AI 一站式创作平台','font_size'=>26]+$text;
            if ($key==='nocturne') {
                $layers[]=['id'=>'title','type'=>'text','x'=>.075,'y'=>.09,'w'=>.85,'h'=>.085,'z'=>8,'text'=>'让想象，成为作品。','font_size'=>72,'bold'=>true]+$text;
                $layers[]=['id'=>'subtitle','type'=>'text','x'=>.075,'y'=>.19,'w'=>.85,'h'=>.045,'z'=>9,'text'=>'文案 · 图片 · 视频 · 音乐 · 数字人','font_size'=>28]+$text;
                $layers[]=['id'=>'benefit','type'=>'text','x'=>.075,'y'=>.245,'w'=>.85,'h'=>.035,'z'=>10,'text'=>'从一个灵感，到下一部作品。','font_size'=>24]+$text;
            } else {
                $layers[]=['id'=>'title','type'=>'text','x'=>.075,'y'=>.08,'w'=>.85,'h'=>.16,'z'=>8,'text'=>"给每个灵感\n一个漂亮的开始",'font_size'=>78,'bold'=>true]+$text;
                $layers[]=['id'=>'subtitle','type'=>'text','x'=>.075,'y'=>.25,'w'=>.85,'h'=>.055,'z'=>9,'text'=>'写文案、做图片、创视频，一站开启 AI 创作。','font_size'=>26]+$text;
            }
            $layers[]=['id'=>'cta','type'=>'text','x'=>.075,'y'=>.755,'w'=>.85,'h'=>.045,'z'=>11,'text'=>'扫码，把灵感变成作品','font_size'=>32,'bold'=>true]+$text;
        }
        $layers=array_merge($layers,[
            ['id'=>'code','type'=>'invite_code','x'=>.72,'y'=>.815,'w'=>.21,'h'=>.1575,'z'=>20,'user_move'=>false,'user_resize'=>false],
            ['id'=>'avatar','type'=>'avatar','x'=>.075,'y'=>.845,'w'=>.075,'h'=>.05625,'z'=>12,'user_move'=>false,'user_resize'=>false],
            ['id'=>'nickname','type'=>'nickname','x'=>.17,'y'=>.855,'w'=>.5,'h'=>.035,'z'=>13,'font_size'=>26,'bold'=>true]+$text,
            ['id'=>'attribution','type'=>'text','x'=>.075,'y'=>.93,'w'=>.55,'h'=>.03,'z'=>14,'text'=>'邀你一起，开启 AI 创作','font_size'=>22]+$text,
        ]);
        $layout=['schema_version'=>1,'canvas'=>['width'=>1080,'height'=>1440,'background_asset_id'=>$assetId],'layers'=>$layers];
        if (!class_exists(PosterLayout::class)) require_once __DIR__.'/PosterLayout.php';
        PosterLayout::validate($layout);
        return $layout;
    }

    public static function availability(?string $assetRoot=null): array
    {
        $root=$assetRoot ?? dirname(__DIR__,4);
        $available=[]; $missing=[];
        foreach (array_keys(self::CATALOG) as $key) {
            $file=$root.'/public/resource/distribution-posters/'.$key.'.png';
            if (is_file($file) && is_readable($file)) $available[]=$key;
            else $missing[]=$key;
        }
        return ['available_assets'=>$available,'missing_assets'=>$missing];
    }

    public static function ensure(int $tenantId, ?PDO $db=null, ?string $prefix=null, ?string $assetRoot=null): array
    {
        if ($tenantId<=0) throw new RuntimeException('租户不存在');
        if (!$db) { $connection=Db::connect(); $prefix=$prefix ?? (string)$connection->getConfig('prefix'); $db=$connection->connect(); }
        $prefix=$prefix ?? 'la_'; self::prefix($prefix);
        $assets=self::availability($assetRoot);
        $result=['inserted'=>0,'existing'=>0,'missing_assets'=>$assets['missing_assets']];
        $templates=$prefix.'distribution_poster_template';
        $existing=$db->prepare("SELECT builtin_key FROM `{$templates}` WHERE tenant_id=? AND builtin_key IN ('nocturne','atelier','playground','odyssey')");
        $existing->execute([$tenantId]);
        // Includes soft-deleted rows; seeding must never resurrect a removed default.
        $result['existing']=count($existing->fetchAll(PDO::FETCH_COLUMN));
        if ($result['existing']===count(self::CATALOG) || !$assets['available_assets']) return $result;
        $ownsTransaction=!$db->inTransaction();
        if ($ownsTransaction) $db->beginTransaction();
        try {
            $lock=$db->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':'';
            $query=$db->prepare("SELECT id,tactics,sn FROM `{$prefix}tenant` WHERE id=?".$lock);
            $query->execute([$tenantId]); $tenant=$query->fetch(PDO::FETCH_ASSOC);
            if (!$tenant) throw new RuntimeException('租户不存在');
            $suffix='';
            if ((int)$tenant['tactics']===1) {
                $sn=(string)$tenant['sn'];
                if (!preg_match('/^[a-zA-Z0-9_-]+$/D',$sn)) throw new RuntimeException('无效租户分表标识');
                $suffix='_'.$sn;
            }
            $fileTable=$prefix.'tenant_file'.$suffix;
            foreach (self::CATALOG as $key=>$item) {
                $query=$db->prepare("SELECT id FROM `{$templates}` WHERE tenant_id=? AND builtin_key=?");
                $query->execute([$tenantId,$key]);
                if ($query->fetchColumn()!==false) continue;
                // Deployment may deliver the assets in stages. Missing keys remain
                // unclaimed so the next list/upgrade can fill them when available.
                if (!in_array($key,$assets['available_assets'],true)) continue;
                $now=time();
                $assetId=self::insert($db,$fileTable,['tenant_id'=>$tenantId,'cid'=>0,'source_id'=>0,'source'=>0,'type'=>10,'name'=>$item['name'].'.png','uri'=>'resource/distribution-posters/'.$key.'.png','storage_scope'=>'platform','storage_engine'=>'local','storage_domain'=>'','create_time'=>$now,'update_time'=>$now,'delete_time'=>null]);
                $json=json_encode(self::layout($key,$assetId),JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
                $id=self::insert($db,$templates,['tenant_id'=>$tenantId,'builtin_key'=>$key,'name'=>$item['name'],'sort'=>100-(int)array_search($key,array_keys(self::CATALOG),true),'status'=>1,'revision'=>1,'published_version'=>0,'draft_json'=>$json,'create_time'=>$now,'update_time'=>$now,'delete_time'=>0]);
                $version=self::insert($db,$prefix.'distribution_poster_version',['tenant_id'=>$tenantId,'template_id'=>$id,'revision'=>1,'name'=>$item['name'],'layout_json'=>$json,'admin_id'=>0,'create_time'=>$now]);
                $query=$db->prepare("UPDATE `{$templates}` SET published_version=? WHERE id=? AND tenant_id=?");
                $query->execute([$version,$id,$tenantId]);
                $result['inserted']++;
            }
            if ($ownsTransaction) $db->commit();
            return $result;
        } catch (\Throwable $e) {
            if ($ownsTransaction && $db->inTransaction()) $db->rollBack();
            throw $e;
        }
    }

    public static function seedAll(PDO $db, string $prefix, ?string $assetRoot=null): array
    {
        self::prefix($prefix);
        $ids=$db->query("SELECT id FROM `{$prefix}tenant` WHERE id>0")->fetchAll(PDO::FETCH_COLUMN);
        $result=['inserted'=>0,'tenants'=>count($ids)]+self::availability($assetRoot);
        foreach ($ids as $id) $result['inserted']+=self::ensure((int)$id,$db,$prefix,$assetRoot)['inserted'];
        return $result;
    }

    /** Explicit upgrade, never invoked to change schema during a normal request. */
    public static function migrate(string $prefix, $connection=null): array
    {
        self::prefix($prefix);
        $db=$connection instanceof PDO ? $connection : ($connection ?: Db::connect())->connect();
        $table=$prefix.'distribution_poster_template';
        if (!$db->query("SHOW COLUMNS FROM `{$table}` LIKE 'builtin_key'")->fetch(PDO::FETCH_ASSOC)) $db->exec("ALTER TABLE `{$table}` ADD COLUMN builtin_key varchar(32) DEFAULT NULL");
        $indexes=$db->query("SHOW INDEX FROM `{$table}` WHERE Key_name='uk_tenant_builtin'")->fetchAll(PDO::FETCH_ASSOC);
        if (!$indexes) $db->exec("ALTER TABLE `{$table}` ADD UNIQUE INDEX uk_tenant_builtin (tenant_id,builtin_key)");
        else {
            usort($indexes,fn($a,$b)=>$a['Seq_in_index']<=>$b['Seq_in_index']);
            if (array_column($indexes,'Column_name')!==['tenant_id','builtin_key'] || (int)$indexes[0]['Non_unique']!==0) throw new RuntimeException('内置海报索引定义冲突');
        }
        return self::seedAll($db,$prefix);
    }

    private static function prefix(string $prefix): void
    {
        if (!preg_match('/^[a-zA-Z0-9_]+$/D',$prefix)) throw new RuntimeException('无效数据库或租户标识');
    }
    private static function insert(PDO $db, string $table, array $data): int
    {
        $columns=implode(',',array_map(fn($key)=>'`'.$key.'`',array_keys($data)));
        $query=$db->prepare("INSERT INTO `{$table}` ({$columns}) VALUES (".implode(',',array_fill(0,count($data),'?')).')');
        $query->execute(array_values($data));
        return (int)$db->lastInsertId();
    }
}
