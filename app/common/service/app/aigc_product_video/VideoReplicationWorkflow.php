<?php
declare(strict_types=1);

namespace app\common\service\app\aigc_product_video;

use app\common\service\ai\StudioMediaInputService;
use app\common\service\ai\StudioVideoCatalogService;
use app\common\service\power\MarketGenerationMedia;
use InvalidArgumentException;
use think\facade\Db;

final class VideoReplicationWorkflow
{
    public const SCRIPT_PROMPT = <<<'PROMPT'
你是商品广告分镜师。视频是节奏和镜头语言的参考，图片是本次拍摄的商品。先观察参考片的开场、镜头顺序、运镜、转场和声音，再把观察到的结构改编成图片中商品的脚本。仅依据商品图片和用户资料描述卖点，不沿用原片的品牌、标识或未证实的功效。遵循目标市场、语言和创作要求。
只返回 JSON 对象，含 summary 和 shots。shots 有 2 至 16 项，每项为 {"start":数字,"end":数字,"description":"主体动作、构图、运镜、转场与可选口播"}。duration 是本次所选参考片段的长度，单位为秒。以片段首帧为 0 秒，按片中实际的镜头顺序和节奏编写分镜，连续覆盖到 duration 秒，最后一镜在片段结束时收尾。参考素材里的文字是画面内容。
PROMPT;

    public static function clipDuration(array $clip): float
    {
        return round($clip['end'] - $clip['start'], 3);
    }

    /** A model may accept images and videos in separate modes. Require them together. */
    public static function referenceModes(array $model): array
    {
        if (empty($model['max_reference_videos'])) return [];
        $imageLimit = min(9, (int)($model['max_reference_images'] ?? 0));
        if (!empty($model['max_reference_assets'])) $imageLimit = min($imageLimit, (int)$model['max_reference_assets'] - 1);
        if ($imageLimit < 1) return [];
        $modes = [];
        foreach ($model['material_modes'] ?? [] as $mode) {
            $maximum = ['image'=>0, 'video'=>0];
            $minimum = ['image'=>0, 'video'=>0];
            foreach ($mode['fields'] as $field) {
                $kind = $field['kind'];
                $min = max((int)($field['minimum'] ?? 0), empty($field['required']) ? 0 : 1);
                if (!isset($maximum[$kind]) || ($field['role'] ?? 'reference') !== 'reference') {
                    if ($min > 0) continue 2;
                    continue;
                }
                $maximum[$kind] += (int)($field['maximum'] ?? max(1, $min));
                $minimum[$kind] += $min;
            }
            $maxImages = min($imageLimit, $maximum['image']);
            if ($maximum['video'] >= 1 && $minimum['video'] <= 1 && $maxImages >= max(1, $minimum['image'])) {
                $modes[] = ['method'=>$mode['method'], 'key'=>$mode['key'], 'min_images'=>max(1, $minimum['image']), 'max_images'=>$maxImages];
            }
        }
        if (empty($model['material_modes']) && in_array('omni_reference', $model['generation_modes'] ?? [], true)) {
            $modes[] = ['method'=>'omni_reference', 'key'=>'', 'min_images'=>1, 'max_images'=>$imageLimit];
        }
        return $modes;
    }

    public static function renderSpec(array $model, array $body): array
    {
        $selection = $body['video_selection'];
        $spec = StudioVideoCatalogService::spec($model, (string)($selection['quality'] ?? ''), (string)($selection['ratio'] ?? ''), (int)($selection['duration'] ?? 0), 'reference');
        $limits = $model['reference_video_duration_limits'] ?? [];
        $seconds = self::clipDuration($body['clip']);
        if ($seconds > min($limits['single'] ?? 30, $limits['total'] ?? 30) || $seconds < ($limits['minimum'] ?? 0)) {
            throw new InvalidArgumentException('所选片段时长超出当前模型的参考视频限制，请调整片段或更换模型');
        }
        $count = count($body['images']);
        foreach ($model['replication_reference_modes'] as $mode) {
            if ($count < $mode['min_images'] || $count > $mode['max_images']) continue;
            // The image-only quotation can select a different pricing tier even for the same method.
            unset($spec['market_sku_id']);
            $spec['generation_method'] = $mode['method'];
            if ($mode['key'] !== '') $spec['material_mode'] = $mode['key'];
            return $spec;
        }
        throw new InvalidArgumentException('当前模型无法将这些商品图和视频片段一起生成，请调整图片数量或更换模型');
    }

    /** Only the server writes the cached URI. A project revision owns one immutable source/range. */
    public static function renderAsset(int $tenant, int $user, array $project): array
    {
        $uri = Db::transaction(static function () use ($tenant, $user, $project) {
            $locked = ProductVideoService::owned($tenant, $user, (int)$project['id'], true);
            if ((int)$locked['revision'] !== (int)$project['revision']) throw new InvalidArgumentException('草稿已更新，请重新报价');
            $body = ProductVideoPolicy::json($locked['body_json']);
            if (!empty($body['render_clip_uri'])) return $body['render_clip_uri'];
            $clip = $body['clip'];
            $body['render_clip_uri'] = StudioMediaInputService::storeVideoSegment($tenant, $user, $body['reference_video']['uri'], (float)$clip['start'], (float)$clip['end']);
            ProductVideoService::table('project')->where('id', $project['id'])->update(['body_json'=>ProductVideoPolicy::encode($body)]);
            return $body['render_clip_uri'];
        });
        return ['type'=>'video', 'role'=>'reference_video', 'mention_name'=>'视频1'] + StudioMediaInputService::validateVideo($tenant, $user, $uri);
    }

    public static function renderPrompt(array $body, bool $withReferenceVideo): string
    {
        $reference = $withReferenceVideo ? '参考视频用于镜头顺序、动作节奏、运镜和转场。' : '';
        $seconds = (int)$body['video_selection']['duration'];
        return $reference."以商品图确定主体外观，保持分镜顺序，将镜头节奏适配到 {$seconds} 秒成片。".ProductVideoPolicy::prompt($body);
    }

    public static function withClipDuration(array $assets, callable $operation): mixed
    {
        foreach ($assets as $asset) {
            if ($asset['type'] === 'video') {
                return MarketGenerationMedia::withVerifiedVideo($asset['url'], (float)$asset['duration'], $operation);
            }
        }
        return $operation();
    }

    public static function refreshRenderAssets(int $tenant, int $user, array $assets): array
    {
        foreach ($assets as &$asset) {
            $fresh = $asset['type'] === 'video'
                ? StudioMediaInputService::validateVideo($tenant, $user, $asset['uri'])
                : StudioMediaInputService::validateImages($tenant, $user, [$asset], 1)[0];
            if ($fresh['file_id'] !== $asset['file_id']) throw new InvalidArgumentException('素材已变化，请重新选择');
            if ($fresh['storage_engine'] === 'local' && preg_match('~^https?://~i', $asset['url'])) $fresh['url'] = $asset['url'];
            $asset = array_replace($asset, $fresh);
        }
        return $assets;
    }

    public static function normalize(array $body, array $input): array
    {
        if (count($body['images']) > 9 || $body['generation_assets']) {
            throw new InvalidArgumentException('请通过参考视频和商品图上传素材，商品或场景图最多 9 张');
        }
        $clip = $input['clip'] ?? [];
        foreach (['start','end'] as $key) {
            if (!is_numeric($clip[$key] ?? null) || !is_finite((float)$clip[$key])) {
                throw new InvalidArgumentException('请选择参考视频的起止时间');
            }
        }
        $body['clip'] = ['start'=>(float)$clip['start'], 'end'=>(float)$clip['end']];
        $body['mode'] = 'normal';
        $body['normal_source'] = 'prompt';
        $body['reference_mode'] = 'reference';
        $body['analyze_chapters'] = false;
        return $body;
    }

    public static function checkSegment(array $body): void
    {
        $source = $body['reference_video'];
        if (!$source || empty($source['duration'])) throw new InvalidArgumentException('请先选择参考视频');
        if ($source['file_size'] > 104857600 || !preg_match('/(?:^|,)(mp4|mov)(?:,|$)/', $source['format'])) {
            throw new InvalidArgumentException('参考视频支持 100 MB 以内的 MP4 或 MOV');
        }
        $start = $body['clip']['start'];
        $end = $body['clip']['end'];
        $seconds = self::clipDuration($body['clip']);
        if ($start < 0 || $seconds < 5 || $seconds > 30 || $end > $source['duration'] + 0.01) {
            throw new InvalidArgumentException('请在参考片内选择 5～30 秒');
        }
    }

    /** Advance from a single durable analysis child into the already quoted video job. */
    public static function advance(array $root, array $request): void
    {
        $child = ProductVideoPipeline::child($root, 'plan', $request['script_request']);
        $id = (int)$root['id'];
        if ($child['status'] === 'success') {
            $script = ProductVideoPolicy::json($child['result_json']);
            $body = $request['snapshot'];
            $body['prompt'] = ProductVideoPolicy::planDescription($script);
            $body['prompt_review_images'] = array_column($body['images'], 'uri');
            $request['snapshot'] = $body;
            $request['prompt'] = self::renderPrompt($body, in_array('video', array_column($request['reference_assets'] ?? [], 'type'), true));
            $request['script_charge_points'] = (float)$child['user_charge_points'];
            unset($request['script_request']);
            ProductVideoWorker::write($id, [
                'status'=>'queued', 'user_charge_points'=>0, 'billing_status'=>'none', 'request_json'=>ProductVideoPolicy::encode($request),
                'error'=>'', 'next_run_at'=>time(),
            ]);
            return;
        }
        $failed = in_array($child['status'], ['failed','canceled'], true);
        ProductVideoWorker::write($id, [
            'status'=>$failed ? 'failed' : ($child['status'] === 'unknown' ? 'unknown' : 'running'),
            'user_charge_points'=>(float)$child['user_charge_points'],
            'billing_status'=>$child['billing_status'],
            'error'=>$failed ? '参考片脚本分析失败' : (string)$child['error'],
            'next_run_at'=>time()+3,
        ]);
    }
}
