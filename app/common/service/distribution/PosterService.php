<?php
declare(strict_types=1);
namespace app\common\service\distribution;

use app\common\model\file\TenantFile;
use app\common\model\user\User;
use app\common\service\FileService;
use app\common\service\SubmitLockService;
use app\common\service\wechat\WeChatMnpService;
use think\facade\Db;
use RuntimeException;

final class PosterService
{
    private static function query(int $tenantId) {
        if ($tenantId <= 0) throw new RuntimeException('租户不存在');
        return Db::name('distribution_poster_template')->where(['tenant_id'=>$tenantId, 'delete_time'=>0]);
    }
    private static function row(int $tenantId, int $id, bool $lock=false): array {
        $row = self::query($tenantId)->where('id',$id)->lock($lock)->find();
        if (!$row) throw new RuntimeException('海报不存在或已删除');
        return $row;
    }
    private static function revision(array $row, array $input): void {
        if ((int)($input['revision']??0) !== (int)$row['revision']) throw new RuntimeException('海报已被其他管理员修改，请重新打开后编辑');
    }
    private static function asset(int $tenantId, int $id): string {
        $file = TenantFile::where(['tenant_id'=>$tenantId,'id'=>$id,'type'=>10,'source'=>0])->findOrEmpty();
        if ($file->isEmpty()) throw new RuntimeException('背景图片不存在，请从当前租户素材库重新选择');
        return FileService::getFileUrlByStorage((string)$file['uri'], (string)($file['storage_scope']??'tenant'), (string)($file['storage_engine']??''), (string)($file['storage_domain']??''));
    }
    private static function decode(array $row, bool $published=false): array {
        $layout = json_decode((string)$row[$published?'layout_json':'draft_json'],true) ?: [];
        $row['layout']=$layout;
        $row['background_url']=self::asset((int)$row['tenant_id'],(int)($layout['canvas']['background_asset_id']??0));
        unset($row['draft_json'],$row['layout_json']);
        return $row;
    }
    public static function lists(int $tenantId, array $input, bool $user=false): array {
        if ($user) DistributionService::assertEnabled($tenantId);
        BuiltinPosterService::ensure($tenantId);
        $q=self::query($tenantId);
        if ($user) $q->where('status',1)->where('published_version','>',0);
        if (!$user && trim((string)($input['keyword']??''))!=='') $q->whereLike('name','%'.trim($input['keyword']).'%');
        $count=(clone $q)->count();
        $rows=$q->order('sort desc,id desc')->page(max(1,(int)($input['page_no']??1)),max(1,min(50,(int)($input['page_size']??20))))->select()->toArray();
        $result=[];
        foreach ($rows as $row) {
            try { $result[]=$user ? self::published($tenantId,(int)$row['id']) : self::decode($row); }
            catch (RuntimeException $e) { if (!$user) { unset($row['draft_json']); $row['background_url']=''; $row['asset_error']=$e->getMessage(); $result[]=$row; } }
        }
        return ['lists'=>$result,'count'=>$count];
    }
    public static function detail(int $tenantId,int $id): array {
        $row=self::row($tenantId,$id);
        try { return self::decode($row); } catch (RuntimeException $e) { $row['layout']=json_decode($row['draft_json'],true)?:[]; unset($row['draft_json']); $row['background_url']=''; $row['asset_error']=$e->getMessage(); return $row; }
    }
    public static function save(int $tenantId, array $input): array {
        $name=trim((string)($input['name']??''));
        if ($name==='' || mb_strlen($name)>80) throw new RuntimeException('请输入 1～80 字的海报名称');
        $layout=$input['layout']??[];
        if (!is_array($layout)) throw new RuntimeException('海报格式无效');
        PosterLayout::validate($layout);
        self::asset($tenantId,(int)$layout['canvas']['background_asset_id']);
        return Db::transaction(function() use($tenantId,$input,$name,$layout) {
            $id=(int)($input['id']??0);
            $data=['name'=>$name,'draft_json'=>json_encode($layout,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),'update_time'=>time()];
            if ($id) { $row=self::row($tenantId,$id,true); self::revision($row,$input); $data['revision']=(int)$row['revision']+1; self::query($tenantId)->where('id',$id)->update($data); }
            else { $data+=['tenant_id'=>$tenantId,'revision'=>1,'status'=>0,'published_version'=>0,'create_time'=>time()]; $id=(int)Db::name('distribution_poster_template')->insertGetId($data); }
            return self::detail($tenantId,$id);
        });
    }
    public static function publish(int $tenantId,int $adminId,array $input): array {
        return Db::transaction(function() use($tenantId,$adminId,$input) {
            $row=self::row($tenantId,(int)($input['id']??0),true); self::revision($row,$input);
            $layout=json_decode($row['draft_json'],true); PosterLayout::validate($layout); self::asset($tenantId,(int)$layout['canvas']['background_asset_id']);
            $version=Db::name('distribution_poster_version')->where(['tenant_id'=>$tenantId,'template_id'=>$row['id'],'revision'=>$row['revision']])->value('id');
            if (!$version) $version=Db::name('distribution_poster_version')->insertGetId(['tenant_id'=>$tenantId,'template_id'=>$row['id'],'revision'=>$row['revision'],'name'=>$row['name'],'layout_json'=>$row['draft_json'],'admin_id'=>$adminId,'create_time'=>time()]);
            self::query($tenantId)->where('id',$row['id'])->update(['published_version'=>$version,'status'=>1,'update_time'=>time()]);
            return self::detail($tenantId,(int)$row['id']);
        });
    }
    public static function mutate(int $tenantId,string $action,array $input): array {
        return Db::transaction(function() use($tenantId,$action,$input) {
            $row=self::row($tenantId,(int)($input['id']??0),true); self::revision($row,$input);
            if ($action==='copy') return self::save($tenantId,['name'=>mb_substr($row['name'],0,75).' 副本','layout'=>json_decode($row['draft_json'],true)]);
            $data=['revision'=>(int)$row['revision']+1,'update_time'=>time()];
            if ($action==='delete') $data+=['delete_time'=>time(),'status'=>0];
            elseif ($action==='sort') $data['sort']=max(-9999,min(9999,(int)($input['sort']??0)));
            elseif ($action==='setStatus') { $status=(int)($input['status']??0); if (!in_array($status,[0,1],true)) throw new RuntimeException('状态无效'); if ($status && !$row['published_version']) throw new RuntimeException('请先发布海报'); $data['status']=$status; }
            else throw new RuntimeException('操作无效');
            self::query($tenantId)->where('id',$row['id'])->update($data);
            return ['id'=>$row['id'],'revision'=>$data['revision']];
        });
    }
    public static function published(int $tenantId,int $id): array {
        DistributionService::assertEnabled($tenantId);
        $row=self::row($tenantId,$id);
        if (!(int)$row['status'] || !(int)$row['published_version']) throw new RuntimeException('海报已下架，请选择其他海报');
        $version=Db::name('distribution_poster_version')->where(['tenant_id'=>$tenantId,'template_id'=>$id,'id'=>$row['published_version']])->find();
        if (!$version) throw new RuntimeException('海报版本不存在');
        $result=self::decode($version,true);
        return ['id'=>$id,'name'=>$result['name'],'published_version'=>(int)$version['id'],'layout'=>$result['layout'],'background_url'=>$result['background_url']];
    }
    public static function profile(int $tenantId,int $userId,bool $readOnly=false): array {
        DistributionService::assertEnabled($tenantId);
        $user=User::where(['tenant_id'=>$tenantId,'id'=>$userId])->findOrEmpty();
        if ($user->isEmpty() || !empty($user['is_disable'])) throw new RuntimeException('请重新登录');
        $relation=$readOnly ? Db::name('distribution_relation')->where(['tenant_id'=>$tenantId,'user_id'=>$userId])->find() : DistributionService::ensurePromoter($tenantId,$userId);
        if (!$relation || empty($relation['invite_code'])) throw new RuntimeException('推广资格未建立，请重新打开推广中心');
        if (!empty($relation['is_frozen'])) throw new RuntimeException('推广资格已停用，请联系管理员');
        return ['nickname'=>(string)$user['nickname'],'avatar'=>(string)$user['avatar'],'invite_code'=>$relation['invite_code']];
    }
    public static function validateExport(int $tenantId,int $userId,array $input): array {
        $profile=self::profile($tenantId,$userId);
        $poster=self::published($tenantId,(int)($input['id']??0));
        if ((int)($input['published_version']??0)!==$poster['published_version']) throw new RuntimeException('海报已更新，请重新选择模板');
        PosterLayout::validateUserEdit($poster['layout'],(array)($input['layout']??[]));
        return ['profile'=>$profile,'background_url'=>$poster['background_url']];
    }
    /** Raster delivery is authorized by the live published template and personal receipts only. */
    public static function assets(int $tenantId, int $userId, array $input): array {
        if (array_diff(array_keys($input), ['id','published_version'])
            || !is_scalar($input['id'] ?? null) || !is_scalar($input['published_version'] ?? null)
            || !preg_match('/^[1-9][0-9]{0,18}$/D', (string)($input['id'] ?? ''))
            || !preg_match('/^[1-9][0-9]{0,18}$/D', (string)($input['published_version'] ?? ''))) {
            throw new RuntimeException('海报参数无效，请重新选择模板');
        }
        $profile = self::profile($tenantId, $userId, true);
        $poster = self::published($tenantId, (int)$input['id']);
        if ((int)$input['published_version'] !== $poster['published_version']) throw new RuntimeException('海报已更新，请重新选择模板');
        $file = TenantFile::where(['tenant_id'=>$tenantId, 'id'=>(int)$poster['layout']['canvas']['background_asset_id'], 'type'=>10, 'source'=>0])->findOrEmpty();
        if ($file->isEmpty()) throw new RuntimeException('背景图片不存在，请重新选择模板');
        $started = microtime(true);
        $background = PosterRasterService::read($file->toArray(), $tenantId, $started + 4.0);
        $avatar = null;
        try {
            // User avatar paths alone are not receipts. Require the current user's own upload.
            $user = User::where(['tenant_id'=>$tenantId, 'id'=>$userId])->findOrEmpty();
            $avatarUri = (string)$user->getData('avatar');
            if ($avatarUri !== '') {
                $receipt = TenantFile::where(['tenant_id'=>$tenantId, 'source'=>1, 'source_id'=>$userId, 'type'=>10, 'uri'=>$avatarUri])->findOrEmpty();
                if (!$receipt->isEmpty()) $avatar = PosterRasterService::read($receipt->toArray(), $tenantId, min($started + 6.0, microtime(true) + 2.0));
            }
        } catch (\Throwable $error) { /* Missing/unreadable personal avatars use the nickname fallback. */ }
        return ['background'=>$background, 'avatar'=>$avatar, 'profile'=>$profile, 'published_version'=>$poster['published_version']];
    }
    public static function code(int $tenantId,int $userId,array $input): array {
        self::published($tenantId,(int)($input['id']??0));
        $profile=self::profile($tenantId,$userId);
        $channel=$input['channel']??'mini';
        if (!in_array($channel,['mini','h5'],true)) throw new RuntimeException('推广码渠道无效');
        if ($channel==='h5') return PosterH5CodeService::code($tenantId,$userId,$profile);
        $scene='t='.$tenantId.'&i='.$profile['invite_code'];
        if (strlen($scene)>32) throw new RuntimeException('推广码参数超长，请联系管理员');
        $wechat=new WeChatMnpService($tenantId);
        $identity=$wechat->posterIdentity();
        $key=hash('sha256',$tenantId.'|'.$userId.'|mini|'.$identity.'|'.$scene.'|release|v1');
        $lock=SubmitLockService::acquire('distribution.poster.code.'.$key,$tenantId,$userId);
        try {
            $dir=runtime_path().'distribution-posters/';
            if (!is_dir($dir) && !mkdir($dir,0700,true) && !is_dir($dir)) throw new RuntimeException('推广码缓存不可用');
            $file=$dir.$key.'.png';
            if (!is_file($file)) {
                $bytes=$wechat->posterCode($scene);
                if (file_put_contents($file.'.tmp',$bytes,LOCK_EX)===false || !rename($file.'.tmp',$file)) throw new RuntimeException('推广码保存失败，请重试');
            }
            $bytes=file_get_contents($file);
            $image=@getimagesizefromstring($bytes);
            if (!$image) { @unlink($file); throw new RuntimeException('推广码缓存损坏，请重试'); }
            return ['base64'=>base64_encode($bytes),'mime'=>$image['mime'],'profile'=>$profile];
        } finally { SubmitLockService::release($lock); }
    }
}
