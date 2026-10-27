<?php
namespace app\common\service\app\aigc_short_drama;

use RuntimeException;

/** Generation-only scene binding. Never rewrites saved projects on read. */
final class ShortDramaSceneBinding
{
    public const INSTRUCTION = '每个scene_beat和分镜都提供scene_name，必须是locations中实际拍摄地点的名称，并与scene_ref_id一致。'
        . '场景ID不能仅因有效就随意绑定；老屋、书屋、资料室是不同地点。对白提到的地点、照片中的地点不是实际拍摄地点。'
        . '一场只承载一个主要拍摄地点，跨地点移动拆为相应场次，不把出发地和目的地全部绑定同一场景。'
        . '骨架先核对剧情实际地点：必要的新地点须在locations中定义独立稳定ID和描述；用户明确限制场景时遵守限制，在允许地点安排剧情。'
        . '分镜严格沿用当前场次的实际地点，地点名称、室内外、画面空间与绑定场景一致，保留剧情、对白和人物关系。';

    public static function enabled(array $request): bool
    {
        return (int)($request['_input_contract_version'] ?? 0) >= 12;
    }

    /** Exact names/declared aliases only. No similarity or list-order fallback. */
    private static function names(array $locations): array
    {
        $names = [];
        foreach ($locations as $location) {
            $id = (string)($location['id'] ?? $location['location_id'] ?? '');
            if ($id === '') continue;
            foreach (array_merge([(string)($location['name'] ?? '')], (array)($location['aliases'] ?? [])) as $name) {
                if (!is_string($name) || trim($name) === '') continue;
                $names[trim($name)][$id] = true;
            }
        }
        return $names;
    }

    private static function placeInText(string $text, array $names, bool $expanded = false, bool $relativeAware = false): string
    {
        // Inspect the setting, never dialogue or a later mention of a destination.
        $text = trim($text);
        $text = preg_replace('/^(?:(?:雨后|次日|当天)?(?:清晨|早晨|上午|中午|下午|傍晚|夜晚|深夜|白天|晚上)[，,：:\s]*)/u', '', $text) ?? $text;
        $lead = preg_split('/[，,。；;\n]/u', $text, 2)[0] ?? '';
        if (preg_match('/[“”「」"：:]|照片|回忆|想起|提到|谈到|看向|望向|指向|准备|打算|计划|曾经/u', $lead)) return '';
        if ($relativeAware && preg_match('/没有|并未|未曾|不曾|未能|没能|尚未|并不/u', $lead)) return '';
        $candidates = array_keys($names);
        // Explicit common spatial nouns catch an undeclared location, without
        // guessing a new scene from arbitrary prose or scanning dialogue.
        $candidates = array_unique(array_merge($candidates, ['母亲老屋','老屋','客厅','卧室','厨房','书房','教室','办公室','资料室','书屋','咖啡店']));
        usort($candidates, static fn($a,$b) => mb_strlen($b) <=> mb_strlen($a));
        foreach ($candidates as $name) {
            $quoted = preg_quote($name, '/');
            $verbs = $expanded ? '走进|走到|进入|回到|来到|抵达|登临|置身于|身处|坐在|站在'
                : '走进|进入|回到|来到|置身于|身处|坐在|站在';
            if (preg_match('/^(?:在)?' . $quoted . '(?=$|[内外里中门窗书桌柜的前后旁边，,。\s])/u', $lead, $match, PREG_OFFSET_CAPTURE)
                || preg_match('/(?:' . $verbs . ')' . $quoted . '(?=$|[内外里中门窗书桌柜的前后旁边，,。\s])/u', $lead, $match, PREG_OFFSET_CAPTURE)) {
                $suffix = substr($lead, $match[0][1] + strlen($match[0][0]));
                // A reference building is not necessarily the shooting location.
                // Longer registered places (e.g. 办公室门外) are matched first.
                if ($relativeAware && preg_match('/^(?:的)?(?:门外|门口|门前|门边|门旁|窗外|外|前方|后方|旁边|附近|对面)/u', $suffix)) return '';
                return $name;
            }
        }
        return '';
    }

    private static function resolve(array $item, array $plan, bool $beat, bool $expanded = false, bool $repairBeatLabel = false, bool $relativeAware = false): array
    {
        $names = self::names((array)($plan['locations'] ?? []));
        $label = trim((string)($item['scene_name'] ?? ''));
        $text = (string)($beat ? ($item['entry'] ?? '') : ($item['visual_description'] ?? ''));
        $place = self::placeInText($text, $names, $expanded, $relativeAware);
        // A longer registered name may be shortened in ordinary narration.
        // Accept a suffix only for the explicitly selected scene, never use it
        // to select among unrelated places (e.g. 书屋 vs 老屋).
        $bound = array_column((array)($plan['locations'] ?? []), null, 'id')[(string)($item['scene_ref_id'] ?? '')] ?? [];
        $boundName = (string)($bound['name'] ?? '');
        if ($place !== '' && !isset($names[$place]) && $boundName !== '' && str_ends_with($boundName, $place)) $place = $boundName;
        if ($place !== '' && $label !== '' && $place !== $label
            && array_keys($names[$place] ?? []) !== array_keys($names[$label] ?? [])
            && !($beat && $repairBeatLabel)) {
            throw new RuntimeException('场景语义不一致：实际画面地点“'.$place.'”与scene_name“'.$label.'”不同，请核对实际拍摄地点并保留剧情', 422);
        }
        $actual = $place !== '' ? $place : $label;
        if ($actual === '') return $item;
        $ids = array_keys($names[$actual] ?? []);
        if (count($ids) !== 1) {
            throw new RuntimeException('场景语义不明确：实际地点“'.$actual.'”未唯一登记在locations中。骨架应补全真实场景或遵守用户场景限制，分镜不可误绑其他地点', 422);
        }
        $item['scene_ref_id'] = $ids[0];
        $item['scene_name'] = (string)(array_column($plan['locations'], 'name', 'id')[$ids[0]] ?? $actual);
        return $item;
    }

    public static function normalize(array $plan, array $request): array
    {
        if (!self::enabled($request) || empty($plan['locations'])) return $plan;
        $plan = ShortDramaTimedScriptGeneration::canonicalSkeleton($plan);
        $contract = (int)($request['_input_contract_version'] ?? 0);
        $plan['_scene_binding_version'] = $contract >= 17 ? 4 : ($contract >= 16 ? 3 : ($contract >= 15 ? 2 : 1));
        foreach (['scene_beats','storyboard'] as $field) {
            foreach ((array)($plan[$field] ?? []) as $i => $item) {
                if (!is_array($item)) continue;
                $plan[$field][$i] = self::resolve($item, $plan, $field === 'scene_beats', $plan['_scene_binding_version'] >= 2,
                    $field === 'scene_beats' && $plan['_scene_binding_version'] >= 3, $plan['_scene_binding_version'] >= 4);
                if ($field === 'storyboard') self::assertShot($plan[$field][$i], $plan);
            }
        }
        return $plan;
    }

    public static function assertShot(array $shot, array $plan): void
    {
        if (empty($plan['_scene_binding_version'])) return;
        $resolved = self::resolve($shot, $plan, false, (int)$plan['_scene_binding_version'] >= 2, false, (int)$plan['_scene_binding_version'] >= 4);
        if (($resolved['scene_ref_id'] ?? '') !== ($shot['scene_ref_id'] ?? '')) {
            throw new RuntimeException('分镜场景引用与实际拍摄地点不一致，应绑定“'.($resolved['scene_name'] ?? '').'”，不要改写无关剧情或对白', 422);
        }
        $space = (string)($shot['interior_exterior'] ?? '');
        $location = array_column($plan['locations'], null, 'id')[$shot['scene_ref_id'] ?? ''] ?? [];
        $expected = (string)($location['interior_exterior'] ?? '');
        if (in_array($expected, ['interior','exterior'], true) && in_array($space, ['interior','exterior'], true) && $space !== $expected) {
            throw new RuntimeException('分镜室内外与绑定场景不一致，请核对实际空间，门外镜头须使用对应外景', 422);
        }
    }

    /** Check a locked beat before paying for any of its shots. A reference to
     * another place in dialogue is not a setting change. */
    public static function assertBeat(array $beat, array $plan, array $request): void
    {
        if ((int)($request['_input_contract_version'] ?? 0) < 15) return;
        $names = self::names((array)($plan['locations'] ?? []));
        $bound = (string)($beat['scene_ref_id'] ?? '');
        foreach (array_merge([(string)($beat['entry'] ?? ''), (string)($beat['exit'] ?? '')],
            array_values(array_filter((array)($beat['key_events'] ?? []), 'is_string')),
            array_values(array_filter((array)($beat['scene_script']['action'] ?? []), 'is_string'))) as $text) {
            $place = self::placeInText($text, $names, true, (int)($request['_input_contract_version'] ?? 0) >= 17);
            if ($place === '') continue;
            $ids = array_keys($names[$place] ?? []);
            if (count($ids) !== 1) {
                throw new RuntimeException('骨架中的实际地点“' . $place . '”未唯一登记，请先修正场景定义', 422);
            }
            if ($ids[0] !== $bound) {
                throw new RuntimeException('同一场骨架跨越“' . $place . '”与已绑定地点，请在骨架中拆场或修正地点后再生成分镜', 422);
            }
        }
    }
}
