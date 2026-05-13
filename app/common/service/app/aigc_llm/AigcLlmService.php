<?php

namespace app\common\service\app\aigc_llm;

use app\common\model\app\aigc_llm\AigcLlmChannel;
use app\common\model\app\aigc_llm\AigcLlmConfig;
use app\common\model\app\aigc_llm\AigcLlmMessage;
use app\common\model\app\aigc_llm\AigcLlmModel;
use app\common\model\app\aigc_llm\AigcLlmSensitiveWord;
use app\common\model\app\aigc_llm\AigcLlmSession;
use app\common\model\app\aigc_llm\AigcLlmUsage;
use app\common\service\point\PointService;
use Exception;
use Throwable;
use think\facade\Db;

class AigcLlmService
{
    public const APP_CODE = 'aigc_llm';
    public const SESSION_IDLE = 'idle';
    public const SESSION_STREAMING = 'streaming';
    public const MESSAGE_DONE = 'done';
    public const MESSAGE_STREAMING = 'streaming';
    public const MESSAGE_STOPPED = 'stopped';
    public const MESSAGE_ERROR = 'error';

    public static function config(int $tenantId): array
    {
        $config = AigcLlmConfig::where('tenant_id', $tenantId)->findOrEmpty();
        $default = self::defaultConfig();
        if ($config->isEmpty()) {
            return array_merge($default, [
                'tenant_id' => $tenantId,
                'option_config' => AigcLlmChannelService::userConfig($tenantId),
            ]);
        }
        $data = array_merge($default, $config->toArray());
        $data['config_json'] = array_merge($default['config_json'], (array)($data['config_json'] ?? []));
        $data['option_config'] = AigcLlmChannelService::userConfig($tenantId);
        return $data;
    }

    public static function saveConfig(int $tenantId, array $params): void
    {
        $current = self::config($tenantId);
        $configJson = array_merge((array)($current['config_json'] ?? []), self::normalizeJson($params['config_json'] ?? []));
        $data = [
            'tenant_id' => $tenantId,
            'provider_mode' => (string)($params['provider_mode'] ?? $current['provider_mode'] ?? 'platform'),
            'provider' => (string)($params['provider'] ?? $current['provider'] ?? 'openai_compatible'),
            'model' => (string)($params['model'] ?? $current['model'] ?? 'qwen3_6_plus'),
            'status' => (int)($params['status'] ?? $current['status'] ?? 1),
            'config_json' => [
                'system_prompt' => trim((string)($configJson['system_prompt'] ?? '')),
                'max_context_messages' => max(2, (int)($configJson['max_context_messages'] ?? 12)),
                'auto_title_chars' => max(8, (int)($configJson['auto_title_chars'] ?? 18)),
            ],
            'update_time' => time(),
        ];
        $row = AigcLlmConfig::where('tenant_id', $tenantId)->findOrEmpty();
        if ($row->isEmpty()) {
            $data['create_time'] = time();
            AigcLlmConfig::create($data);
            return;
        }
        $row->save($data);
    }

    public static function sessionLists(int $tenantId, int $userId): array
    {
        $rows = AigcLlmSession::where([
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'delete_time' => 0,
        ])->order(['last_message_at' => 'desc', 'id' => 'desc'])->limit(50)->select()->toArray();
        foreach ($rows as &$row) {
            $row['last_message'] = self::lastMessageSnippet($tenantId, (int)$row['id']);
        }
        return $rows;
    }

    public static function createSession(int $tenantId, int $userId, array $params = []): array
    {
        $title = trim((string)($params['title'] ?? '新对话'));
        $modelCode = trim((string)($params['model_code'] ?? ''));
        $time = time();
        $session = AigcLlmSession::create([
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'title' => mb_substr($title, 0, 50, 'UTF-8'),
            'model_code' => $modelCode,
            'status' => self::SESSION_IDLE,
            'last_message_at' => $time,
            'message_count' => 0,
            'create_time' => $time,
            'update_time' => $time,
            'delete_time' => 0,
        ]);
        return $session->toArray();
    }

    public static function sessionDetail(int $tenantId, int $userId, int $sessionId): array
    {
        $session = self::findUserSession($tenantId, $userId, $sessionId);
        $data = $session->toArray();
        $data['messages'] = self::messageLists($tenantId, $userId, $sessionId);
        $data['last_message'] = self::lastMessageSnippet($tenantId, $sessionId);
        return $data;
    }

    public static function renameSession(int $tenantId, int $userId, int $sessionId, string $title): void
    {
        $session = self::findUserSession($tenantId, $userId, $sessionId);
        $title = trim($title);
        if ($title === '') {
            throw new Exception('请输入会话标题');
        }
        $session->save([
            'title' => mb_substr($title, 0, 50, 'UTF-8'),
            'update_time' => time(),
        ]);
    }

    public static function deleteSession(int $tenantId, int $userId, int $sessionId): void
    {
        $session = self::findUserSession($tenantId, $userId, $sessionId);
        $time = time();
        $session->save([
            'delete_time' => $time,
            'status' => self::SESSION_IDLE,
            'update_time' => $time,
        ]);
        AigcLlmMessage::where([
            'tenant_id' => $tenantId,
            'session_id' => $sessionId,
            'delete_time' => 0,
        ])->update([
            'delete_time' => $time,
            'update_time' => $time,
        ]);
    }

    public static function messageLists(int $tenantId, int $userId, int $sessionId): array
    {
        self::findUserSession($tenantId, $userId, $sessionId);
        return AigcLlmMessage::where([
            'tenant_id' => $tenantId,
            'session_id' => $sessionId,
            'delete_time' => 0,
        ])->order(['seq' => 'asc', 'id' => 'asc'])->select()->toArray();
    }

    public static function adminSessionLists(int $tenantId, array $params = []): array
    {
        $query = AigcLlmSession::where(['tenant_id' => $tenantId, 'delete_time' => 0])->order(['last_message_at' => 'desc', 'id' => 'desc']);
        if (!empty($params['user_id'])) {
            $query->where('user_id', (int)$params['user_id']);
        }
        if (!empty($params['keyword'])) {
            $keyword = trim((string)$params['keyword']);
            $query->whereLike('title', '%' . $keyword . '%');
        }
        $rows = $query->limit(100)->select()->toArray();
        foreach ($rows as &$row) {
            $row['last_message'] = self::lastMessageSnippet($tenantId, (int)$row['id']);
        }
        return $rows;
    }

    public static function adminSessionDetail(int $tenantId, int $sessionId): array
    {
        $session = AigcLlmSession::where([
            'tenant_id' => $tenantId,
            'id' => $sessionId,
            'delete_time' => 0,
        ])->findOrEmpty();
        if ($session->isEmpty()) {
            throw new Exception('会话不存在');
        }
        $data = $session->toArray();
        $data['messages'] = AigcLlmMessage::where([
            'tenant_id' => $tenantId,
            'session_id' => $sessionId,
            'delete_time' => 0,
        ])->order(['seq' => 'asc', 'id' => 'asc'])->select()->toArray();
        $data['last_message'] = self::lastMessageSnippet($tenantId, $sessionId);
        return $data;
    }

    public static function sensitiveWordLists(int $tenantId): array
    {
        return AigcLlmSensitiveWord::where('tenant_id', $tenantId)->order(['id' => 'desc'])->select()->toArray();
    }

    public static function saveSensitiveWord(int $tenantId, array $params): void
    {
        $word = trim((string)($params['word'] ?? ''));
        if ($word === '') {
            throw new Exception('请输入敏感词');
        }
        $data = [
            'tenant_id' => $tenantId,
            'word' => $word,
            'status' => (int)($params['status'] ?? 1),
            'update_time' => time(),
        ];
        $id = (int)($params['id'] ?? 0);
        $row = $id > 0 ? AigcLlmSensitiveWord::where(['tenant_id' => $tenantId, 'id' => $id])->findOrEmpty() : AigcLlmSensitiveWord::where(['tenant_id' => $tenantId, 'word' => $word])->findOrEmpty();
        if ($row->isEmpty()) {
            $data['create_time'] = time();
            AigcLlmSensitiveWord::create($data);
            return;
        }
        $row->save($data);
    }

    public static function stat(int $tenantId): array
    {
        $todayStart = strtotime(date('Y-m-d'));
        return [
            'session_count' => (int)AigcLlmSession::where(['tenant_id' => $tenantId, 'delete_time' => 0])->count(),
            'message_count' => (int)AigcLlmMessage::where(['tenant_id' => $tenantId, 'delete_time' => 0])->count(),
            'user_count' => (int)AigcLlmSession::where(['tenant_id' => $tenantId, 'delete_time' => 0])->distinct(true)->count('user_id'),
            'today_session_count' => (int)AigcLlmSession::where('tenant_id', $tenantId)->where('delete_time', 0)->where('create_time', '>=', $todayStart)->count(),
            'today_message_count' => (int)AigcLlmMessage::where('tenant_id', $tenantId)->where('delete_time', 0)->where('create_time', '>=', $todayStart)->count(),
            'prompt_tokens' => (int)AigcLlmUsage::where(['tenant_id' => $tenantId, 'billing_status' => 'deducted'])->sum('prompt_tokens'),
            'completion_tokens' => (int)AigcLlmUsage::where(['tenant_id' => $tenantId, 'billing_status' => 'deducted'])->sum('completion_tokens'),
            'total_tokens' => (int)AigcLlmUsage::where(['tenant_id' => $tenantId, 'billing_status' => 'deducted'])->sum('total_tokens'),
            'tenant_cost_points' => (float)AigcLlmUsage::where(['tenant_id' => $tenantId, 'billing_status' => 'deducted'])->sum('tenant_cost_points'),
            'user_charge_points' => (float)AigcLlmUsage::where(['tenant_id' => $tenantId, 'billing_status' => 'deducted'])->sum('user_charge_points'),
            'today_tokens' => (int)AigcLlmUsage::where('tenant_id', $tenantId)->where('billing_status', 'deducted')->where('create_time', '>=', $todayStart)->sum('total_tokens'),
            'today_user_charge_points' => (float)AigcLlmUsage::where('tenant_id', $tenantId)->where('billing_status', 'deducted')->where('create_time', '>=', $todayStart)->sum('user_charge_points'),
        ];
    }

    public static function tenantStat(): array
    {
        $sessionRows = AigcLlmSession::where('delete_time', 0)
            ->field('tenant_id,count(*) as session_count,count(distinct user_id) as user_count,max(update_time) as update_time')
            ->group('tenant_id')
            ->select()
            ->toArray();
        $messageRows = AigcLlmMessage::where('delete_time', 0)
            ->field('tenant_id,count(*) as message_count')
            ->group('tenant_id')
            ->select()
            ->toArray();
        $messageMap = [];
        foreach ($messageRows as $row) {
            $messageMap[(int)$row['tenant_id']] = (int)$row['message_count'];
        }
        foreach ($sessionRows as &$row) {
            $row['message_count'] = $messageMap[(int)$row['tenant_id']] ?? 0;
        }
        $usageRows = AigcLlmUsage::where('billing_status', 'deducted')
            ->field('tenant_id,sum(prompt_tokens) as prompt_tokens,sum(completion_tokens) as completion_tokens,sum(total_tokens) as total_tokens,sum(tenant_cost_points) as tenant_cost_points,sum(user_charge_points) as user_charge_points')
            ->group('tenant_id')
            ->select()
            ->toArray();
        $usageMap = [];
        foreach ($usageRows as $row) {
            $usageMap[(int)$row['tenant_id']] = $row;
        }
        foreach ($sessionRows as &$row) {
            $usage = $usageMap[(int)$row['tenant_id']] ?? [];
            $row['prompt_tokens'] = (int)($usage['prompt_tokens'] ?? 0);
            $row['completion_tokens'] = (int)($usage['completion_tokens'] ?? 0);
            $row['total_tokens'] = (int)($usage['total_tokens'] ?? 0);
            $row['tenant_cost_points'] = (float)($usage['tenant_cost_points'] ?? 0);
            $row['user_charge_points'] = (float)($usage['user_charge_points'] ?? 0);
        }
        return $sessionRows;
    }

    public static function stopChat(int $tenantId, int $userId, int $sessionId): void
    {
        $session = self::findUserSession($tenantId, $userId, $sessionId);
        $time = time();
        $session->save([
            'status' => self::SESSION_IDLE,
            'update_time' => $time,
        ]);
        $message = AigcLlmMessage::where([
            'tenant_id' => $tenantId,
            'session_id' => $sessionId,
            'role' => 'assistant',
            'status' => self::MESSAGE_STREAMING,
            'delete_time' => 0,
        ])->order('id', 'desc')->findOrEmpty();
        if ($message->isEmpty()) {
            return;
        }
        $message->save([
            'status' => self::MESSAGE_STOPPED,
            'finish_reason' => 'stopped',
            'update_time' => $time,
        ]);
    }

    public static function streamChat(int $tenantId, int $userId, array $params): void
    {
        $content = trim((string)($params['content'] ?? ''));
        $regenerateMessageId = (int)($params['regenerate_message_id'] ?? 0);
        if ($content === '' && $regenerateMessageId <= 0) {
            throw new Exception('请输入消息内容');
        }
        $config = self::config($tenantId);
        if ((int)($config['status'] ?? 1) !== 1) {
            throw new Exception('对话应用已停用');
        }
        self::checkSensitiveWords($tenantId, $content);
        $model = AigcLlmChannelService::resolveUserModel($tenantId, $params, $config);
        $sessionId = (int)($params['session_id'] ?? 0);
        $time = time();

        $context = Db::transaction(function () use ($tenantId, $userId, $sessionId, &$content, $model, $config, $regenerateMessageId, $time) {
            $session = $sessionId > 0
                ? self::findUserSession($tenantId, $userId, $sessionId, true)
                : AigcLlmSession::create([
                    'tenant_id' => $tenantId,
                    'user_id' => $userId,
                    'title' => self::buildTitle($content, (int)($config['config_json']['auto_title_chars'] ?? 18)),
                    'model_code' => $model['code'],
                    'status' => self::SESSION_IDLE,
                    'last_message_at' => $time,
                    'message_count' => 0,
                    'create_time' => $time,
                    'update_time' => $time,
                    'delete_time' => 0,
                ]);
            if ($sessionId > 0 && (string)$session['status'] === self::SESSION_STREAMING) {
                throw new Exception('当前会话仍在生成中');
            }

            $latestSeq = (int)AigcLlmMessage::where([
                'tenant_id' => $tenantId,
                'session_id' => (int)$session['id'],
                'delete_time' => 0,
            ])->max('seq');
            $parentUserMessageId = 0;
            if ($regenerateMessageId > 0) {
                $parentUserMessage = AigcLlmMessage::where([
                    'tenant_id' => $tenantId,
                    'session_id' => (int)$session['id'],
                    'id' => $regenerateMessageId,
                    'role' => 'user',
                    'delete_time' => 0,
                ])->findOrEmpty();
                if ($parentUserMessage->isEmpty()) {
                    throw new Exception('重答消息不存在');
                }
                $latestUserMessage = AigcLlmMessage::where([
                    'tenant_id' => $tenantId,
                    'session_id' => (int)$session['id'],
                    'role' => 'user',
                    'delete_time' => 0,
                ])->order('id', 'desc')->findOrEmpty();
                if ($latestUserMessage->isEmpty() || (int)$latestUserMessage['id'] !== (int)$parentUserMessage['id']) {
                    throw new Exception('仅支持重答最近一轮用户问题');
                }
                $parentUserMessageId = (int)$parentUserMessage['id'];
                $content = (string)$parentUserMessage['content'];
            } else {
                $userMessage = AigcLlmMessage::create([
                    'tenant_id' => $tenantId,
                    'session_id' => (int)$session['id'],
                    'user_id' => $userId,
                    'role' => 'user',
                    'content' => $content,
                    'seq' => $latestSeq + 1,
                    'status' => self::MESSAGE_DONE,
                    'finish_reason' => 'submitted',
                    'token_usage_json' => [],
                    'parent_user_message_id' => 0,
                    'create_time' => $time,
                    'update_time' => $time,
                    'delete_time' => 0,
                ]);
                $latestSeq = (int)$userMessage['seq'];
                $parentUserMessageId = (int)$userMessage['id'];
            }

            $assistantMessage = AigcLlmMessage::create([
                'tenant_id' => $tenantId,
                'session_id' => (int)$session['id'],
                'user_id' => $userId,
                'role' => 'assistant',
                'content' => '',
                'seq' => $latestSeq + 1,
                'status' => self::MESSAGE_STREAMING,
                'finish_reason' => '',
                'token_usage_json' => [],
                'parent_user_message_id' => $parentUserMessageId,
                'create_time' => $time,
                'update_time' => $time,
                'delete_time' => 0,
            ]);

            $session->save([
                'model_code' => $model['code'],
                'status' => self::SESSION_STREAMING,
                'last_message_at' => $time,
                'message_count' => (int)AigcLlmMessage::where([
                    'tenant_id' => $tenantId,
                    'session_id' => (int)$session['id'],
                    'delete_time' => 0,
                ])->count(),
                'update_time' => $time,
            ]);

            $historyMaxId = $regenerateMessageId > 0 ? $parentUserMessageId : (int)$assistantMessage['id'];
            $history = AigcLlmMessage::where([
                'tenant_id' => $tenantId,
                'session_id' => (int)$session['id'],
                'delete_time' => 0,
            ])->where('id', '<=', $historyMaxId)->order(['seq' => 'asc', 'id' => 'asc'])->select()->toArray();
            $history = array_values(array_filter($history, fn(array $row) => in_array($row['role'], ['user', 'assistant'], true) && !($row['role'] === 'assistant' && (int)$row['id'] === (int)$assistantMessage['id'])));
            $history = self::trimContextMessages($history, (int)($config['config_json']['max_context_messages'] ?? 12));

            return [
                'session' => $session->toArray(),
                'assistant_message' => $assistantMessage->toArray(),
                'history' => $history,
                'model' => $model,
                'config' => $config,
            ];
        });

        $session = $context['session'];
        $assistantMessage = $context['assistant_message'];
        self::emitEvent('session', [
            'session_id' => (int)$session['id'],
            'title' => (string)$session['title'],
            'model_code' => (string)$context['model']['code'],
        ]);
        self::emitEvent('message', [
            'user_message_id' => (int)$assistantMessage['parent_user_message_id'],
            'assistant_message_id' => (int)$assistantMessage['id'],
            'parent_user_message_id' => (int)$assistantMessage['parent_user_message_id'],
        ]);

        $provider = self::providerFor((string)($context['model']['provider'] ?? 'openai_compatible'));
        $channelConfig = self::resolveChannelConfig($tenantId, (string)($context['model']['channel_code'] ?? ''));

        $request = new AigcLlmGenerateRequest(
            $tenantId,
            $userId,
            (int)$session['id'],
            (string)($context['config']['config_json']['system_prompt'] ?? ''),
            (string)($context['model']['channel_code'] ?? ''),
            (string)($context['model']['code'] ?? ''),
            array_map(fn(array $row) => [
                'role' => $row['role'],
                'content' => (string)$row['content'],
            ], $context['history']),
            $context['model'],
            $channelConfig
        );

        $precheck = self::estimateBilling($context['history'], '', $context['model']);
        if ($precheck['tenant_cost_points'] > 0 || $precheck['user_charge_points'] > 0) {
            PointService::assertCanConsumeAmounts($tenantId, $userId, (float)$precheck['tenant_cost_points'], (float)$precheck['user_charge_points']);
        }

        $output = '';
        $usage = [];
        $finishReason = 'stop';
        $providerRequestId = '';
        try {
            foreach ($provider->stream($request) as $event) {
                if (self::shouldStopStream($tenantId, (int)$assistantMessage['id'])) {
                    $finishReason = 'stopped';
                    $billing = self::finishChatWithBilling($tenantId, $userId, (int)$session['id'], (int)$assistantMessage['id'], $output, self::MESSAGE_STOPPED, $finishReason, $context, $usage, $providerRequestId);
                    self::emitEvent('done', [
                        'message_id' => (int)$assistantMessage['id'],
                        'content' => $output,
                        'finish_reason' => $finishReason,
                        'usage' => $billing['usage'],
                        'billing' => $billing['billing'],
                        'charge_points' => $billing['billing']['user_charge_points'],
                    ]);
                    return;
                }
                $type = (string)($event['type'] ?? 'delta');
                if (!empty($event['provider_request_id'])) {
                    $providerRequestId = (string)$event['provider_request_id'];
                }
                if ($type === 'usage') {
                    $usage = (array)($event['usage'] ?? []);
                    continue;
                }
                if ($type === 'done') {
                    $finishReason = (string)($event['finish_reason'] ?? $finishReason);
                    continue;
                }
                $delta = (string)($event['content'] ?? '');
                if ($delta === '') {
                    continue;
                }
                $output .= $delta;
                self::emitEvent('delta', [
                    'message_id' => (int)$assistantMessage['id'],
                    'delta' => $delta,
                ]);
            }
            $billing = self::finishChatWithBilling($tenantId, $userId, (int)$session['id'], (int)$assistantMessage['id'], $output, self::MESSAGE_DONE, $finishReason, $context, $usage, $providerRequestId);
            self::emitEvent('done', [
                'message_id' => (int)$assistantMessage['id'],
                'content' => $output,
                'finish_reason' => $finishReason,
                'usage' => $billing['usage'],
                'billing' => $billing['billing'],
                'charge_points' => $billing['billing']['user_charge_points'],
            ]);
        } catch (Throwable $e) {
            self::finishAssistantMessage($tenantId, (int)$assistantMessage['id'], [
                'content' => $output,
                'status' => self::MESSAGE_ERROR,
                'finish_reason' => 'error',
                'token_usage_json' => self::buildUsage($context['history'], $output, $usage, $context['model'], [
                    'billing_status' => 'none',
                    'provider_request_id' => $providerRequestId,
                    'error' => $e->getMessage(),
                ]),
            ]);
            self::finishSession((int)$session['id'], $tenantId);
            self::emitEvent('error', [
                'message' => $e->getMessage(),
            ]);
        }
    }

    public static function generateText(int $tenantId, int $userId, array $params): array
    {
        $content = trim((string)($params['content'] ?? $params['prompt'] ?? ''));
        if ($content === '') {
            throw new Exception('请输入文本内容');
        }
        $config = self::config($tenantId);
        if ((int)($config['status'] ?? 1) !== 1) {
            throw new Exception('对话应用已停用');
        }
        self::checkSensitiveWords($tenantId, $content);

        $model = AigcLlmChannelService::resolveUserModel($tenantId, [
            'model_code' => (string)($params['model_code'] ?? $params['model'] ?? ''),
        ], $config);
        $systemPrompt = trim((string)($params['system_prompt'] ?? ''));
        if ($systemPrompt === '') {
            $systemPrompt = (string)($config['config_json']['system_prompt'] ?? '');
        }
        $history = self::buildTextHistory($content, (array)($params['reference_images'] ?? $params['image_urls'] ?? []));
        $precheck = self::estimateBilling($history, '', $model);
        if ((float)$precheck['tenant_cost_points'] > 0 || (float)$precheck['user_charge_points'] > 0) {
            PointService::assertCanConsumeAmounts($tenantId, $userId, (float)$precheck['tenant_cost_points'], (float)$precheck['user_charge_points']);
        }

        $request = new AigcLlmGenerateRequest(
            $tenantId,
            $userId,
            0,
            $systemPrompt,
            (string)($model['channel_code'] ?? ''),
            (string)($model['code'] ?? ''),
            $history,
            $model,
            self::resolveChannelConfig($tenantId, (string)($model['channel_code'] ?? ''))
        );

        $provider = self::providerFor((string)($model['provider'] ?? 'openai_compatible'));
        $output = '';
        $usage = [];
        $finishReason = 'stop';
        $providerRequestId = '';
        foreach ($provider->stream($request) as $event) {
            if (!empty($event['provider_request_id'])) {
                $providerRequestId = (string)$event['provider_request_id'];
            }
            $type = (string)($event['type'] ?? 'delta');
            if ($type === 'usage') {
                $usage = (array)($event['usage'] ?? []);
                continue;
            }
            if ($type === 'done') {
                $finishReason = (string)($event['finish_reason'] ?? $finishReason);
                continue;
            }
            $output .= (string)($event['content'] ?? '');
        }
        if (trim($output) === '') {
            throw new Exception(self::emptyTextError($finishReason, count(self::normalizeReferenceImages((array)($params['reference_images'] ?? $params['image_urls'] ?? [])))));
        }

        $billing = self::consumeTextUsage($tenantId, $userId, $history, $output, $model, $usage, $providerRequestId, [
            'source_app_code' => (string)($params['source_app_code'] ?? 'aigc_canvas'),
            'source_type' => (string)($params['source_type'] ?? 'text'),
            'source_id' => (string)($params['source_id'] ?? ''),
            'finish_reason' => $finishReason,
            'reference_image_count' => count(self::normalizeReferenceImages((array)($params['reference_images'] ?? $params['image_urls'] ?? []))),
        ]);

        return [
            'content' => $output,
            'model_code' => (string)($model['code'] ?? ''),
            'channel_code' => (string)($model['channel_code'] ?? ''),
            'finish_reason' => $finishReason,
            'usage' => $billing['usage'],
            'billing' => $billing['billing'],
            'charge_points' => $billing['billing']['user_charge_points'],
        ];
    }

    public static function streamText(int $tenantId, int $userId, array $params, ?callable $onEvent = null): array
    {
        $content = trim((string)($params['content'] ?? $params['prompt'] ?? ''));
        if ($content === '') {
            throw new Exception('请输入文本内容');
        }
        $config = self::config($tenantId);
        if ((int)($config['status'] ?? 1) !== 1) {
            throw new Exception('对话应用已停用');
        }
        self::checkSensitiveWords($tenantId, $content);

        $model = AigcLlmChannelService::resolveUserModel($tenantId, [
            'model_code' => (string)($params['model_code'] ?? $params['model'] ?? ''),
        ], $config);
        $systemPrompt = trim((string)($params['system_prompt'] ?? ''));
        if ($systemPrompt === '') {
            $systemPrompt = (string)($config['config_json']['system_prompt'] ?? '');
        }
        $referenceImages = self::normalizeReferenceImages((array)($params['reference_images'] ?? $params['image_urls'] ?? []));
        $history = self::buildTextHistory($content, $referenceImages);
        $precheck = self::estimateBilling($history, '', $model);
        if ((float)$precheck['tenant_cost_points'] > 0 || (float)$precheck['user_charge_points'] > 0) {
            PointService::assertCanConsumeAmounts($tenantId, $userId, (float)$precheck['tenant_cost_points'], (float)$precheck['user_charge_points']);
        }

        $request = new AigcLlmGenerateRequest(
            $tenantId,
            $userId,
            0,
            $systemPrompt,
            (string)($model['channel_code'] ?? ''),
            (string)($model['code'] ?? ''),
            $history,
            $model,
            self::resolveChannelConfig($tenantId, (string)($model['channel_code'] ?? ''))
        );

        $provider = self::providerFor((string)($model['provider'] ?? 'openai_compatible'));
        $output = '';
        $usage = [];
        $finishReason = 'stop';
        $providerRequestId = '';
        foreach ($provider->stream($request) as $event) {
            if (!empty($event['provider_request_id'])) {
                $providerRequestId = (string)$event['provider_request_id'];
            }
            $type = (string)($event['type'] ?? 'delta');
            if ($type === 'usage') {
                $usage = (array)($event['usage'] ?? []);
                continue;
            }
            if ($type === 'done') {
                $finishReason = (string)($event['finish_reason'] ?? $finishReason);
                continue;
            }
            $delta = (string)($event['content'] ?? '');
            if ($delta === '') {
                continue;
            }
            $output .= $delta;
            if ($onEvent) {
                $onEvent('delta', ['delta' => $delta]);
            }
        }
        if (trim($output) === '') {
            throw new Exception(self::emptyTextError($finishReason, count($referenceImages)));
        }

        $billing = self::consumeTextUsage($tenantId, $userId, $history, $output, $model, $usage, $providerRequestId, [
            'source_app_code' => (string)($params['source_app_code'] ?? 'aigc_canvas'),
            'source_type' => (string)($params['source_type'] ?? 'text'),
            'source_id' => (string)($params['source_id'] ?? ''),
            'finish_reason' => $finishReason,
            'reference_image_count' => count($referenceImages),
        ]);

        return [
            'content' => $output,
            'model_code' => (string)($model['code'] ?? ''),
            'channel_code' => (string)($model['channel_code'] ?? ''),
            'finish_reason' => $finishReason,
            'usage' => $billing['usage'],
            'billing' => $billing['billing'],
            'charge_points' => $billing['billing']['user_charge_points'],
        ];
    }

    private static function providerFor(string $provider): AigcLlmProviderInterface
    {
        if (in_array($provider, ['openai_compatible', 'qwen', 'deepseek', 'doubao'], true)) {
            return new OpenAiCompatibleLlmProvider();
        }
        throw new Exception('不支持的对话通道类型，请配置 OpenAI 兼容通道');
    }

    private static function defaultConfig(): array
    {
        return [
            'provider_mode' => 'platform',
            'provider' => 'openai_compatible',
            'model' => 'qwen3_6_plus',
            'status' => 1,
            'config_json' => [
                'system_prompt' => '',
                'max_context_messages' => 12,
                'auto_title_chars' => 18,
            ],
        ];
    }

    private static function buildTitle(string $content, int $limit): string
    {
        $title = preg_replace('/\s+/', ' ', trim($content)) ?: '新对话';
        return mb_substr($title, 0, $limit, 'UTF-8');
    }

    private static function trimContextMessages(array $messages, int $limit): array
    {
        if ($limit <= 0 || count($messages) <= $limit) {
            return $messages;
        }
        return array_slice($messages, -$limit);
    }

    public static function estimateTokensFromText(string $text): int
    {
        $chars = mb_strlen($text, 'UTF-8');
        return max(1, (int)ceil($chars / 1.5));
    }

    public static function estimateTokensFromMessages(array $messages): int
    {
        $tokens = 0;
        foreach ($messages as $message) {
            $tokens += self::estimateTokensFromText(self::messageText((array)$message));
        }
        return max(1, $tokens);
    }

    private static function buildUsage(array $history, string $output, array $providerUsage = [], array $model = [], array $extra = []): array
    {
        $inputChars = 0;
        foreach ($history as $message) {
            $inputChars += mb_strlen(self::messageText((array)$message), 'UTF-8');
        }
        $promptTokens = (int)($providerUsage['prompt_tokens'] ?? 0);
        $completionTokens = (int)($providerUsage['completion_tokens'] ?? 0);
        if ($promptTokens <= 0) {
            $promptTokens = self::estimateTokensFromMessages($history);
            $providerUsage['estimated'] = true;
        }
        if ($completionTokens <= 0) {
            $completionTokens = self::estimateTokensFromText($output);
            $providerUsage['estimated'] = true;
        }
        $totalTokens = (int)($providerUsage['total_tokens'] ?? 0);
        if ($totalTokens <= 0) {
            $totalTokens = $promptTokens + $completionTokens;
        }
        return [
            'input_chars' => $inputChars,
            'output_chars' => mb_strlen($output, 'UTF-8'),
            'message_count' => count($history),
            'prompt_tokens' => $promptTokens,
            'completion_tokens' => $completionTokens,
            'total_tokens' => $totalTokens,
            'estimated' => (bool)($providerUsage['estimated'] ?? false),
            'channel_code' => (string)($model['channel_code'] ?? ''),
            'model_code' => (string)($model['code'] ?? ''),
            'provider' => (string)($model['provider'] ?? ''),
            'provider_model' => (string)($model['model'] ?? ''),
            'billing' => $extra,
        ];
    }

    private static function estimateBilling(array $history, string $output, array $model, array $providerUsage = []): array
    {
        $usage = self::buildUsage($history, $output, $providerUsage, $model);
        $tenantCost = ((int)$usage['prompt_tokens'] * (float)($model['platform_input_unit_cost'] ?? $model['platform_unit_cost'] ?? 0) + (int)$usage['completion_tokens'] * (float)($model['platform_output_unit_cost'] ?? $model['platform_unit_cost'] ?? 0)) / 1000000;
        $userCharge = ((int)$usage['prompt_tokens'] * (float)($model['tenant_input_unit_price'] ?? $model['tenant_unit_price'] ?? 0) + (int)$usage['completion_tokens'] * (float)($model['tenant_output_unit_price'] ?? $model['tenant_unit_price'] ?? 0)) / 1000000;
        return [
            'usage' => $usage,
            'tenant_cost_points' => self::formatBillingPoints($tenantCost),
            'user_charge_points' => self::formatBillingPoints($userCharge),
            'price' => [
                'platform_input_unit_cost' => self::formatUnitPrice((float)($model['platform_input_unit_cost'] ?? $model['platform_unit_cost'] ?? 0)),
                'platform_output_unit_cost' => self::formatUnitPrice((float)($model['platform_output_unit_cost'] ?? $model['platform_unit_cost'] ?? 0)),
                'tenant_input_unit_price' => self::formatUnitPrice((float)($model['tenant_input_unit_price'] ?? $model['tenant_unit_price'] ?? 0)),
                'tenant_output_unit_price' => self::formatUnitPrice((float)($model['tenant_output_unit_price'] ?? $model['tenant_unit_price'] ?? 0)),
                'billing_unit' => 'tokens_1m',
            ],
        ];
    }

    private static function finishChatWithBilling(int $tenantId, int $userId, int $sessionId, int $messageId, string $output, string $status, string $finishReason, array $context, array $providerUsage, string $providerRequestId): array
    {
        $billing = self::estimateBilling($context['history'], $output, $context['model'], $providerUsage);
        $usage = $billing['usage'];
        $billingStatus = 'none';
        $sourceSn = (string)$messageId;
        Db::startTrans();
        try {
            if ((float)$billing['tenant_cost_points'] > 0 || (float)$billing['user_charge_points'] > 0) {
                PointService::consumeBusinessAmountsInCurrentTransaction($tenantId, $userId, (float)$billing['tenant_cost_points'], (float)$billing['user_charge_points'], $sourceSn, 'AIGC对话消费', [
                    'app_code' => self::APP_CODE,
                    'session_id' => $sessionId,
                    'message_id' => $messageId,
                    'channel_code' => (string)($context['model']['channel_code'] ?? ''),
                    'model_code' => (string)($context['model']['code'] ?? ''),
                    'prompt_tokens' => (int)$usage['prompt_tokens'],
                    'completion_tokens' => (int)$usage['completion_tokens'],
                ]);
                $billingStatus = 'deducted';
            }
            AigcLlmUsage::create([
                'tenant_id' => $tenantId,
                'user_id' => $userId,
                'session_id' => $sessionId,
                'message_id' => $messageId,
                'channel_code' => (string)($context['model']['channel_code'] ?? ''),
                'model_code' => (string)($context['model']['code'] ?? ''),
                'provider' => (string)($context['model']['provider'] ?? ''),
                'provider_model' => (string)($context['model']['model'] ?? ''),
                'provider_request_id' => $providerRequestId,
                'prompt_tokens' => (int)$usage['prompt_tokens'],
                'completion_tokens' => (int)$usage['completion_tokens'],
                'total_tokens' => (int)$usage['total_tokens'],
                'tenant_cost_points' => $billing['tenant_cost_points'],
                'user_charge_points' => $billing['user_charge_points'],
                'billing_status' => $billingStatus,
                'tenant_point_sn' => $billingStatus === 'deducted' ? $sourceSn : '',
                'user_point_sn' => $billingStatus === 'deducted' ? $sourceSn : '',
                'price_json' => $billing['price'],
                'extra_json' => [
                    'finish_reason' => $finishReason,
                ],
                'create_time' => time(),
                'update_time' => time(),
            ]);
            $usageJson = self::buildUsage($context['history'], $output, $providerUsage, $context['model'], [
                'billing_status' => $billingStatus,
                'tenant_cost_points' => $billing['tenant_cost_points'],
                'user_charge_points' => $billing['user_charge_points'],
                'provider_request_id' => $providerRequestId,
                'price' => $billing['price'],
            ]);
            self::finishAssistantMessage($tenantId, $messageId, [
                'content' => $output,
                'status' => $status,
                'finish_reason' => $finishReason,
                'token_usage_json' => $usageJson,
            ]);
            self::finishSession($sessionId, $tenantId);
            Db::commit();
            return [
                'usage' => $usageJson,
                'billing' => [
                    'billing_status' => $billingStatus,
                    'tenant_cost_points' => $billing['tenant_cost_points'],
                    'user_charge_points' => $billing['user_charge_points'],
                    'billing_unit' => 'tokens_1m',
                ],
            ];
        } catch (Throwable $e) {
            Db::rollback();
            AigcLlmUsage::create([
                'tenant_id' => $tenantId,
                'user_id' => $userId,
                'session_id' => $sessionId,
                'message_id' => $messageId,
                'channel_code' => (string)($context['model']['channel_code'] ?? ''),
                'model_code' => (string)($context['model']['code'] ?? ''),
                'provider' => (string)($context['model']['provider'] ?? ''),
                'provider_model' => (string)($context['model']['model'] ?? ''),
                'provider_request_id' => $providerRequestId,
                'prompt_tokens' => (int)$usage['prompt_tokens'],
                'completion_tokens' => (int)$usage['completion_tokens'],
                'total_tokens' => (int)$usage['total_tokens'],
                'tenant_cost_points' => $billing['tenant_cost_points'],
                'user_charge_points' => $billing['user_charge_points'],
                'billing_status' => 'deduct_failed',
                'price_json' => $billing['price'],
                'extra_json' => [
                    'finish_reason' => $finishReason,
                    'error' => $e->getMessage(),
                ],
                'create_time' => time(),
                'update_time' => time(),
            ]);
            throw $e;
        }
    }

    private static function consumeTextUsage(int $tenantId, int $userId, array $history, string $output, array $model, array $providerUsage, string $providerRequestId, array $extra = []): array
    {
        $billing = self::estimateBilling($history, $output, $model, $providerUsage);
        $usage = $billing['usage'];
        $billingStatus = 'none';
        $sourceSn = 'llm_text_' . md5($tenantId . '_' . $userId . '_' . microtime(true));
        Db::startTrans();
        try {
            if ((float)$billing['tenant_cost_points'] > 0 || (float)$billing['user_charge_points'] > 0) {
                PointService::consumeBusinessAmountsInCurrentTransaction($tenantId, $userId, (float)$billing['tenant_cost_points'], (float)$billing['user_charge_points'], $sourceSn, 'AIGC文本消费', [
                    'app_code' => (string)($extra['source_app_code'] ?? self::APP_CODE),
                    'channel_code' => (string)($model['channel_code'] ?? ''),
                    'model_code' => (string)($model['code'] ?? ''),
                    'prompt_tokens' => (int)$usage['prompt_tokens'],
                    'completion_tokens' => (int)$usage['completion_tokens'],
                    'source_type' => (string)($extra['source_type'] ?? 'text'),
                    'source_id' => (string)($extra['source_id'] ?? ''),
                ]);
                $billingStatus = 'deducted';
            }
            AigcLlmUsage::create([
                'tenant_id' => $tenantId,
                'user_id' => $userId,
                'session_id' => 0,
                'message_id' => 0,
                'channel_code' => (string)($model['channel_code'] ?? ''),
                'model_code' => (string)($model['code'] ?? ''),
                'provider' => (string)($model['provider'] ?? ''),
                'provider_model' => (string)($model['model'] ?? ''),
                'provider_request_id' => $providerRequestId,
                'prompt_tokens' => (int)$usage['prompt_tokens'],
                'completion_tokens' => (int)$usage['completion_tokens'],
                'total_tokens' => (int)$usage['total_tokens'],
                'tenant_cost_points' => $billing['tenant_cost_points'],
                'user_charge_points' => $billing['user_charge_points'],
                'billing_status' => $billingStatus,
                'tenant_point_sn' => $billingStatus === 'deducted' ? $sourceSn : '',
                'user_point_sn' => $billingStatus === 'deducted' ? $sourceSn : '',
                'price_json' => $billing['price'],
                'extra_json' => array_merge($extra, [
                    'output_chars' => mb_strlen($output, 'UTF-8'),
                ]),
                'create_time' => time(),
                'update_time' => time(),
            ]);
            Db::commit();
            return [
                'usage' => self::buildUsage($history, $output, $providerUsage, $model, [
                    'billing_status' => $billingStatus,
                    'tenant_cost_points' => $billing['tenant_cost_points'],
                    'user_charge_points' => $billing['user_charge_points'],
                    'provider_request_id' => $providerRequestId,
                    'price' => $billing['price'],
                ]),
                'billing' => [
                    'billing_status' => $billingStatus,
                    'tenant_cost_points' => $billing['tenant_cost_points'],
                    'user_charge_points' => $billing['user_charge_points'],
                    'billing_unit' => 'tokens_1m',
                ],
            ];
        } catch (Throwable $e) {
            Db::rollback();
            throw $e;
        }
    }

    private static function resolveChannelConfig(int $tenantId, string $channelCode): array
    {
        $tenantChannelConfig = AigcLlmChannel::where([
            'tenant_id' => $tenantId,
            'code' => $channelCode,
        ])->value('config_json');
        $platformChannelConfig = AigcLlmChannel::where([
            'tenant_id' => 0,
            'code' => $channelCode,
        ])->value('config_json');
        $channelConfig = array_merge(self::normalizeJson($platformChannelConfig), self::normalizeJson($tenantChannelConfig));
        if (empty($channelConfig['api_key'])) {
            $platformConfig = self::normalizeJson($platformChannelConfig);
            $channelConfig['api_key'] = (string)($platformConfig['api_key'] ?? '');
        }
        return $channelConfig;
    }

    private static function finishAssistantMessage(int $tenantId, int $messageId, array $data): void
    {
        AigcLlmMessage::where(['tenant_id' => $tenantId, 'id' => $messageId])->update([
            'content' => $data['content'],
            'status' => $data['status'],
            'finish_reason' => $data['finish_reason'],
            'token_usage_json' => $data['token_usage_json'],
            'update_time' => time(),
        ]);
    }

    private static function finishSession(int $sessionId, int $tenantId): void
    {
        AigcLlmSession::where(['tenant_id' => $tenantId, 'id' => $sessionId])->update([
            'status' => self::SESSION_IDLE,
            'last_message_at' => time(),
            'message_count' => (int)AigcLlmMessage::where(['tenant_id' => $tenantId, 'session_id' => $sessionId, 'delete_time' => 0])->count(),
            'update_time' => time(),
        ]);
    }

    private static function shouldStopStream(int $tenantId, int $messageId): bool
    {
        $status = (string)AigcLlmMessage::where(['tenant_id' => $tenantId, 'id' => $messageId])->value('status');
        return $status === self::MESSAGE_STOPPED;
    }

    private static function formatBillingPoints(float $value): string
    {
        if ($value > 0 && $value < 0.01) {
            $value = 0.01;
        }
        return number_format(max(0, $value), 2, '.', '');
    }

    private static function formatUnitPrice(float $value): string
    {
        return number_format(max(0, $value), 4, '.', '');
    }

    private static function emitEvent(string $event, array $data): void
    {
        echo 'event: ' . $event . "\n";
        echo 'data: ' . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n\n";
        @ob_flush();
        @flush();
    }

    private static function buildTextHistory(string $content, array $referenceImages = []): array
    {
        $images = self::normalizeReferenceImages($referenceImages);
        if (empty($images)) {
            return [[
                'role' => 'user',
                'content' => $content,
            ]];
        }
        $parts = [[
            'type' => 'text',
            'text' => $content,
        ]];
        foreach ($images as $image) {
            $parts[] = [
                'type' => 'image_url',
                'image_url' => ['url' => $image],
            ];
        }
        return [[
            'role' => 'user',
            'content' => $parts,
        ]];
    }

    private static function emptyTextError(string $finishReason = '', int $referenceImageCount = 0): string
    {
        if ($finishReason === 'content_filter') {
            return '供应商内容安全策略拦截，未返回文本内容';
        }
        if ($finishReason === 'length') {
            return '供应商输出长度达到上限，未返回完整文本';
        }
        if ($referenceImageCount > 0) {
            return '供应商未返回文本内容，请确认当前文本模型支持图片参考';
        }
        return '供应商未返回文本内容';
    }

    private static function normalizeReferenceImages(array $images): array
    {
        $items = [];
        foreach ($images as $image) {
            $url = trim((string)$image);
            if ($url !== '' && !in_array($url, $items, true)) {
                $items[] = $url;
            }
        }
        return array_slice($items, 0, 12);
    }

    private static function messageText(array $message): string
    {
        $content = $message['content'] ?? '';
        if (is_string($content)) {
            return $content;
        }
        if (!is_array($content)) {
            return '';
        }
        $texts = [];
        foreach ($content as $part) {
            if (!is_array($part)) {
                continue;
            }
            if (isset($part['text'])) {
                $texts[] = (string)$part['text'];
            } elseif (isset($part['image_url']['url'])) {
                $texts[] = (string)$part['image_url']['url'];
            }
        }
        return trim(implode("\n", $texts));
    }

    private static function normalizeJson($value): array
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            return is_array($decoded) ? $decoded : [];
        }
        return is_array($value) ? $value : [];
    }

    private static function lastMessageSnippet(int $tenantId, int $sessionId): string
    {
        $content = (string)AigcLlmMessage::where([
            'tenant_id' => $tenantId,
            'session_id' => $sessionId,
            'delete_time' => 0,
        ])->order(['seq' => 'desc', 'id' => 'desc'])->value('content');
        return mb_substr(trim($content), 0, 60, 'UTF-8');
    }

    private static function checkSensitiveWords(int $tenantId, string $content): void
    {
        $words = AigcLlmSensitiveWord::where(['tenant_id' => $tenantId, 'status' => 1])->column('word');
        foreach ($words as $word) {
            $word = trim((string)$word);
            if ($word !== '' && mb_stripos($content, $word, 0, 'UTF-8') !== false) {
                throw new Exception('内容包含敏感词：' . $word);
            }
        }
    }

    private static function findUserSession(int $tenantId, int $userId, int $sessionId, bool $lock = false): AigcLlmSession
    {
        $query = AigcLlmSession::where([
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'id' => $sessionId,
            'delete_time' => 0,
        ]);
        if ($lock) {
            $query->lock(true);
        }
        $session = $query->findOrEmpty();
        if ($session->isEmpty()) {
            throw new Exception('会话不存在');
        }
        return $session;
    }
}
