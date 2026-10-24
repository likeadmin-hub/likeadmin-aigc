<?php
namespace app\common\service\power;

use InvalidArgumentException;

/** Interprets the reviewed upstream material-combination rules before any point reservation. */
final class MarketGenerationRules
{
    private static function present($value): bool { return $value !== null && $value !== '' && $value !== []; }
    private static function count($value): int { return self::present($value) ? (is_array($value) ? count($value) : 1) : 0; }
    public static function validate(array $rules, array $input): void
    {
        foreach ($rules as $rule) {
            $active = true;
            foreach ($rule['when'] ?? [] as $key=>$value) if (($input[$key] ?? null) !== $value) $active = false;
            foreach ($rule['when_present'] ?? [] as $key) if (!self::present($input[$key] ?? null)) $active = false;
            foreach ($rule['when_absent'] ?? [] as $key) if (self::present($input[$key] ?? null)) $active = false;
            if (!$active) continue;
            $count = isset($rule['field']) ? self::count($input[$rule['field']] ?? null) : 0;
            $any = static fn(array $keys): bool => (bool)array_filter($keys, static fn($key)=>self::present($input[$key] ?? null));
            $bad = false;
            switch ($rule['kind']) {
                case 'requires': $bad=self::present($input[$rule['field']] ?? null) && !self::present($input[$rule['required']] ?? null); break;
                case 'exclusive': $bad=$any($rule['left']) && $any($rule['right']); break;
                case 'exactly_one': $bad=count(array_filter($rule['fields'], static fn($key)=>self::present($input[$key] ?? null))) !== 1; break;
                case 'at_least_one': $bad=!$any($rule['fields']); break;
                case 'max': $bad=$count > $rule['maximum']; break;
                case 'range': $bad=$count < $rule['minimum'] || $count > $rule['maximum']; break;
                case 'equals': $bad=array_key_exists($rule['field'],$input) && $input[$rule['field']] != $rule['value']; break;
                case 'forbid_value': $bad=($input[$rule['field']] ?? null) === $rule['value']; break;
                case 'sum': $total=0; foreach ($rule['fields'] as $key=>$weight) $total+=self::count($input[$key] ?? null)*$weight; $bad=$total>$rule['maximum']; break;
                case 'clip_range': foreach ($input[$rule['field']] ?? [] as $clip) if (isset($clip[$rule['start']],$clip[$rule['end']]) && ($clip[$rule['end']] <= $clip[$rule['start']] || $clip[$rule['end']]-$clip[$rule['start']]>$rule['maximum'])) $bad=true; break;
                case 'loop_frame': if (!empty($input['loop_url'])) foreach (['first_frame_url','last_frame_url'] as $field) if (!empty($input[$field]) && $input[$field] !== $input['loop_url']) $bad=true; break;
                case 'keyframes': $times=[]; foreach ($input['keyframes'] ?? [] as $frame) if (isset($frame['timestamp_s'])) { $time=$frame['timestamp_s']; if ($time<=0 || $time>=($input['duration'] ?? 8)) $bad=true; $times[]=$time; } sort($times); for ($i=1;$i<count($times);$i++) if ($times[$i]-$times[$i-1]<1/3) $bad=true; break;
                case 'kling3_shots': $bad=(!empty($input['customize_multi_shots']) && !empty($input['prefer_multi_shots'])) || (!empty($input['customize_multi_shots']) === empty($input['multi_prompt'])) || array_sum(array_column($input['multi_prompt']??[],'duration'))>15; break;
                case 'kling4': $bad=!MarketGenerationKlingRules::valid($rule['check'],$input); break;
                case 'oct24_urls':
                    foreach (array_merge($input['image_urls'] ?? [],isset($input['audio_url']) ? [$input['audio_url']] : []) as $url) { $parts=is_string($url) ? parse_url($url) : false; if (!$parts || !in_array(strtolower($parts['scheme'] ?? ''),['http','https'],true) || isset($parts['user']) || isset($parts['pass'])) $bad=true; }
                    if (isset($input['prompt']) && trim($input['prompt']) === '') $bad=true; break;
                // These two upstream rules require measured MIME/media facts. They are checked by the supplier before accepting a paid task.
                case 'subjects': case 'subject_quota': break;
                default: throw new InvalidArgumentException('当前模型的素材合同需要更新，请先同步模型');
            }
            if ($bad) throw new InvalidArgumentException((string)$rule['message']);
        }
    }
}
