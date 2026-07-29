<?php

namespace app\common\service\app\aigc_canvas\agent\runtime;

use app\common\service\app\aigc_canvas\agent\tools\CanvasAgentToolRegistryService;

/** Builds a minimal, tenant-authorized contract when no domain Skill applies. */
final class GenericCapabilityContractFactory
{
    public static function create(int $tenantId, string $request, array $context): array
    {
        $capability = self::capability($request, $context);
        if ($capability === '' || !self::isActive($tenantId, $capability)) {
            return [];
        }
        $isMedia = in_array($capability, ['generate_image', 'generate_video', 'generate_music'], true);
        $missing = self::missing($capability, $request, $context);
        return [
            'skill_key' => '',
            'name' => '通用创作能力',
            'version' => 1,
            'explicit' => false,
            'generic_contract' => true,
            'capability' => $capability,
            'artifact_type' => self::artifactType($capability, $request),
            'allowed_tools' => array_values(array_unique(['ask_user', $capability])),
            'capability_tools' => [
                'read' => [],
                'plan' => $capability === 'generate_text' ? ['generate_text'] : [],
                'write' => $capability === 'canvas_mutation' ? ['canvas_mutation'] : [],
                'costly' => $isMedia ? [$capability] : [],
            ],
            'max_tool_calls' => 2,
            'max_iterations' => 3,
            'missing_slots' => $missing,
            'missing_hard_slots' => $missing,
            'inferred_slots' => [],
            'default_slots' => [],
            'default_sources' => [],
            'clarification_question' => '',
            'defaults' => [],
            'binding_policy' => ['default_mode' => 'contract'],
            'execution_confirmed' => false,
        ];
    }

    private static function capability(string $request, array $context): string
    {
        $text = mb_strtolower($request, 'UTF-8');
        if (!empty($context['selected_elements']) && preg_match('/替换|修改|编辑|移动|删除|裁剪|背景/u', $text) === 1) return 'canvas_mutation';
        if (preg_match('/视频|短片|动画|video/u', $text) === 1) return 'generate_video';
        if (preg_match('/音乐|配乐|音频|bgm|music/u', $text) === 1) return 'generate_music';
        if (preg_match('/文案|脚本|标题|总结|改写|翻译|text|copy|script/u', $text) === 1) return 'generate_text';
        if (preg_match('/生成|制作|创建|出图|画一|做一|图片|图像|海报|插画|封面|logo|image|poster/u', $text) === 1) return 'generate_image';
        return '';
    }

    private static function isActive(int $tenantId, string $capability): bool
    {
        $tool = CanvasAgentToolRegistryService::find($tenantId, $capability);
        return !empty($tool) && (int)($tool['status'] ?? 0) === 1;
    }

    private static function missing(string $capability, string $request, array $context): array
    {
        if ($capability === 'canvas_mutation' && empty($context['selected_elements']) && empty($context['selected_ids'])) return ['target'];
        if (trim($request) === '' || (in_array($capability, ['generate_image', 'generate_video', 'generate_music'], true) && !self::hasCreativeBrief($request))) {
            return ['prompt_or_brief'];
        }
        return [];
    }

    private static function hasCreativeBrief(string $request): bool
    {
        $subject = preg_replace('/生成|制作|创建|帮我|给我|一张|一个|图片|图像|海报|视频|音乐|配乐|动画|出图|画一|做一|please|generate|make|create|image|poster|video|music/iu', '', $request);
        $subject = preg_replace('/\s+/u', '', (string)$subject);
        return mb_strlen((string)$subject, 'UTF-8') >= 2;
    }

    private static function artifactType(string $capability, string $request): string
    {
        if ($capability === 'generate_image' && preg_match('/logo/u', $request) === 1) return 'logo_concept';
        return match ($capability) {
            'generate_image' => 'generic_visual',
            'generate_video' => 'video_clip',
            'generate_music' => 'audio_track',
            'generate_text' => 'text_response',
            default => 'canvas_patch',
        };
    }

}
