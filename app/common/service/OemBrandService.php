<?php
namespace app\common\service;

/** Also loaded directly by the pre-framework installer. Contains no credentials. */
class OemBrandService
{
    public const SCHEMA_VERSION = 1;
    public static function read(?string $root = null): array
    {
        $root = $root ?? dirname(__DIR__, 3);
        $file = $root . '/oem/brand.json';
        if (!is_file($file)) return [];
        $data = json_decode((string)file_get_contents($file), true);
        if (!is_array($data)) throw new \InvalidArgumentException('OEM品牌文件无效');
        return self::validate($data);
    }
    public static function validate(array $b): array
    {
        if (($b['schema_version'] ?? 0) !== 1) throw new \InvalidArgumentException('不支持的OEM品牌版本');
        if (!is_string($b['name'] ?? null) || trim($b['name']) === '' || mb_strlen($b['name']) > 40) throw new \InvalidArgumentException('品牌名称无效');
        if (!preg_match('/^[a-z0-9][a-z0-9-]{2,39}$/D', $b['slug'] ?? '')) throw new \InvalidArgumentException('品牌英文标识无效');
        foreach (['logo','logo_dark','favicon','login_background'] as $key) {
            $path = $b[$key] ?? '';
            if (!is_string($path) || ($path !== '' && (!preg_match('~^oem-assets/[a-zA-Z0-9_-]+/[a-zA-Z0-9_.-]+\.(png|jpg|jpeg|webp)$~D', $path) || strpos($path,'..') !== false))) throw new \InvalidArgumentException('品牌资源路径无效');
        }
        if (empty($b['logo'])) throw new \InvalidArgumentException('缺少品牌Logo');
        return $b;
    }
    public static function values(array $b): array
    {
        if (!$b) return [];
        $name=$b['name'];$logo=$b['logo'];$ico=$b['favicon'] ?? $logo;$bg=$b['login_background'] ?? 'resource/image/common/oem-login-background.png';
        $copy=[];
        foreach (['copyright_text','icp','police_icp'] as $key) if (!empty($b[$key])) $copy[]=['key'=>$b[$key],'value'=>''];
        return [
            'platform'=>['name'=>$name,'web_logo_light'=>$logo,'web_logo_dark'=>$b['logo_dark'] ?? $logo,'web_favicon'=>$ico,'login_image'=>$bg],
            'tenant'=>['name'=>$name,'web_logo'=>$logo,'web_favicon'=>$ico,'login_image'=>$bg,'admin_avatar'=>$logo],
            'website'=>['name'=>$name,'shop_name'=>$name,'shop_logo'=>$logo,'pc_logo'=>$logo,'pc_ico'=>$ico,'h5_favicon'=>$ico,'pc_title'=>$b['seo_title'] ?? $name,'pc_keywords'=>$b['seo_keywords'] ?? $name,'pc_desc'=>$b['description'] ?? '', 'pc_login_title'=>$name],
            'copyright'=>['config'=>$copy],
        ];
    }
    public static function defaultValue(string $type,string $name,?string $root=null)
    {
        return self::values(self::read($root))[$type][$name] ?? null;
    }
    /** Called only after NEW install/tenant SQL bootstrap. overwrite=false is repair-safe. */
    public static function initialize(\PDO $db,string $prefix,?int $tenantId=null,?string $root=null,bool $overwrite=true): void
    {
        if (!preg_match('/^[A-Za-z0-9_]+$/D',$prefix)) throw new \InvalidArgumentException('无效表前缀');
        $b=self::read($root);if (!$b) return;
        $table=$prefix.($tenantId===null?'config':'tenant_config');
        foreach (self::values($b) as $type=>$entries) {
            foreach ($entries as $name=>$value) {
                $condition='`type`=? AND `name`=?'.($tenantId===null?'':' AND tenant_id=?');
                $args=[$type,$name];if($tenantId!==null)$args[]=$tenantId;
                $q=$db->prepare("SELECT id FROM `$table` WHERE $condition");$q->execute($args);$id=$q->fetchColumn();
                $value=is_array($value)?json_encode($value,JSON_UNESCAPED_UNICODE):$value;
                if ($id!==false) {
                    if($overwrite){$q=$db->prepare("UPDATE `$table` SET value=? WHERE $condition");$q->execute(array_merge([$value],$args));}
                } else {
                    $columns='`type`,`name`,`value`'.($tenantId===null?'':',tenant_id');
                    $args=[$type,$name,$value];if($tenantId!==null)$args[]=$tenantId;
                    $q=$db->prepare("INSERT INTO `$table` ($columns) VALUES (".implode(',',array_fill(0,count($args),'?')).')');$q->execute($args);
                }
            }
        }
    }
}
