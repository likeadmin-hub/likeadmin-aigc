<?php

namespace app\common\service\decorate\agent;

use app\common\enum\FileEnum;
use app\common\model\ai\AiAppTask;
use app\common\model\ai\AiConsumptionLog;
use app\common\model\file\TenantFile;
use app\common\service\ai\AiTaskResultAssetService;
use app\common\service\decorate\DecorateDataSourceService;
use app\common\service\FileService;
use app\common\service\open_platform\OpenSafeHttp;
use app\common\service\power\MarketImageModelRuntimeService;
use app\common\service\storage\StorageSignedUrlService;
use Exception;
use think\facade\Db;

/** Tenant-admin authoring adapter. No request field can opt ordinary users into this payer. */
final class DecorationAgentMediaService
{
    public const BUSINESS_TABLE = 'decorate_agent_image_slot';
    private const MAX_BYTES = 10485760;
    private const MAX_ATTACHMENTS = 5;

    /** URLs and image metadata are resolved from an owned file, never copied from client input. */
    public static function validateAttachments(int $tenantId, array $attachments): array
    {
        self::assertTenant($tenantId);
        if (count($attachments) > self::MAX_ATTACHMENTS) throw new Exception('最多上传5张参考图片');
        if($attachments===[])return [];
        $evidence=self::evidenceFileIds($tenantId);
        $result = [];
        foreach ($attachments as $attachment) {
            if (!is_array($attachment) || (int)($attachment['file_id'] ?? 0) <= 0) throw new Exception('附件必须提供文件ID');
            $id = (int)$attachment['file_id'];
            if (isset($result[$id])) throw new Exception('附件不能重复');
            $file = TenantFile::where(['tenant_id' => $tenantId, 'id' => $id, 'source' => FileEnum::SOURCE_ADMIN, 'type' => FileEnum::IMAGE_TYPE])->findOrEmpty();
            $row = $file->isEmpty() ? [] : $file->toArray();
            self::assertAttachmentRow($tenantId, $row);
            $url = self::fileUrl($row);
            $bytes=self::readImage($row,$url);
            [$width, $height] = self::inspectImage($bytes);
            $uses = $attachment['uses'] ?? ['asset'];
            if (is_string($uses)) $uses = [$uses];
            if (!is_array($uses) || count(array_filter($uses, 'is_string')) !== count($uses) || array_diff($uses, ['asset', 'layout', 'style', 'generation']) !== []) throw new Exception('附件用途无效');
            if(in_array($id,$evidence,true)&&array_intersect($uses,['asset','generation']))throw new Exception('整页验收截图只能作为对照证据，不能作为素材');
            $result[$id] = ['file_id' => $id, 'url' => $url, 'uses' => array_values(array_unique($uses)), 'width' => $width, 'height' => $height,'sha256'=>hash('sha256',$bytes)];
        }
        return array_values($result);
    }

    /** Ephemeral transport only: never persist these data URIs in agent context or events. */
    public static function visionReferences(int $tenantId, array $attachments): array
    {
        $validated = self::validateAttachments($tenantId, $attachments);
        $references = [];
        foreach ($validated as $attachment) {
            if (array_intersect($attachment['uses'], ['layout', 'style']) === []) continue;
            // Reload ownership before reading bytes; a removed file must not become a vision input.
            $file = TenantFile::where(['tenant_id' => $tenantId, 'id' => $attachment['file_id'], 'source' => FileEnum::SOURCE_ADMIN, 'type' => FileEnum::IMAGE_TYPE])->findOrEmpty();
            $row = $file->isEmpty() ? [] : $file->toArray();
            self::assertAttachmentRow($tenantId, $row);
            $bytes = self::readImage($row, self::fileUrl($row));
            self::inspectImage($bytes);
            $info = getimagesizefromstring($bytes);
            $references[] = 'data:' . $info['mime'] . ';base64,' . base64_encode($bytes);
        }
        return $references;
    }

    public static function options(int $tenantId): array
    {
        self::assertTenant($tenantId);
        return MarketImageModelRuntimeService::options($tenantId);
    }

    /** Reviewed first-party decoration images; never label these as user/customer cases. */
    public static function builtinMaterials(): array
    {
        $result=[];
        foreach([
            ['banner001','玻璃城市主视觉',['玻璃','城市','建筑','创意','工作室','主视觉','灰色']],
            ['banner002','彩色玻璃立方',['玻璃','立方','彩色','创意','工作室','主视觉','抽象']],
            ['banner003','浅蓝数据概念',['数据','科技','浅蓝','概念','背景','主视觉']],
        ] as [$file,$title,$tags]){
            $uri='resource/image/tenantapi/default/'.$file.'.png';$info=@getimagesize(public_path().$uri);
            if(!$info)continue;
            $result[]=['id'=>'builtin:'.$file,'file_id'=>0,'uri'=>$uri,'url'=>'/'.$uri,'source'=>'content','source_key'=>'builtin_decoration','title'=>$title,'type'=>'image','role'=>'decorative','tags'=>$tags,'width'=>$info[0],'height'=>$info[1],'dimensions_known'=>true,'suggested_uses'=>['banner','背景主视觉'],'provenance'=>'随系统提供的装饰图片，不是用户生成作品或客户案例'];
        }
        return $result;
    }

    /** Search tenant media plus existing published decoration sources, respecting enabled source flags. */
    public static function search(int $tenantId, string $query, array $allowedSources, array $attachments, int $limit = 12): array
    {
        self::assertTenant($tenantId);
        $limit = max(1, min(50, $limit));
        $needle = mb_strtolower(trim($query));
        $candidates = [];
        $evidence=self::evidenceFileIds($tenantId);
        if(in_array('content',$allowedSources,true))foreach(self::builtinMaterials() as $material)if($needle===''||self::matches($material['title'],$material['tags'],$needle))$candidates[]=$material;
        if (in_array('upload', $allowedSources, true)) {
            foreach (self::validateAttachments($tenantId, $attachments) as $attachment) {
                if (!in_array('asset', $attachment['uses'], true)) continue;
                $candidates[] = $attachment + ['id' => 'file:' . $attachment['file_id'], 'source' => 'upload', 'title' => '上传参考图','type'=>'image','role'=>'uploaded_asset','tags'=>[],'dimensions_known'=>true];
            }
        }
        if (in_array('tenant', $allowedSources, true)) {
            foreach (self::generatedMaterials($tenantId, $needle) as $material) $candidates[] = $material;
            $files = TenantFile::where(['tenant_id' => $tenantId, 'source' => FileEnum::SOURCE_ADMIN, 'type' => FileEnum::IMAGE_TYPE]);
            if($evidence)$files->whereNotIn('id',$evidence);
            // Bounded filename discovery supports synonyms without pretending to inspect pixels.
            foreach ($files->order('id', 'desc')->limit(50)->select()->toArray() as $file) {
                if (!self::imageExtension((string)$file['uri'])||($needle!==''&&!self::matches((string)$file['name'],[],$needle))) continue;
                $candidates[] = ['id' => 'file:' . $file['id'], 'file_id' => (int)$file['id'], 'url' => self::fileUrl($file), 'source' => 'tenant', 'title' => (string)$file['name'], 'width' => 0, 'height' => 0,'uri'=>(string)$file['uri'],'type'=>'image','role'=>'tenant_asset','tags'=>[],'dimensions_known'=>false,'match_basis'=>'filename_only','semantic_confidence'=>'unknown'];
            }
        }
        $refs = [];
        foreach ($allowedSources as $source) {
            if ($source === 'content') foreach (['article', 'image_cases', 'ai_tools', 'app_entries'] as $key) $refs[$key] = ['key' => $key];
            if (is_array($source)) {
                $key = (string)($source['key'] ?? '');
                if (in_array($key, ['article', 'image_cases', 'ai_tools', 'app_entries'], true)) $refs[$key] = $source;
            }
        }
        foreach ($refs as $key => $ref) {
            $params = (array)($ref['params'] ?? []);
            $params['limit'] = 50;
            if($key==='app_entries')$params['terminal']='uniapp';
            $rows = DecorateDataSourceService::resolve($key, $params, $tenantId, ['tenant_id' => $tenantId, 'terminal' => 'uniapp']);
            $rows = isset($rows['lists']) ? $rows['lists'] : $rows;
            foreach ($rows as $row) {
                if (!is_array($row)) continue;
                $title = (string)($row['title'] ?? $row['name'] ?? '');
                if ($needle !== '' && !self::matches($title.' '.(string)($row['description']??$row['desc']??''),[$key,(string)($row['app_code']??'')],$needle)) continue;
                foreach (['image', 'cover_url', 'cover', 'image_url', 'icon'] as $field) {
                    $uri = trim((string)($row[$field] ?? ''));
                    if ($uri === '' || !self::imageExtension($uri)) continue;
                    $candidates[] = ['id' => $key . ':' . (string)($row['id'] ?? $row['app_code'] ?? '') . ':' . $field, 'file_id' => 0, 'url' => self::contentImageUrl($uri), 'source' => 'content', 'source_key' => $key, 'source_id' => $row['id'] ?? $row['app_code'] ?? '', 'title' => $title, 'width' => 0, 'height' => 0,'uri'=>$uri,'type'=>'image','role'=>match($key){'image_cases'=>'user_case','app_entries'=>'app_icon','ai_tools'=>'app_cover',default=>'article_cover'},'tags'=>[$key],'dimensions_known'=>false];
                    break;
                }
            }
        }
        // Select across allowed sources rather than letting newest uploads consume the entire limit.
        $priority=['uploaded_asset'=>100,'decorative'=>90,'generated_decorative'=>85,'user_case'=>80,'app_icon'=>70,'app_cover'=>60,'tenant_asset'=>50,'article_cover'=>30];
        usort($candidates,fn($a,$b)=>($priority[$b['role']??'']??0)<=>($priority[$a['role']??'']??0));
        $seen = []; $result = [];
        foreach ($candidates as $candidate) {
            if (isset($seen[$candidate['url']])) continue;
            $uri=(string)($candidate['uri']??'');
            if($uri!==''&&!preg_match('#^https?://#i',$uri)){
                $root=realpath(public_path());$path=realpath(public_path().ltrim($uri,'/'));
                if($root&&$path&&str_starts_with($path,$root.DIRECTORY_SEPARATOR)&&is_file($path)&&filesize($path)<=self::MAX_BYTES){$info=@getimagesize($path);if($info){$candidate['width']=$info[0];$candidate['height']=$info[1];$candidate['dimensions_known']=true;}}
            }
            $seen[$candidate['url']] = true; $result[] = $candidate;
            if (count($result) >= $limit) break;
        }
        return $result;
    }

    /** Evidence is excluded tenant-wide, including later sessions, without changing file ownership. */
    public static function evidenceFileIds(int $tenant): array
    {
        $runs=Db::name('decorate_agent_run')->where('tenant_id',$tenant)
            ->where(function($query){$query->whereLike('context_json','%"screenshot_file_id"%')->whereOr('input_json','like','%"screenshot_file_id"%');})
            ->field('context_json,input_json')->select()->toArray();
        $ids=[];foreach($runs as $run)foreach(['context_json','input_json'] as $key){$id=(int)(DecorationAgentService::decode($run[$key])['visual_review']['screenshot_file_id']??0);if($id>0)$ids[$id]=$id;}
        return array_values($ids);
    }

    /** Reuse only settled system authoring assets, never ordinary users' private images. */
    private static function generatedMaterials(int $tenantId, string $query): array
    {
        $result = [];
        $slots = Db::name('decorate_agent_image_slot')->where('tenant_id', $tenantId)
            ->whereIn('status', ['success', 'regenerated'])->order('id', 'desc')->limit(50)->select()->toArray();
        foreach ($slots as $slot) {
            $request = DecorationAgentService::decode($slot['request_json']);
            if(($request['purpose']??'')==='design')continue;
            $prompt = (string)($request['prompt'] ?? '');
            if ($query !== '' && !self::matches($prompt, [], $query)) continue;
            $stored = DecorationAgentService::decode($slot['result_json']);
            foreach ((array)($stored['images'] ?? []) as $asset) {
                if (!is_array($asset) || empty($asset['image_uri'])) continue;
                $stable = '/' . ltrim((string)$asset['image_uri'], '/');
                // The shared resolver verifies receipt, task, ownership and immutable storage identity.
                $widget = DecorationAgentCatalog::create('banner', 'material:' . $slot['id']);
                $delivery = (string)($asset['url'] ?? $stable);
                $widget['content']['data'] = [['image' => $delivery]];
                $proof = DecorationAgentAssetReferences::attachMaterials(['data' => [$widget], 'meta' => []],
                    [array_replace($asset, ['slot_id' => (int)$slot['id'], 'file_id' => 0, 'url' => $delivery])], $tenantId);
                if (($proof['data'][0]['_decoration_asset_refs']['content.data.0.image']['id'] ?? 0) !== (int)$slot['id']) continue;
                $urls = DecorationAgentAssetReferences::resolveUrls($proof, $tenantId);
                if (!isset($urls[$stable])) continue;
                $width = max(0, (int)($asset['width'] ?? 0));
                $height = max(0, (int)($asset['height'] ?? 0));
                $result[] = array_replace($asset, [
                    'id' => 'slot:' . $slot['id'] . ':' . hash('sha256', $stable),
                    'slot_id' => (int)$slot['id'], 'file_id' => 0, 'uri' => (string)$asset['image_uri'],
                    'source' => 'tenant', 'source_key' => 'decoration_generated', 'title' => $prompt,
                    'type' => 'image', 'role' => 'generated_decorative', 'tags' => ['装修生成', $prompt],
                    'dimensions_known' => $width > 0 && $height > 0,
                    'match_basis' => 'generation_prompt', 'provenance' => '租户后台装修生成的装饰素材，不是用户作品或客户案例',
                ]);
                $result[array_key_last($result)]['url'] = $urls[$stable];
            }
        }
        return $result;
    }

    public static function quote(int $tenantId, array $selection, int $quantity = 1, array $request = [],int $slotId=0): array
    {
        self::assertTenant($tenantId);
        self::referenceRequest($tenantId, $request,$slotId);
        $quote = MarketImageModelRuntimeService::quote($tenantId, $selection, $quantity, [], true);
        $quote['actual_tenant_cost_points'] = (float)$quote['tenant_cost_points'];
        $quote['cost_known'] = (string)$quote['settlement_mode'] !== 'actual_usage';
        return $quote;
    }

    /** Persist this receipt in the slot before calling submit; never hide a second submission. */
    public static function reserve(int $tenantId, int $adminId, int $slotId, array $selection, array $request, int $quantity = 1): array
    {
        self::assertTenant($tenantId);
        if ($adminId <= 0 || $slotId <= 0) throw new Exception('装修图片任务身份无效');
        $request = self::referenceRequest($tenantId, $request,$slotId);
        $receipt = MarketImageModelRuntimeService::reserve($tenantId, 0, 'decoration_agent_image', (string)$slotId, $selection, $request, $quantity, 'system_default', self::BUSINESS_TABLE, [], true);
        $receipt['spent_points'] = (float)self::consumption($tenantId, (int)$receipt['consumption_id'])['reserved_tenant_cost'];
        return $receipt;
    }

    public static function submit(int $consumptionId, array $request): array
    {
        $consumption = self::consumption(0, $consumptionId);
        $slotId=(int)AiAppTask::where('id',(int)$consumption['app_task_id'])->value('business_id');
        $request = self::referenceRequest((int)$consumption['tenant_id'], $request,$slotId);
        $snapshot = (array)$consumption['price_snapshot'];
        $request['quantity'] = max(1, (int)($snapshot['_tenant_admin_image_quantity'] ?? 1));
        MarketImageModelRuntimeService::submit($consumptionId, $request, true);
        return self::poll((int)$consumption['tenant_id'], $consumptionId, false);
    }

    public static function poll(int $tenantId, int $consumptionId, bool $refresh = true): array
    {
        self::assertTenant($tenantId);
        $request = request();
        $previousTenant = $request->tenantId ?? null;
        $request->tenantId = $tenantId;
        try {
            $consumption = self::consumption($tenantId, $consumptionId);
            if ($refresh && in_array((string)$consumption['billing_status'], ['reserved', 'pending_usage'], true)) {
                MarketImageModelRuntimeService::refresh($consumptionId);
                $consumption = self::consumption($tenantId, $consumptionId);
            }
            $summary = (array)$consumption['response_summary'];
            $images = [];
            if ((string)$consumption['run_status'] === 'success' && (string)$consumption['billing_status'] === 'settled') {
                AiTaskResultAssetService::recordConsumptionAssets($consumptionId, true);
                foreach ((array)($summary['images'] ?? []) as $image) {
                    if (!is_array($image) || empty($image['image_uri']) || empty($image['storage_scope']) || empty($image['storage_engine'])) continue;
                    $url = (string)$image['storage_engine'] === 'local' ? '/' . ltrim((string)$image['image_uri'], '/')
                        : FileService::getFileUrlByStorage((string)$image['image_uri'], (string)$image['storage_scope'], (string)$image['storage_engine'], (string)($image['storage_domain'] ?? ''));
                    $images[] = array_replace($image, ['url' => $url]);
                }
            }
            $status = (string)$consumption['run_status'];
            if ($status === 'success' && (string)$consumption['billing_status'] !== 'settled') $status = 'running';
            if ($status === 'success' && $images === []) $status = 'needs_review';
            if (in_array($status, ['submitting', 'pending_confirmation'], true) || ($status === 'running' && (string)$consumption['upstream_task_id'] === '')) $status = 'needs_review';
            $spent = (string)$consumption['billing_status'] === 'settled' ? (float)$consumption['actual_tenant_cost'] : ((string)$consumption['billing_status'] === 'refunded' ? 0.0 : (float)$consumption['reserved_tenant_cost']);
            return ['consumption_id' => $consumptionId, 'app_task_id' => (int)$consumption['app_task_id'], 'status' => $status, 'images' => $images, 'error' => (string)$consumption['error_message'], 'billing_status' => (string)$consumption['billing_status'], 'spent_points' => $spent, 'actual_spent_points' => (float)$consumption['actual_tenant_cost']];
        } finally {
            $request->tenantId = $previousTenant;
        }
    }

    private static function consumption(int $tenantId, int $id): AiConsumptionLog
    {
        $consumption = AiConsumptionLog::findOrEmpty($id);
        if ($consumption->isEmpty() || ($tenantId > 0 && (int)$consumption['tenant_id'] !== $tenantId) || (int)$consumption['user_id'] !== 0 || (string)$consumption['app_code'] !== 'system_default' || (string)$consumption['protocol'] !== 'image_generate') throw new Exception('装修图片消耗记录不存在');
        $task = AiAppTask::findOrEmpty((int)$consumption['app_task_id']);
        if ($task->isEmpty() || (string)$task['business_table'] !== self::BUSINESS_TABLE || (int)$task['tenant_id'] !== (int)$consumption['tenant_id']) throw new Exception('装修图片任务不存在');
        return $consumption;
    }

    private static function referenceRequest(int $tenantId, array $request,int $slotId=0): array
    {
        $designSlot=(int)($request['reference_design_slot_id']??0);
        $materialReferences=[];
        if(!empty($request['design_materials'])){
            $slot=Db::name('decorate_agent_image_slot')->where(['id'=>$slotId,'tenant_id'=>$tenantId])->find();
            if(!$slot)throw new Exception('设计素材参考图片位置无效');
            $materialReferences=DecorationAgentDesignService::materialReferences($tenantId,$slot);
        }
        $request = array_intersect_key($request, array_flip(['prompt', 'negative_prompt', 'ratio', 'aspect_ratio', 'quality', 'size', 'image_size', 'resolution', 'quantity', 'attachments', 'reference_images', 'reference_file_ids']));
        $attachments = self::validateAttachments($tenantId, (array)($request['attachments'] ?? []));
        $trusted = array_column($attachments, 'url');
        if (array_key_exists('reference_file_ids', $request)) {
            if (!is_array($request['reference_file_ids'])) throw new Exception('参考图文件ID无效');
            $byId = array_column($attachments, 'url', 'file_id');
            $references = [];
            foreach ($request['reference_file_ids'] as $id) {
                if (!is_scalar($id) || !preg_match('/^[1-9][0-9]*$/', (string)$id) || !isset($byId[(int)$id])) throw new Exception('参考图必须来自当前租户上传附件');
                $references[] = $byId[(int)$id];
            }
        } elseif (array_key_exists('reference_images', $request)) {
            $references = (array)$request['reference_images'];
            foreach ($references as $reference) if (!is_string($reference) || !in_array($reference, $trusted, true)) throw new Exception('参考图必须来自当前租户上传附件');
        } else {
            $references = array_column(array_filter($attachments, static fn(array $attachment): bool => in_array('generation', $attachment['uses'], true)), 'url');
        }
        if($designSlot){
            $slot=Db::name('decorate_agent_image_slot')->where(['id'=>$slotId,'tenant_id'=>$tenantId])->find();
            if(!$slot||(int)(DecorationAgentService::decode($slot['request_json'])['reference_design_slot_id']??0)!==$designSlot)throw new Exception('设计参考图必须来自已授权的本轮图片位置');
            $approved=DecorationAgentDesignService::approvedForRun($tenantId,(int)$slot['admin_id'],(int)$slot['run_id'],$designSlot);
            $read=DecorationAgentDesignService::referenceBytes($tenantId,(int)$slot['admin_id'],$approved);
            $references[]='data:'.$read['mime_type'].';base64,'.base64_encode($read['bytes']);
        }
        $request['reference_images'] = array_values(array_unique(array_merge($references,$materialReferences)));
        unset($request['reference_file_ids']);
        unset($request['callback_url']);
        return $request;
    }

    /** Callers supply identities verified by the design/asset authority, never browser URLs. */
    public static function readStoredImage(int $tenant,array $asset): array
    {
        $uri=(string)($asset['image_uri']??'');
        if(!preg_match('#^/?uploads/[a-zA-Z0-9_./-]+\.(?:png|jpe?g|webp)$#iD',$uri)||preg_match('#(?:^|/)\.\.?(/|$)#',$uri))throw new Exception('存储参考图片身份无效');
        $url=DecorationAgentDesignService::assetUrl($tenant,$asset);
        $bytes=self::readImage(['uri'=>$uri]+$asset,$url);[$width,$height]=self::inspectImage($bytes);$info=getimagesizefromstring($bytes);
        return ['bytes'=>$bytes,'mime_type'=>$info['mime'],'width'=>$width,'height'=>$height,'sha256'=>hash('sha256',$bytes),'url'=>$url];
    }

    private static function assertAttachmentRow(int $tenantId, array $row): void
    {
        if ((int)($row['id'] ?? 0) <= 0 || (int)($row['tenant_id'] ?? 0) !== $tenantId || (int)($row['source'] ?? -1) !== FileEnum::SOURCE_ADMIN || (int)($row['type'] ?? 0) !== FileEnum::IMAGE_TYPE || !empty($row['delete_time'])) throw new Exception('附件不存在或不属于当前租户后台');
        if (!self::imageExtension((string)($row['uri'] ?? ''))) throw new Exception('附件仅支持JPG、PNG、WEBP图片');
    }

    private static function readImage(array $file, string $url): string
    {
        if ((string)($file['storage_engine'] ?? '') === 'local') {
            $uri = (string)$file['uri'];
            if (preg_match('#^https?://#i', $uri)) $uri = (string)parse_url($uri, PHP_URL_PATH);
            $root = realpath(public_path());
            $path = realpath(public_path() . ltrim($uri, '/'));
            if ($root === false || $path === false || !str_starts_with($path, $root . DIRECTORY_SEPARATOR) || !is_file($path)) throw new Exception('附件文件不存在');
            if (filesize($path) > self::MAX_BYTES) throw new Exception('附件不能超过10MB');
            $data = file_get_contents($path);
            if ($data === false) throw new Exception('附件读取失败');
            return $data;
        }
        return OpenSafeHttp::get($url, self::MAX_BYTES, 15)['body'];
    }

    private static function inspectImage(string $data): array
    {
        if (strlen($data) > self::MAX_BYTES) throw new Exception('附件不能超过10MB');
        $info = @getimagesizefromstring($data);
        if (!$info || !in_array($info['mime'] ?? '', ['image/jpeg', 'image/png', 'image/webp'], true) || $info[0] < 1 || $info[1] < 1) throw new Exception('附件仅支持有效的JPG、PNG、WEBP图片');
        return [(int)$info[0], (int)$info[1]];
    }

    private static function matches(string $title,array $tags,string $query): bool
    {
        if($query==='')return true;
        $text=mb_strtolower($title.' '.implode(' ',$tags));
        if(str_contains($text,$query))return true;
        foreach($tags as $tag)if(mb_strlen($tag)>1&&str_contains($query,mb_strtolower($tag)))return true;
        $terms=preg_split('/[\s,，、|]+/u',$query)?:[];
        foreach(['图片|绘图|图像|生图'=>'图片 绘图 图像 生图 image photo img','视频|短片'=>'视频 短片 video','文字|写作|文案'=>'文字 写作 文案 llm','玻璃|立方'=>'玻璃 立方','科技|数据'=>'科技 数据'] as $pattern=>$synonyms)if(preg_match('/'.$pattern.'/u',$query))$terms=array_merge($terms,explode(' ',$synonyms));
        foreach($terms as $term)if($term!==''&&str_contains($text,$term))return true;
        return false;
    }

    private static function contentImageUrl(string $uri): string
    {
        // First-party public icons must also work in a CLI worker with no request host.
        $local=preg_replace('#^https?://resource/#i','resource/',$uri);
        if(str_starts_with(ltrim((string)$local,'/'),'resource/')){
            $root=realpath(public_path());$path=realpath(public_path().ltrim((string)$local,'/'));
            if($root&&$path&&str_starts_with($path,$root.DIRECTORY_SEPARATOR)&&is_file($path))return '/'.ltrim((string)$local,'/');
        }
        return FileService::getFileUrl($uri);
    }

    private static function imageExtension(string $uri): bool
    { return preg_match('/\.(?:jpe?g|png|webp)(?:[?#]|$)/i', $uri) === 1; }

    private static function fileUrl(array $file): string
    {
        $signed = StorageSignedUrlService::resolve((string)$file['uri'], (string)($file['storage_scope'] ?? ''), (string)($file['storage_engine'] ?? ''), (string)($file['storage_domain'] ?? ''), (int)($file['tenant_id'] ?? 0));
        if ($signed !== null) return $signed;
        $request = request();
        $previousTenant = $request->tenantId ?? null;
        $request->tenantId = (int)($file['tenant_id'] ?? 0);
        try {
            return (string)($file['storage_engine'] ?? '') === 'local' ? '/' . ltrim((string)$file['uri'], '/')
                : FileService::getFileUrlByStorage((string)$file['uri'], (string)($file['storage_scope'] ?? ''), (string)($file['storage_engine'] ?? ''), (string)($file['storage_domain'] ?? ''));
        } finally {
            $request->tenantId = $previousTenant;
        }
    }

    private static function assertTenant(int $tenantId): void
    { if ($tenantId <= 0) throw new Exception('租户身份无效'); }
}
