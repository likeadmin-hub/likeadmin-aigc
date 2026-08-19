<?php

namespace app\common\service\app\aigc_canvas\agent\tools;

use app\common\service\app\aigc_canvas\AigcCanvasService;
use app\common\service\app\aigc_canvas\agent\model\CanvasGenerationPricingService;
use app\common\service\app\aigc_canvas\agent\contracts\FunctionCallSchema;
use app\common\service\app\aigc_canvas\agent\contracts\CanvasProtocol;
use app\common\service\app\aigc_canvas\agent\contracts\ToolInterface;
use Exception;
use think\facade\Db;

final class CanvasAgentToolRegistryService
{
    /** @return ToolInterface[] */
    public static function builtinToolInstances(): array
    {
        $tools = [
            new RetrieveSkillsTool(),
            new AskUserTool(),
            new CreatePageTool(),
            new AddElementTool(),
            new UpdateElementTool(),
            new GenerateImageTool(),
            new GenerateVideoTool(),
            new CanvasCapabilityTool(CanvasProtocol::TOOL_QUERY),
            new CanvasCapabilityTool(CanvasProtocol::TOOL_MUTATION),
            new CanvasCapabilityTool(CanvasProtocol::TOOL_SELECTION_ACTION),
        ];
        foreach (self::builtinVirtualSchemas() as $code => $schema) {
            $tools[] = new RuntimeDispatchTool($code, $schema);
        }
        return $tools;
    }

    public static function toolList(int $tenantId, array $params = []): array
    {
        $tools = self::builtinToolMap();
        foreach (self::configuredTools($tenantId) as $row) {
            $code = (string)($row['tool_code'] ?? '');
            if ($code === '') {
                continue;
            }
            $tools[$code] = array_merge($tools[$code] ?? self::emptyTool($code), [
                'tool_code' => $code,
                'name' => (string)($row['name'] ?? ($tools[$code]['name'] ?? $code)),
                'description' => (string)($row['description'] ?? ($tools[$code]['description'] ?? '')),
                'status' => (int)($row['status'] ?? ($tools[$code]['status'] ?? 1)),
                'version' => (int)($row['version'] ?? ($tools[$code]['version'] ?? 1)),
                'input_schema' => self::schemaParameters(self::decodeJson($row['schema_json'] ?? []) ?: ($tools[$code]['schema'] ?? [])),
                'schema' => self::decodeJson($row['schema_json'] ?? []) ?: ($tools[$code]['schema'] ?? []),
                'source' => (int)($row['tenant_id'] ?? 0) > 0 ? 'tenant' : 'platform',
            ]);
        }

        $category = trim((string)($params['category'] ?? ''));
        $status = trim((string)($params['status'] ?? ''));
        $result = array_values(array_filter($tools, static function (array $tool) use ($category, $status): bool {
            if ($category !== '' && (string)($tool['category'] ?? '') !== $category) {
                return false;
            }
            if ($status !== '' && (int)($tool['status'] ?? 0) !== (int)$status) {
                return false;
            }
            return true;
        }));

        usort($result, static fn(array $a, array $b): int => strcmp((string)$a['tool_code'], (string)$b['tool_code']));
        return $result;
    }

    public static function schemas(int $tenantId, array $params = []): array
    {
        $codes = array_filter(array_map('trim', explode(',', (string)($params['tool_codes'] ?? $params['tool_code'] ?? ''))));
        $tools = self::toolList($tenantId);
        if (!empty($codes)) {
            $tools = array_values(array_filter($tools, static fn(array $tool): bool => in_array((string)$tool['tool_code'], $codes, true)));
        }
        return array_map(static fn(array $tool): array => [
            'tool_code' => (string)$tool['tool_code'],
            'schema' => is_array($tool['schema'] ?? null) ? $tool['schema'] : [],
            'input_schema' => is_array($tool['input_schema'] ?? null) ? $tool['input_schema'] : [],
            'output_schema' => is_array($tool['output_schema'] ?? null) ? $tool['output_schema'] : [],
        ], $tools);
    }

    public static function pricing(int $tenantId, array $params = []): array
    {
        $tools = self::toolList($tenantId, $params);
        return array_map(static function (array $tool) use ($tenantId, $params): array {
            $toolCode = (string)$tool['tool_code'];
            $pricing = is_array($tool['pricing'] ?? null) ? $tool['pricing'] : [];
            if (in_array($toolCode, ['generate_image', 'generate_video', 'generate_music'], true)) {
                $pricing = CanvasGenerationPricingService::estimate($tenantId, $toolCode, $params);
            }
            return [
                'tool_code' => $toolCode,
                'pricing' => $pricing,
                'requires_points' => !empty($tool['requires_points']),
                'async' => !empty($tool['async']),
            ];
        }, $tools);
    }

    public static function taskStatus(int $tenantId, array $params = []): array
    {
        $toolCode = trim((string)($params['tool_code'] ?? ''));
        $taskId = (int)($params['task_id'] ?? $params['provider_task_id'] ?? $params['source_task_id'] ?? 0);
        $tool = self::find($tenantId, $toolCode);
        if ($taskId <= 0) {
            return [
                'tool_code' => $toolCode,
                'task_id' => 0,
                'status' => 'unknown',
                'async' => !empty($tool['async']),
                'message' => '缺少 task_id，无法查询任务状态。',
            ];
        }

        $row = self::findRunForTask($tenantId, $toolCode, $taskId);
        if (empty($row)) {
            return [
                'tool_code' => $toolCode,
                'task_id' => $taskId,
                'status' => 'not_found',
                'async' => !empty($tool['async']),
                'message' => '未找到对应的无限画布任务记录。',
            ];
        }

        $status = (string)($row['status'] ?? '');
        $detail = [];
        if (in_array((string)($row['run_type'] ?? ''), ['image', 'video', 'music'], true) && !in_array($status, ['success', 'failed', 'canceled'], true)) {
            try {
                $detail = AigcCanvasService::runDetail($tenantId, (int)$row['id']);
                $status = (string)($detail['status'] ?? $status);
            } catch (\Throwable) {
                $detail = [];
            }
        }

        $result = $detail ?: self::decodeJson($row['result_json'] ?? []);
        $results = self::resultItems((string)($row['run_type'] ?? ''), $result);
        return [
            'tool_code' => $toolCode,
            'task_id' => $taskId,
            'run_id' => (int)($row['id'] ?? 0),
            'source_task_id' => (int)($row['source_task_id'] ?? 0),
            'status' => $status,
            'async' => !empty($tool['async']),
            'result_count' => count($results),
            'results' => $results,
            'error' => (string)($result['error'] ?? $row['error'] ?? ''),
            'message' => self::taskStatusMessage($status),
        ];
    }

    public static function find(int $tenantId, string $toolCode): array
    {
        $toolCode = trim($toolCode);
        if ($toolCode === '') {
            return [];
        }
        foreach (self::toolList($tenantId) as $tool) {
            if ((string)($tool['tool_code'] ?? '') === $toolCode) {
                return $tool;
            }
        }
        return [];
    }

    public static function validateInput(int $tenantId, string $toolCode, array $input): void
    {
        $tool = self::find($tenantId, $toolCode);
        if (empty($tool)) {
            throw new Exception('未知 Agent 工具：' . $toolCode);
        }
        if ((int)($tool['status'] ?? 0) !== 1) {
            throw new Exception('Agent 工具已停用：' . $toolCode);
        }
        $schema = is_array($tool['schema'] ?? null) ? $tool['schema'] : [];
        $parameters = is_array($schema['function']['parameters'] ?? null) ? $schema['function']['parameters'] : [];
        foreach ((array)($parameters['required'] ?? []) as $required) {
            if (!array_key_exists((string)$required, $input) || $input[(string)$required] === '') {
                throw new Exception("缺少 {$toolCode} 参数：{$required}");
            }
        }
    }

    private static function builtinToolMap(): array
    {
        $map = [];
        foreach (self::builtinToolInstances() as $tool) {
            $schema = $tool->schema();
            $map[$tool->code()] = self::fromSchema($tool->code(), $schema, [
                'source' => 'builtin',
                'async' => self::isAsyncTool($tool->code()),
                'requires_points' => self::requiresPoints($tool->code()),
                'category' => self::categoryFor($tool->code()),
            ]);
        }
        return $map;
    }

    private static function builtinVirtualSchemas(): array
    {
        return [
            'generate_text' => FunctionCallSchema::function('generate_text', 'Generate planning, copywriting, or conversational text.', [
                'prompt' => ['type' => 'string'],
                'content' => ['type' => 'string'],
                'request_id' => ['type' => 'string'],
            ]),
            'generate_music' => FunctionCallSchema::function('generate_music', 'Generate music or audio from a prompt.', [
                'prompt' => ['type' => 'string'],
                'content' => ['type' => 'string'],
                'duration' => ['type' => 'number'],
                'request_id' => ['type' => 'string'],
            ]),
            'apply_json_canvas' => FunctionCallSchema::function('apply_json_canvas', 'Apply validated JSON Canvas actions to the current project.', [
                'canvas_json' => ['type' => 'object'],
                'placement' => ['type' => 'string'],
                'requires_confirmation' => ['type' => 'boolean'],
            ], ['canvas_json']),
            'web_fetch' => FunctionCallSchema::function('web_fetch', 'Fetch readable text and metadata from a URL for agent context.', [
                'url' => ['type' => 'string'],
                'max_length' => ['type' => 'number'],
            ], ['url']),
            'search_web_info' => FunctionCallSchema::function('search_web_info', 'Search web information for a topic and return traceable source snippets.', [
                'query' => ['type' => 'string'],
                'url' => ['type' => 'string'],
                'limit' => ['type' => 'number'],
            ], ['query']),
            'search_image' => FunctionCallSchema::function('search_image', 'Search reference images for a topic and return source links.', [
                'query' => ['type' => 'string'],
                'limit' => ['type' => 'number'],
            ], ['query']),
            'brand_research' => FunctionCallSchema::function('brand_research', 'Collect brand facts, sources, and visual clues from a brand name or official URL.', [
                'query' => ['type' => 'string'],
                'url' => ['type' => 'string'],
            ]),
            'url_to_design_brief' => FunctionCallSchema::function('url_to_design_brief', 'Turn a URL into a design brief with sources for canvas generation.', [
                'url' => ['type' => 'string'],
                'max_length' => ['type' => 'number'],
            ], ['url']),
            'asset_analyze' => FunctionCallSchema::function('asset_analyze', 'Analyze uploaded or referenced assets from the current agent context.', [
                'assets' => ['type' => 'array'],
                'references' => ['type' => 'array'],
                'requires_visual_understanding' => ['type' => 'boolean'],
            ]),
            'canvas_select' => FunctionCallSchema::function('canvas_select', 'Read selected canvas elements from the current agent context.', [
                'selected_elements' => ['type' => 'array'],
                'selection' => ['type' => 'array'],
            ]),
            'canvas_read' => FunctionCallSchema::function('canvas_read', 'Read the current canvas snapshot for planning and revision.', [
                'canvas_snapshot' => ['type' => 'object'],
                'nodes' => ['type' => 'array'],
                'viewport' => ['type' => 'object'],
            ]),
            'canvas_patch' => FunctionCallSchema::function('canvas_patch', 'Return a structured canvas patch proposal for the runtime canvas writer.', [
                'patch_json' => ['type' => 'object'],
                'actions' => ['type' => 'array'],
                'requires_confirmation' => ['type' => 'boolean'],
            ]),
        ];
    }

    private static function configuredTools(int $tenantId): array
    {
        try {
            return Db::name('aigc_canvas_agent_tool_schema')
                ->whereIn('tenant_id', [0, $tenantId])
                ->where('delete_time', 0)
                ->order(['tenant_id' => 'asc', 'id' => 'asc'])
                ->select()
                ->toArray();
        } catch (Exception) {
            return [];
        }
    }

    private static function fromSchema(string $code, array $schema, array $extra = []): array
    {
        return array_merge([
            'tool_code' => $code,
            'name' => self::nameFor($code),
            'description' => (string)($schema['function']['description'] ?? ''),
            'category' => self::categoryFor($code),
            'input_schema' => self::schemaParameters($schema),
            'output_schema' => [],
            'pricing' => [],
            'status' => 1,
            'version' => 1,
            'async' => false,
            'requires_login' => true,
            'requires_points' => false,
            'schema' => $schema,
        ], $extra);
    }

    private static function emptyTool(string $code): array
    {
        return self::fromSchema($code, FunctionCallSchema::function($code, '', []), ['source' => 'configured']);
    }

    private static function schemaParameters(array $schema): array
    {
        return is_array($schema['function']['parameters'] ?? null) ? $schema['function']['parameters'] : [];
    }

    private static function decodeJson($value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (!is_string($value) || trim($value) === '') {
            return [];
        }
        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : [];
    }

    private static function findRunForTask(int $tenantId, string $toolCode, int $taskId): array
    {
        $runType = self::runTypeForTool($toolCode);
        try {
            $query = Db::name('aigc_canvas_run')
                ->where('tenant_id', $tenantId)
                ->where('delete_time', 0)
                ->where(function ($query) use ($taskId) {
                    $query->where('id', $taskId)->whereOr('source_task_id', $taskId);
                });
            if ($runType !== '') {
                $query->where('run_type', $runType);
            }
            $row = $query->order(['id' => 'desc'])->find();
            return is_array($row) ? $row : [];
        } catch (Exception) {
            return [];
        }
    }

    private static function runTypeForTool(string $toolCode): string
    {
        return [
            'generate_image' => 'image',
            'generate_video' => 'video',
            'generate_music' => 'music',
            'generate_text' => 'text',
        ][$toolCode] ?? '';
    }

    private static function resultItems(string $runType, array $result): array
    {
        $key = match ($runType) {
            'image' => 'images',
            'video' => 'videos',
            'music' => 'items',
            default => 'results',
        };
        $items = is_array($result[$key] ?? null) ? (array)$result[$key] : (array)($result['results'] ?? []);
        return array_values(array_filter($items, static fn($item): bool => is_array($item)));
    }

    private static function taskStatusMessage(string $status): string
    {
        return [
            'pending' => '任务排队中。',
            'running' => '任务生成中。',
            'success' => '任务已完成。',
            'failed' => '任务失败。',
            'canceled' => '任务已取消。',
        ][$status] ?? '任务状态待同步。';
    }

    private static function nameFor(string $code): string
    {
        return [
            'retrieve_skills' => 'Skill 检索',
            'ask_user' => '用户追问',
            'generate_text' => '文本生成',
            'generate_image' => '图片生成',
            'generate_video' => '视频生成',
            'generate_music' => '音乐生成',
            'create_page' => '创建画布页面',
            'add_element' => '添加画布元素',
            'update_element' => '更新画布元素',
            'apply_json_canvas' => '写入 JSON Canvas',
            'web_fetch' => '网页读取',
            'search_web_info' => '网页搜索',
            'search_image' => '图片搜索',
            'brand_research' => '品牌研究',
            'url_to_design_brief' => '链接生成设计简报',
            'asset_analyze' => '素材分析',
            'canvas_select' => '读取选中元素',
            'canvas_read' => '读取画布',
            'canvas_patch' => '画布补丁',
        ][$code] ?? $code;
    }

    private static function categoryFor(string $code): string
    {
        if (str_starts_with($code, 'generate_')) {
            return 'generation';
        }
        if (in_array($code, ['retrieve_skills', 'ask_user'], true)) {
            return 'agent';
        }
        if (in_array($code, ['web_fetch', 'search_web_info', 'search_image', 'brand_research', 'url_to_design_brief'], true)) {
            return 'research';
        }
        if (in_array($code, ['asset_analyze'], true)) {
            return 'asset';
        }
        return 'canvas';
    }

    private static function isAsyncTool(string $code): bool
    {
        return in_array($code, ['generate_image', 'generate_video', 'generate_music'], true);
    }

    private static function requiresPoints(string $code): bool
    {
        return in_array($code, ['generate_text', 'generate_image', 'generate_video', 'generate_music'], true);
    }
}
