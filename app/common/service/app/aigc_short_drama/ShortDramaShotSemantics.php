<?php
namespace app\common\service\app\aigc_short_drama;

/** Shared interpretation for generation, editing and downstream audio prompts. */
final class ShortDramaShotSemantics
{
    public const INSTRUCTION = 'subjects必须声明category=character、animal、prop或symbol（人物是character，信件、书、海报等物品是prop），同一id类型不随集数改变。每镜必须声明interior_exterior=interior或exterior，与实际拍摄地点一致。dialogue只放实际可朗读的原话，不放表演说明、（无声）或角色说话的叙述。静默用dialogue="",speech_type=none,voice_role=""。多人对白每人独占一行，以实际角色名加中文冒号开头，不把姓名写成对白中的称呼；voice_role为第一位说话者。subject_ref_ids包含画面中实际可见的全部已登记主体。';

    public static function silent(string $text): bool
    {
        return trim($text) === '' || preg_match('/^[（(【\[]?\s*(?:无声|无对白|无台词|静默|无|silence|silent|none)\s*[）)】\]]?[。.]?$/iu', trim($text)) === 1;
    }

    /** Explicit attribution only. Preserve words; never guess from visible people. */
    public static function turns(array $shot): array
    {
        $text = trim((string)($shot['dialogue'] ?? ''));
        if (self::silent($text)) return [];
        if (preg_match_all('/(?:^|[\n。！？；])\s*([^：:\n。！？；“”「」]{1,16}?)(?:说|笑着答|回答|答道|说道)?\s*[：:]\s*/u', $text, $m, PREG_OFFSET_CAPTURE)) {
            $out = [];
            // A prefix inside an unlabelled sentence is not a turn boundary.
            if ($m[0][0][1] !== 0) return [['speaker'=>(string)($shot['voice_role'] ?? ''), 'text'=>$text]];
            foreach ($m[0] as $i => $prefix) {
                $start = $prefix[1] + strlen($prefix[0]);
                $end = isset($m[0][$i + 1]) ? $m[0][$i + 1][1] : strlen($text);
                // Sentence punctuation at the next boundary belongs to this turn.
                if (isset($m[0][$i + 1]) && preg_match('/^[。！？；]/u', $m[0][$i + 1][0], $punct)) $end += strlen($punct[0]);
                $out[] = ['speaker'=>trim($m[1][$i][0]), 'text'=>trim(substr($text, $start, $end - $start))];
            }
            return $out;
        }
        return [['speaker'=>(string)($shot['voice_role'] ?? ''), 'text'=>$text]];
    }

    public static function space(array $shot, array $locations = []): string
    {
        if (in_array($shot['interior_exterior'] ?? '', ['interior','exterior'], true)) return $shot['interior_exterior'];
        $location = array_column($locations, null, 'id')[$shot['scene_ref_id'] ?? ''] ?? [];
        $text = (string)($location['name'] ?? '') . ' ' . (string)($shot['scene_name'] ?? '');
        if (preg_match('/屋内|室内|客厅|卧室|厨房|教室|办公室|inside|interior/iu', $text)) return 'interior';
        if (preg_match('/^(?:清晨[，, ]*)?(?:屋内|室内|客厅)/u', (string)($shot['visual_description'] ?? ''))) return 'interior';
        return 'exterior';
    }

    public static function normalize(array $plan, array $locked = []): array
    {
        $types = array_column((array)($locked['subjects'] ?? []), 'category', 'id');
        foreach ($plan['subjects'] ?? [] as $i => $subject) {
            if (in_array($types[$subject['id'] ?? ''] ?? '', ['character','animal','prop','symbol'], true)) $plan['subjects'][$i]['category'] = $types[$subject['id']];
        }
        foreach ($plan['storyboard'] ?? [] as $i => $shot) {
            // Two independent literal cues can correct a legacy default. A
            // transition shot keeps its explicit setting when the cues differ.
            $location = array_column((array)($plan['locations'] ?? []), null, 'id')[$shot['scene_ref_id'] ?? ''] ?? [];
            if (preg_match('/屋内|室内|客厅|卧室|厨房|教室|办公室/u', (string)($location['name'] ?? ''))
                && (preg_match('/^(?:清晨[，, ]*)?(?:屋内|室内|客厅|卧室|厨房|教室|办公室)/u', (string)($shot['visual_description'] ?? ''))
                    || preg_match('/^'.preg_quote((string)$location['name'],'/').'[，,。\s]/u',(string)($shot['visual_description']??'')))) $shot['interior_exterior'] = 'interior';
            $shot['interior_exterior'] = self::space($shot, (array)($plan['locations'] ?? []));
            if (self::silent((string)($shot['dialogue'] ?? ''))) {
                $shot['dialogue'] = ''; $shot['speech_type'] = 'none'; $shot['voice_role'] = '';
            }
            $plan['storyboard'][$i] = $shot;
        }
        return $plan;
    }
}
