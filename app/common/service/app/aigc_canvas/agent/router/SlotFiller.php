<?php

namespace app\common\service\app\aigc_canvas\agent\router;

final class SlotFiller
{
    use RouterSupport;

    public static function fill(array $skill, string $content, array $context, array $pendingContext = [], array $routerJson = []): array
    {
        $pendingSlots = is_array($pendingContext['slots'] ?? null) ? $pendingContext['slots'] : [];
        $routerSlots = is_array($routerJson['slots'] ?? null) ? $routerJson['slots'] : [];
        $slots = array_merge($pendingSlots, $routerSlots, self::extractByRules($skill, $content, $context, $pendingContext));
        return self::normalizeForSkill($skill, $slots);
    }

    public static function missingRequiredSlots(array $skill, array $slots, array $context): array
    {
        $definitions = (array)($skill['required_slots_json'] ?? []);
        $required = self::slotKeys($definitions);
        $missing = [];
        foreach ($required as $slot) {
            if ($slot === 'reference_asset' && !empty($context['selected_elements'])) {
                continue;
            }
            $satisfiedByContext = false;
            foreach ($definitions as $definition) {
                if (!is_array($definition) || (string)($definition['key'] ?? $definition['name'] ?? '') !== $slot) {
                    continue;
                }
                foreach ((array)($definition['any_of_context'] ?? []) as $contextKey) {
                    if (!empty($context[(string)$contextKey])) {
                        $satisfiedByContext = true;
                        break 2;
                    }
                }
            }
            if ($satisfiedByContext) {
                continue;
            }
            $value = $slots[$slot] ?? null;
            if ($value === null || $value === '' || (is_array($value) && $value === [])) {
                $missing[] = $slot;
            }
        }
        return array_slice($missing, 0, 3);
    }

    public static function inferIntentFromSkill(array $skill): string
    {
        $policy = is_array($skill['tool_policy_json'] ?? null) ? $skill['tool_policy_json'] : [];
        $allowed = (array)($policy['allowed_tools'] ?? []);
        foreach (['generate_image', 'generate_video', 'generate_music', 'generate_text'] as $tool) {
            if (in_array($tool, $allowed, true)) {
                return $tool;
            }
        }
        return 'chat';
    }

    public static function toolIntentIsText(string $intent, array $skill): bool
    {
        if ($intent === 'generate_text' || $intent === 'chat') {
            return true;
        }
        $policy = is_array($skill['tool_policy_json'] ?? null) ? $skill['tool_policy_json'] : [];
        $allowed = (array)($policy['allowed_tools'] ?? []);
        return in_array('generate_text', $allowed, true) && !array_intersect($allowed, ['generate_image', 'generate_video', 'generate_music']);
    }

    private static function extractByRules(array $skill, string $content, array $context, array $pendingContext): array
    {
        $key = (string)($skill['skill_key'] ?? '');
        $slots = [];

        if (preg_match('/(\d+)\s*(张|个|份|段|秒|屏|区块)/u', $content, $match)) {
            $number = (int)$match[1];
            if ($number > 0 && $number <= 50) {
                if (($match[2] ?? '') === '秒') {
                    $slots['duration'] = $number;
                } else {
                    $slots['quantity'] = $number;
                    if (in_array($key, ['ecommerce_detail_page'], true)) {
                        $slots['section_count'] = $number;
                    }
                }
            }
        } elseif (self::containsAny($content, ['两张', '两份', '两个'])) {
            $slots['quantity'] = 2;
        } elseif (self::containsAny($content, ['三张', '三份', '三个'])) {
            $slots['quantity'] = 3;
        }

        if (self::containsAny($content, ['淘宝', 'taobao'])) {
            $slots['platform'] = 'taobao';
        } elseif (self::containsAny($content, ['京东', 'jd'])) {
            $slots['platform'] = 'jd';
        } elseif (self::containsAny($content, ['天猫', 'tmall'])) {
            $slots['platform'] = 'tmall';
        } elseif (self::containsAny($content, ['小红书', 'rednote', 'xiaohongshu'])) {
            $slots['platform'] = 'xiaohongshu';
        } elseif (self::containsAny($content, ['亚马逊', 'amazon'])) {
            $slots['platform'] = 'amazon';
        }

        if (preg_match('/(\d+)\s*:\s*(\d+)/', $content, $match)) {
            $slots['ratio'] = $match[1] . ':' . $match[2];
        } elseif (self::containsAny($content, ['横版'])) {
            $slots['ratio'] = '16:9';
        } elseif (self::containsAny($content, ['竖版', '手机长图'])) {
            $slots['ratio'] = '9:16';
        }

        if (self::containsAny($content, ['镜头慢慢推进', '慢慢推进', '推近', 'push in'])) {
            $slots['camera_motion'] = 'push_in';
        }
        if (self::containsAny($content, ['详情图', '详情页'])) {
            $slots['image_type'] = 'detail';
        } elseif (self::containsAny($content, ['主图'])) {
            $slots['image_type'] = 'main';
        } elseif (self::containsAny($content, ['海报'])) {
            $slots['image_type'] = 'poster';
        }
        if (!empty($context['selected_elements'])) {
            $slots['reference_asset'] = 'selected_canvas_element';
        }

        if ($key === 'ecommerce_detail_page') {
            $productInfo = self::extractProductInfo($content);
            if ($productInfo !== '') {
                $slots['product_info'] = $productInfo;
            }
            if (preg_match('/(?:卖点|主打|优势|特点|突出)[是为：:\s]*([^。！？\n]+)/u', $content, $match)) {
                $points = array_values(array_filter(array_map('trim', preg_split('/[，、,;；和]+/u', trim($match[1])) ?: [])));
                if ($points !== []) {
                    $slots['selling_points'] = $points;
                }
            } elseif (self::containsAny($content, ['降噪', '续航', '轻便', '防水', '保湿', '美白', '透气', '耐磨', '快充', '高颜值', '清凉'])) {
                $slots['selling_points'] = $content;
            }
        }
        if ($key === 'general_image' && self::looksLikeConcreteSubject($content, ['生成', '帮我', '做', '一张', '两张', '图片', '图像', '插画'])) {
            $slots['visual_subject'] = $content;
        }
        if ($key === 'poster_design' && self::looksLikeConcreteSubject($content, ['生成', '帮我', '做', '一张', '海报', '活动海报', '招生海报', '促销海报'])) {
            $slots['theme'] = $content;
        }
        if ($key === 'video_generation' && (self::looksLikeConcreteSubject($content, ['生成', '帮我', '做', '视频', '短片', '动画']) || !empty($context['selected_elements']))) {
            $slots['video_subject'] = $content;
        }
        if ($key === 'music_generation' && self::looksLikeConcreteSubject($content, ['生成', '帮我', '做', '音乐', '音频', '配乐', '歌曲'])) {
            $slots['music_subject'] = $content;
        }
        if (!empty($pendingContext['missing_slots']) && count($pendingContext['missing_slots']) === 1) {
            $missing = (string)$pendingContext['missing_slots'][0];
            if ($missing !== '' && !isset($slots[$missing]) && trim($content) !== '') {
                $slots[$missing] = $content;
            }
        }
        return $slots;
    }

    private static function normalizeForSkill(array $skill, array $slots): array
    {
        if ((string)($skill['skill_key'] ?? '') !== 'ecommerce_detail_page') {
            return $slots;
        }
        $productInfo = trim((string)($slots['product_info'] ?? ''));
        if ($productInfo !== '' && self::isGenericEcommerceImageRequest($productInfo)) {
            unset($slots['product_info']);
        }
        return $slots;
    }

    private static function extractProductInfo(string $content): string
    {
        if (preg_match('/产品是([^，。！？]+)/u', $content, $match)) {
            return trim($match[1]);
        }
        foreach (['女装', '无线耳机', '保温杯', '食品', '宠物用品', '蓝牙耳机', '护肤品', '服装', '家居用品'] as $subject) {
            if (str_contains($content, $subject)) {
                return $subject;
            }
        }
        return self::isGenericEcommerceImageRequest($content) ? '' : $content;
    }

    private static function isGenericEcommerceImageRequest(string $content): bool
    {
        $text = mb_strtolower(trim($content), 'UTF-8');
        if ($text === '') {
            return true;
        }
        $text = preg_replace('/\d+\s*(张|个|份|套|屏|区块)?/u', '', $text) ?? $text;
        foreach ([
            '我想', '我要', '我需要', '帮我', '请', '生成', '做', '制作', '创建', '设计',
            '电商', '淘宝', '天猫', '京东', '小红书', '亚马逊', '详情图', '详情页', '详情长图',
            '主图', '商品图', '产品图', '卖点图', '海报', '图片', '图', '一套', '一组',
            'image', 'poster', 'product', 'ecommerce',
        ] as $word) {
            $text = str_replace($word, '', $text);
        }
        $text = preg_replace('/[，。、！？；;:,.!?\s]+/u', '', $text) ?? $text;
        return mb_strlen(trim($text), 'UTF-8') < 2;
    }

    private static function looksLikeConcreteSubject(string $content, array $genericWords): bool
    {
        $text = trim($content);
        foreach ($genericWords as $word) {
            $text = str_replace($word, '', $text);
        }
        $text = trim(preg_replace('/[，。、！？；;:,.!?\s\d一二两三四五六七八九十个张份段秒]+/u', '', $text) ?? '');
        return mb_strlen($text, 'UTF-8') >= 2;
    }
}
