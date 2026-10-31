<?php
namespace app\common\service\ai;

use app\common\service\JsonService;
use think\facade\Db;
use think\Response;

/** Durable HTTP admission only; generation, billing and result ownership stay with the original tools. */
final class GenerationSubmission
{
    private static ?array $context = null;

    public static function operation(string $controller, string $action): string
    {
        $operation = strtolower($controller . '/' . $action);
        if (preg_match('~^app\.(aigc_(?:image|video|music|music_cover|product_image|product_suite|style_transfer|photo_restore|model_wear|background_removal|image_translate|one_click_cleanup|product_multi_angle|fashion_lookbook|outpaint|local_redraw|fitting|hairstyle|product_promo_video|digital_human|pic_lipsync|watermark_removal|action_transfer|person_replacement)|smart_clip|image_human)\.generate/index$~D', $operation)) return $operation;
        $routes = [
            'app.aigc_canvas.generate/image', 'app.aigc_canvas.generate/video', 'app.aigc_canvas.generate/music',
            'app.aigc_short_drama.canvas/run',
            'app.aigc_micro_video.plan/create', 'app.aigc_micro_video.prompt/write',
            'app.aigc_micro_video.frame/generate', 'app.aigc_micro_video.generate/index', 'app.aigc_micro_video.task/retry',
            'app.aigc_music_cover.task/retry',
            'app.aigc_music.lyrics/index', 'app.aigc_music.lyrics/mashup', 'app.aigc_music.style/generate',
            'app.aigc_music.export/wav', 'app.aigc_music.export/midi', 'app.aigc_music.export/mp4', 'app.aigc_music.export/vox', 'app.aigc_music.export/timing',
            'app.aigc_music.persona/upstream', 'app.aigc_music.asset/upload_upstream', 'app.aigc_music.voice_clone/create',
        ];
        return in_array($operation, $routes, true) ? $operation : '';
    }

    public static function key(): string { return self::$context['key'] ?? ''; }

    public static function fingerprint(array $params): string
    {
        $sort = static function ($value) use (&$sort) {
            if (!is_array($value)) return $value;
            foreach ($value as &$child) $child = $sort($child);
            unset($child);
            if (!array_is_list($value)) ksort($value);
            return $value;
        };
        return hash('sha256', json_encode($sort($params), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    public static function run(int $tenant, int $user, string $operation, string $key, array $params, callable $dispatch): Response
    {
        if ($tenant <= 0 || $user <= 0 || !preg_match('/^[A-Za-z0-9_-]{16,128}$/D', $key)) return JsonService::fail('生成提交编号无效，请刷新后重试');
        $identity = ['tenant_id' => $tenant, 'user_id' => $user, 'operation' => $operation, 'key_hash' => hash('sha256', $key)];
        $hash = self::fingerprint($params);
        $token = bin2hex(random_bytes(16));
        try {
            // INSERT IGNORE arbitrates the unique key. No user or database lock survives dispatch.
            Db::name('ai_generation_submission')->extra('IGNORE')->insert($identity + [
                'payload_hash' => $hash, 'owner_token' => $token, 'status' => 'submitting',
                'task_links' => '[]', 'response_json' => '', 'create_time' => time(), 'update_time' => time(),
            ]);
            $row = Db::name('ai_generation_submission')->where($identity)->find();
        } catch (\Throwable $error) {
            \think\facade\Log::error('Generation submission receipt unavailable: ' . get_class($error));
            return JsonService::fail('生成提交组件暂不可用，请稍后重试');
        }
        if (!$row || !hash_equals($hash, $row['payload_hash'])) return JsonService::fail('同一提交编号的内容已变化，请新建请求');
        if (!hash_equals($token, $row['owner_token'])) {
            $until = microtime(true) + 1;
            while ($row['status'] === 'submitting' && microtime(true) < $until) {
                usleep(50000);
                $row = Db::name('ai_generation_submission')->where($identity)->find();
            }
            return self::replay($row, $tenant, $user);
        }
        $previous = self::$context;
        self::$context = ['id' => (int)$row['id'], 'token' => $token, 'key' => $key, 'tenant' => $tenant, 'user' => $user, 'saw_task' => false];
        try {
            $response = $dispatch();
            $body = $response->getData();
            if (!is_array($body) || !array_key_exists('code', $body)) throw new \RuntimeException('生成受理回执格式异常');
            // Models/collections may serialize lazily. Freeze once for both the first response and replays.
            $encoded = json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
            $body = json_decode($encoded, true, 512, JSON_THROW_ON_ERROR);
            $state = (int)$body['code'] === 1 ? 'accepted' : (self::$context['saw_task'] ? 'uncertain' : 'rejected');
            Db::name('ai_generation_submission')->where(['id' => $row['id'], 'owner_token' => $token])->update([
                'status' => $state, 'response_json' => $encoded, 'update_time' => time(),
            ]);
            return json($body + ['generation_submission' => ['state' => $state, 'replayed' => false]], $response->getCode());
        } catch (\Throwable $error) {
            // A killed or uncertain dispatch must never be automatically re-entered, even after a timeout.
            Db::name('ai_generation_submission')->where(['id' => $row['id'], 'owner_token' => $token])->update(['status' => 'uncertain', 'update_time' => time()]);
            throw $error;
        } finally {
            self::$context = $previous;
        }
    }

    public static function linkTask($model): void
    {
        if (self::$context === null) return;
        $table = $model->getName();
        if (!preg_match('/^(?:ai_app_task|aigc_[a-z0-9_]+_task|smart_clip_task|image_human_task|aigc_short_drama_canvas_run)$/D', $table)) return;
        if ((int)$model['tenant_id'] !== self::$context['tenant'] || (int)$model['user_id'] !== self::$context['user']) throw new \RuntimeException('生成任务归属不一致');
        self::$context['saw_task'] = true;
        $query = Db::name('ai_generation_submission')->where(['id' => self::$context['id'], 'owner_token' => self::$context['token']]);
        $row = (clone $query)->lock(true)->find();
        if (!$row) throw new \RuntimeException('生成提交回执已不存在');
        $links = json_decode($row['task_links'], true) ?: [];
        $links[] = ['table' => $table, 'id' => (int)$model['id']];
        $query->update(['task_links' => json_encode($links), 'update_time' => time()]);
    }

    private static function replay(array $row, int $tenant, int $user): Response
    {
        foreach (json_decode($row['task_links'], true) ?: [] as $link) {
            $task = Db::name($link['table'])->where(['tenant_id' => $tenant, 'user_id' => $user, 'id' => $link['id']])->find();
            if (!$task || !empty($task['delete_time'])) return JsonService::fail('本次提交对应任务已删除或不可用，请新建请求');
        }
        $body = json_decode($row['response_json'], true);
        if (is_array($body)) return json($body + ['generation_submission' => ['state' => $row['status'], 'replayed' => true]]);
        return json(['code' => 0, 'show' => 1, 'msg' => '本次生成正在受理或等待核对，请使用原请求重试，勿重复新建', 'data' => [], 'generation_submission' => ['state' => $row['status'], 'replayed' => true]]);
    }
}
