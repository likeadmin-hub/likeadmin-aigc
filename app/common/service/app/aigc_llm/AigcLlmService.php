<?php

namespace app\common\service\app\aigc_llm;

use app\common\model\app\aigc_llm\AigcLlmConfig;
use app\common\model\app\aigc_llm\AigcLlmMessage;
use app\common\model\app\aigc_llm\AigcLlmSensitiveWord;
use app\common\model\app\aigc_llm\AigcLlmSession;
use app\common\model\app\aigc_llm\AigcLlmUsage;
use app\common\service\app\AppDisplayConfigService;
use app\common\service\power\MarketTextModelRuntimeService;
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
            return AppDisplayConfigService::appendToConfig($tenantId, self::APP_CODE, array_merge($default, [
                'tenant_id' => $tenantId,
                'option_config' => AigcLlmChannelService::userConfig($tenantId),
            ]));
        }
        $data = array_merge($default, $config->toArray());
        unset($data['provider_mode'], $data['provider'], $data['model']);
        $data['config_json'] = array_merge($default['config_json'], (array)($data['config_json'] ?? []));
        $data['option_config'] = AigcLlmChannelService::userConfig($tenantId);
        return AppDisplayConfigService::appendToConfig($tenantId, self::APP_CODE, $data);
    }

    public static function saveConfig(int $tenantId, array $params): void
    {
        AppDisplayConfigService::saveFromConfigPayload($tenantId, self::APP_CODE, $params);
        $current = self::config($tenantId);
        $configJson = array_merge((array)($current['config_json'] ?? []), self::normalizeJson($params['config_json'] ?? []));
        $data = [
            'tenant_id' => $tenantId,
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
        $model = self::resolveMarketChatModel($tenantId, $params);
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
            $history = array_values(array_filter($history, fn(array $row) => self::isContextMessage($row, (int)$assistantMessage['id'])));
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

        $output = '';
        $stopped = false;
        try {
            $result = MarketTextModelRuntimeService::generate($tenantId, $userId, [
                'app_code' => self::APP_CODE,
                'source_app_code' => self::APP_CODE,
                'business_table' => 'aigc_llm_message',
                'business_id' => (int)$assistantMessage['id'],
                'action_code' => 'chat',
                'content' => $content,
                'messages' => array_map(static fn(array $row): array => [
                    'role' => (string)$row['role'],
                    'content' => (string)$row['content'],
                ], $context['history']),
                'system_prompt' => (string)($context['config']['config_json']['system_prompt'] ?? ''),
                'model_selection' => ['id' => (string)($context['model']['market_product_id'] ?? '')],
            ], function (string $event, array $data) use ($tenantId, $assistantMessage, &$stopped): void {
                if ($event !== 'delta') {
                    return;
                }
                if (self::shouldStopStream($tenantId, (int)$assistantMessage['id'])) {
                    $stopped = true;
                    return;
                }
                $delta = (string)($data['delta'] ?? '');
                if ($delta !== '') {
                    self::emitEvent('delta', ['message_id' => (int)$assistantMessage['id'], 'delta' => $delta]);
                }
            });
            $output = (string)($result['content'] ?? '');
            $finishReason = $stopped ? 'stopped' : 'stop';
            $billing = self::finishChatWithMarketBilling($tenantId, $userId, (int)$session['id'], (int)$assistantMessage['id'], $output, $stopped ? self::MESSAGE_STOPPED : self::MESSAGE_DONE, $finishReason, $context['model'], $result);
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
                'token_usage_json' => [
                    'billing_status' => 'none',
                    'provider' => 'power_market',
                    'error' => $e->getMessage(),
                ],
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
        return self::generateMarketText($tenantId, $userId, $params, $config);
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
        return self::generateMarketText($tenantId, $userId, $params, $config, $onEvent);
    }

    /** @return array<string, mixed> */
    private static function resolveMarketChatModel(int $tenantId, array $params): array
    {
        $selection = trim((string)($params['model_code'] ?? $params['model'] ?? ''));
        $model = MarketTextModelRuntimeService::resolveModel($tenantId, $selection);
        return [
            'code' => 'market_text_' . (int)$model['product_id'],
            'market_product_id' => (int)$model['product_id'],
            'channel_code' => (string)$model['channel_code'],
            'model' => (string)$model['model_code'],
            'provider' => 'power_market',
        ];
    }

    /** @return array<string, mixed> */
    private static function generateMarketText(int $tenantId, int $userId, array $params, array $config, ?callable $onEvent = null): array
    {
        $content = trim((string)($params['content'] ?? $params['prompt'] ?? ''));
        $systemPrompt = trim((string)($params['system_prompt'] ?? ''));
        $params['content'] = $content;
        $params['system_prompt'] = $systemPrompt !== '' ? $systemPrompt : (string)($config['config_json']['system_prompt'] ?? '');
        $params['reference_images'] = self::normalizeReferenceImages((array)($params['reference_images'] ?? $params['image_urls'] ?? []));
        $params['model_selection'] = trim((string)($params['model_code'] ?? $params['model'] ?? ''));
        $params['source_app_code'] = (string)($params['source_app_code'] ?? self::APP_CODE);
        $params['app_code'] = (string)($params['app_code'] ?? $params['source_app_code']);
        return MarketTextModelRuntimeService::generate($tenantId, $userId, $params, $onEvent);
    }

    /** @return array{usage:array<string,mixed>, billing:array<string,mixed>} */
    private static function finishChatWithMarketBilling(int $tenantId, int $userId, int $sessionId, int $messageId, string $output, string $status, string $finishReason, array $model, array $result): array
    {
        $usage = (array)($result['usage'] ?? []);
        $billing = (array)($result['billing'] ?? []);
        $usageJson = [
            'prompt_tokens' => (int)($usage['prompt_tokens'] ?? 0),
            'completion_tokens' => (int)($usage['completion_tokens'] ?? 0),
            'total_tokens' => (int)($usage['total_tokens'] ?? 0),
            'provider_reported' => !empty($usage['provider_reported']),
            'channel_code' => (string)($result['channel_code'] ?? $model['channel_code'] ?? ''),
            'model_code' => (string)($model['code'] ?? ''),
            'provider' => 'power_market',
            'provider_model' => (string)($result['model_code'] ?? $model['model'] ?? ''),
            'billing' => $billing,
            'market_product_id' => (int)($model['market_product_id'] ?? 0),
            'app_task_id' => (int)($result['app_task_id'] ?? 0),
            'consumption_id' => (int)($result['consumption_id'] ?? 0),
        ];
        Db::transaction(function () use ($tenantId, $userId, $sessionId, $messageId, $output, $status, $finishReason, $model, $result, $usage, $billing, $usageJson): void {
            AigcLlmUsage::create([
                'tenant_id' => $tenantId,
                'user_id' => $userId,
                'session_id' => $sessionId,
                'message_id' => $messageId,
                'channel_code' => (string)($result['channel_code'] ?? $model['channel_code'] ?? ''),
                'model_code' => (string)($model['code'] ?? ''),
                'provider' => 'power_market',
                'provider_model' => (string)($result['model_code'] ?? $model['model'] ?? ''),
                'provider_request_id' => (string)($result['provider_request_id'] ?? ''),
                'prompt_tokens' => (int)($usage['prompt_tokens'] ?? 0),
                'completion_tokens' => (int)($usage['completion_tokens'] ?? 0),
                'total_tokens' => (int)($usage['total_tokens'] ?? 0),
                'tenant_cost_points' => (float)($billing['tenant_cost_points'] ?? 0),
                'user_charge_points' => (float)($billing['user_charge_points'] ?? 0),
                'billing_status' => (string)($billing['billing_status'] ?? 'pending_usage'),
                'tenant_point_sn' => '',
                'user_point_sn' => '',
                'price_json' => ['price_source' => 'power_market_text_model', 'market_product_id' => (int)($model['market_product_id'] ?? 0)],
                'extra_json' => ['finish_reason' => $finishReason, 'app_task_id' => (int)($result['app_task_id'] ?? 0), 'consumption_id' => (int)($result['consumption_id'] ?? 0)],
                'create_time' => time(),
                'update_time' => time(),
            ]);
            self::finishAssistantMessage($tenantId, $messageId, [
                'content' => $output,
                'status' => $status,
                'finish_reason' => $finishReason,
                'token_usage_json' => $usageJson,
            ]);
            self::finishSession($sessionId, $tenantId);
        });
        return [
            'usage' => $usageJson,
            'billing' => [
                'billing_status' => (string)($billing['billing_status'] ?? 'pending_usage'),
                'tenant_cost_points' => (float)($billing['tenant_cost_points'] ?? 0),
                'user_charge_points' => (float)($billing['user_charge_points'] ?? 0),
                'billing_unit' => 'token',
            ],
        ];
    }

    private static function defaultConfig(): array
    {
        return [
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

    private static function isContextMessage(array $row, int $currentAssistantMessageId = 0): bool
    {
        $role = (string)($row['role'] ?? '');
        if (!in_array($role, ['user', 'assistant'], true)) {
            return false;
        }
        if ($role === 'assistant' && $currentAssistantMessageId > 0 && (int)($row['id'] ?? 0) === $currentAssistantMessageId) {
            return false;
        }
        if ($role === 'assistant') {
            $status = (string)($row['status'] ?? '');
            if (in_array($status, [self::MESSAGE_ERROR, self::MESSAGE_STREAMING], true)) {
                return false;
            }
            if (trim((string)($row['content'] ?? '')) === '') {
                return false;
            }
        }
        return true;
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
