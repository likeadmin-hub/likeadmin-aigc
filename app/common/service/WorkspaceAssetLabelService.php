<?php
namespace app\common\service;

use think\facade\Db;
use InvalidArgumentException;

/** Per-account labels; stable values preserve asset associations through renames. */
final class WorkspaceAssetLabelService
{
    private const TABLE = 'user_asset_labels';
    private const DEFAULTS = [
        ['value'=>'todo','title'=>'待处理','color'=>'#e9c951'],
        ['value'=>'in_progress','title'=>'进行中','color'=>'#579ae2'],
        ['value'=>'review','title'=>'待审核','color'=>'#b58bea'],
        ['value'=>'done','title'=>'已完成','color'=>'#65ac80'],
    ];

    public static function read(int $tenant, int $user, bool $lock = false): array
    {
        if ($tenant <= 0 || $user <= 0) throw new InvalidArgumentException('请先登录');
        $scope = ['tenant_id'=>$tenant,'user_id'=>$user];
        if ($lock) Db::name(self::TABLE)->extra('IGNORE')->insert($scope + ['labels'=>json_encode(self::DEFAULTS, JSON_UNESCAPED_UNICODE),'update_time'=>time()]);
        $row = Db::name(self::TABLE)->where($scope)->lock($lock)->find();
        return $row ? json_decode($row['labels'], true, 512, JSON_THROW_ON_ERROR) : self::DEFAULTS;
    }

    public static function change(int $tenant, int $user, array $params): array
    {
        $action=$params['action']??'';
        if (array_diff(array_keys($params), ['action','value','title','color']) || !in_array($action,['create','update','delete'],true)) throw new InvalidArgumentException('标签操作无效');
        return Db::transaction(function () use ($tenant,$user,$params,$action) {
            $labels=self::read($tenant,$user,true);
            $value=$params['value']??'';
            if (!is_string($value) || ($action==='create' && $value!=='')) throw new InvalidArgumentException('标签标识无效');
            $index=array_search($value,array_column($labels,'value'),true);
            if ($action!=='create' && $index===false) throw new InvalidArgumentException('标签不存在或无权操作');
            if ($action==='delete') {
                array_splice($labels,$index,1);
                Db::name('user_asset_state')->where(['tenant_id'=>$tenant,'user_id'=>$user,'label'=>$value])->update(['label'=>'','update_time'=>time()]);
            } else {
                $title=$params['title']??''; $color=$params['color']??'';
                if (!is_string($title) || !is_string($color)) throw new InvalidArgumentException('标签格式无效');
                $title=trim($title);
                if ($title==='' || mb_strlen($title)>20 || preg_match('/[\x00-\x1f\x7f]/u',$title)) throw new InvalidArgumentException('标签名称须为1至20个字符');
                if (!preg_match('/^#[0-9a-fA-F]{6}$/D',$color)) throw new InvalidArgumentException('标签颜色无效');
                foreach ($labels as $label) if ($label['value']!==$value && mb_strtolower($label['title'])===mb_strtolower($title)) throw new InvalidArgumentException('标签名称已存在');
                if ($action==='create') {
                    if (count($labels)>=30) throw new InvalidArgumentException('最多添加30个标签');
                    $labels[]=['value'=>'l_'.bin2hex(random_bytes(8)),'title'=>$title,'color'=>$color];
                } else $labels[$index]=['value'=>$value,'title'=>$title,'color'=>$color];
            }
            Db::name(self::TABLE)->where(['tenant_id'=>$tenant,'user_id'=>$user])->update(['labels'=>json_encode($labels,JSON_UNESCAPED_UNICODE),'update_time'=>time()]);
            return ['labels'=>array_values($labels)];
        });
    }
}
