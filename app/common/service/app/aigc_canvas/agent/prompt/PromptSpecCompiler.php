<?php

namespace app\common\service\app\aigc_canvas\agent\prompt;

/** The only compiler allowed to create final provider prompts for image tasks. */
final class PromptSpecCompiler
{
    public const VERSION = 'prompt-spec-v2';

    /** @deprecated Use compilePlanned() for planned deliveries. */
    public static function compile(array $creativeContext, array $section, string $deliveryType, array $modelOptions = []): array
    {
        return self::compilePlanned($creativeContext, $section, $deliveryType, $modelOptions);
    }

    public static function compileDirect(string $userRequest, array $modelOptions = []): array
    {
        $legacy = self::normalizeLegacyIntent($userRequest);
        $userRequest = $legacy['intent'];
        if (trim((string)($modelOptions['ratio'] ?? $modelOptions['size'] ?? '')) === '' && $legacy['ratio'] !== '') {
            $modelOptions['ratio'] = $legacy['ratio'];
        }
        return self::compileSpec([
            'mode' => 'direct',
            'language' => self::language($modelOptions),
            'user_request' => self::text($userRequest, 2400),
            'delivery_type' => self::text((string)($modelOptions['delivery_type'] ?? 'image'), 80),
            'purpose' => '',
            'narrative' => '',
            'ratio' => self::ratio($modelOptions),
            'product_identity' => self::array($modelOptions['product_identity'] ?? []),
            'evidence' => [],
            'claims' => [],
            'copy_plan' => [],
            'visual_direction' => self::stringList($modelOptions['visual_direction'] ?? []),
            'constraints' => self::stringList($modelOptions['constraints'] ?? []),
            'model_options' => self::safeModelOptions($modelOptions),
        ]);
    }

    public static function compilePlanned(array $creativeContext, array $section, string $deliveryType, array $modelOptions = []): array
    {
        $evidence = self::indexEvidence((array)($creativeContext['evidence_catalog'] ?? []));
        $evidenceIds = array_values(array_unique(array_filter(array_map('strval', (array)($section['evidence_ids'] ?? [])))));
        $selectedEvidence = array_values(array_intersect_key($evidence, array_fill_keys($evidenceIds, true)));
        $claims = self::approvedClaims((array)($creativeContext['claim_policy']['claims'] ?? []), $evidenceIds);
        $unverified = self::unverifiedClaims((array)($creativeContext['claim_policy']['claims'] ?? []));
        return self::compileSpec([
            'mode' => 'planned',
            'language' => self::language($modelOptions, $creativeContext),
            'user_request' => self::text((string)($section['user_request'] ?? ''), 2400),
            'delivery_type' => self::text($deliveryType, 80),
            'purpose' => self::text((string)($section['purpose'] ?? $section['title'] ?? ''), 80),
            'narrative' => self::text((string)($section['narrative'] ?? ''), 500),
            'ratio' => self::ratio($modelOptions, $section),
            'product_identity' => self::array($creativeContext['product_identity'] ?? []),
            'evidence' => $selectedEvidence,
            'claims' => $claims,
            'unverified_parameters' => $unverified,
            'copy_plan' => self::safeCopy(self::array($section['copy_content'] ?? [])),
            'visual_direction' => self::stringList(array_merge(
                self::array($creativeContext['global_visual_system'] ?? []),
                self::array($section['visual_direction'] ?? [])
            )),
            'constraints' => self::stringList($section['constraints'] ?? []),
            'model_options' => self::safeModelOptions($modelOptions),
        ]);
    }

    public static function compileEdit(string $userRequest, array $originalIdentity, array $modelOptions = []): array
    {
        return self::compileSpec([
            'mode' => 'edit',
            'language' => self::language($modelOptions),
            'user_request' => self::text($userRequest, 2400),
            'delivery_type' => self::text((string)($modelOptions['delivery_type'] ?? 'image_edit'), 80),
            'purpose' => self::text((string)($modelOptions['edit_action'] ?? '图片编辑'), 80),
            'narrative' => '',
            'ratio' => self::ratio($modelOptions),
            'product_identity' => $originalIdentity,
            'evidence' => [],
            'claims' => [],
            'copy_plan' => [],
            'visual_direction' => self::stringList($modelOptions['visual_direction'] ?? []),
            'constraints' => array_merge(['保持原图主体身份，不替换或改造主体。'], self::stringList($modelOptions['constraints'] ?? [])),
            'model_options' => self::safeModelOptions($modelOptions),
        ]);
    }

    /** Preserve an already audited request for retry; it must never be recompiled. */
    public static function reuseSnapshot(array $snapshot): array
    {
        self::assertSubmission($snapshot);
        $spec = self::array($snapshot['prompt_spec_json'] ?? []);
        return [
            'creative_spec_json' => self::array($snapshot['creative_spec_json'] ?? []),
            'prompt_spec_json' => $spec,
            'compiled_prompt' => (string)$snapshot['prompt'],
            'compiler_version' => self::VERSION,
            'evidence_ids' => array_values(array_filter(array_map('strval', (array)($snapshot['evidence_ids'] ?? $spec['evidence_ids'] ?? [])))),
            'claim_ids' => array_values(array_filter(array_map('strval', (array)($snapshot['claim_ids'] ?? $spec['claim_ids'] ?? [])))),
            'prompt_hash' => (string)$snapshot['prompt_hash'],
        ];
    }

    /** @deprecated Kept for old callers; ad-hoc work intentionally has no inherited evidence. */
    public static function compileAdHoc(array $creativeContext, string $intent, string $deliveryType, array $modelOptions = []): array
    {
        return self::compileDirect($intent, array_merge($modelOptions, ['delivery_type' => $deliveryType]));
    }

    public static function assertSubmission(array $input): void
    {
        $prompt = trim((string)($input['prompt'] ?? ''));
        $spec = self::array($input['prompt_spec_json'] ?? []);
        if ($prompt === '' || $spec === [] || (string)($input['compiler_version'] ?? '') !== self::VERSION) {
            throw new \InvalidArgumentException('图片任务必须通过统一 Prompt 编译器提交。');
        }
        if (!hash_equals((string)($input['prompt_hash'] ?? ''), hash('sha256', $prompt))) {
            throw new \InvalidArgumentException('最终提示词校验失败，请重新提交任务。');
        }
    }

    private static function compileSpec(array $spec): array
    {
        $spec = PromptPolicyValidator::validate($spec);
        $compiled = self::render($spec);
        return [
            'creative_spec_json' => [
                'product_identity' => (array)($spec['product_identity'] ?? []),
                'evidence_catalog' => (array)($spec['evidence'] ?? []),
                'claim_policy' => ['claims' => (array)($spec['claims'] ?? [])],
                'unverified_parameters' => (array)($spec['unverified_parameters'] ?? []),
            ],
            'prompt_spec_json' => $spec,
            'compiled_prompt' => $compiled,
            'compiler_version' => self::VERSION,
            'evidence_ids' => (array)($spec['evidence_ids'] ?? []),
            'claim_ids' => (array)($spec['claim_ids'] ?? []),
            'prompt_hash' => hash('sha256', $compiled),
        ];
    }

    private static function render(array $spec): string
    {
        if ((string)($spec['language'] ?? 'zh-CN') === 'en-US') {
            return self::renderEnglish($spec);
        }
        $parts = [];
        $task = self::join([trim((string)($spec['purpose'] ?? '')), trim((string)($spec['user_request'] ?? ''))], '，');
        if ($task !== '') $parts[] = self::sentence($task . self::ratioText((string)($spec['ratio'] ?? '')));
        $identity = self::identityText((array)($spec['product_identity'] ?? []));
        $facts = self::evidenceText((array)($spec['evidence'] ?? []));
        if ($identity !== '' || $facts !== '') $parts[] = self::sentence(self::join([$identity, $facts], '；'));
        $claims = self::claimsText((array)($spec['claims'] ?? []));
        $copy = self::copyText((array)($spec['copy_plan'] ?? []));
        if ($claims !== '' || $copy !== '') $parts[] = self::sentence(self::join([$claims, $copy], '；'));
        $visual = self::join(array_merge(
            self::stringList($spec['visual_direction'] ?? []),
            [trim((string)($spec['narrative'] ?? ''))]
        ), '，');
        if ($visual !== '') $parts[] = self::sentence($visual);
        $constraints = self::join((array)($spec['constraints'] ?? []), '；');
        if ($constraints !== '') $parts[] = self::sentence($constraints);
        return self::clean(implode("\n\n", $parts));
    }

    private static function renderEnglish(array $spec): string
    {
        $parts = [];
        $task = self::join([trim((string)($spec['purpose'] ?? '')), trim((string)($spec['user_request'] ?? ''))], '. ');
        if ($task !== '') $parts[] = trim($task . self::ratioText((string)($spec['ratio'] ?? ''), 'en-US')) . '.';
        $facts = self::evidenceText((array)($spec['evidence'] ?? []));
        if ($facts !== '') $parts[] = 'Preserve: ' . $facts . '.';
        $visual = self::join(array_merge(self::stringList($spec['visual_direction'] ?? []), [trim((string)($spec['narrative'] ?? ''))]), ', ');
        if ($visual !== '') $parts[] = $visual . '.';
        $constraints = self::join((array)($spec['constraints'] ?? []), '; ');
        if ($constraints !== '') $parts[] = $constraints . '.';
        return self::clean(implode("\n\n", $parts));
    }

    private static function approvedClaims(array $claims, array $evidenceIds): array
    {
        return array_values(array_filter($claims, static function ($claim) use ($evidenceIds): bool {
            if (!is_array($claim) || (string)($claim['status'] ?? '') !== 'approved_claim') return false;
            $ids = array_values(array_filter(array_map('strval', (array)($claim['evidence_ids'] ?? []))));
            return ((string)($claim['source'] ?? '') === 'verified' && !empty($claim['verification_record']))
                || ($ids !== [] && array_intersect($ids, $evidenceIds) !== []);
        }));
    }

    private static function unverifiedClaims(array $claims): array
    {
        $items = [];
        foreach ($claims as $claim) {
            if (!is_array($claim) || (string)($claim['status'] ?? '') !== 'needs_verification') continue;
            $text = trim((string)($claim['text'] ?? ''));
            if ($text === '') continue;
            $items[(string)($claim['claim_id'] ?? sha1($text))] = [
                'claim_id' => (string)($claim['claim_id'] ?? ''),
                'text' => $text,
                'status' => 'needs_verification',
            ];
        }
        return array_values($items);
    }

    private static function indexEvidence(array $records): array
    {
        $indexed = [];
        foreach ($records as $record) if (is_array($record) && !empty($record['fact_id'])) $indexed[(string)$record['fact_id']] = $record;
        return $indexed;
    }

    private static function identityText(array $identity): string
    {
        $values = self::stringList($identity);
        return $values === [] ? '' : '使用参考图中的' . self::join($values, '、') . '作为主体';
    }

    private static function evidenceText(array $evidence): string
    {
        $values = [];
        foreach ($evidence as $item) if (is_array($item) && trim((string)($item['observation'] ?? '')) !== '') $values[] = trim((string)$item['observation']);
        return $values === [] ? '' : '保持' . self::join($values, '；');
    }

    private static function claimsText(array $claims): string
    {
        $values = [];
        foreach ($claims as $claim) if (is_array($claim) && trim((string)($claim['text'] ?? '')) !== '') $values[] = trim((string)$claim['text']);
        return $values === [] ? '' : self::join($values, '；');
    }

    private static function copyText(array $copy): string
    {
        $values = self::stringList([$copy['headline'] ?? '', $copy['subheadline'] ?? '', $copy['body'] ?? '']);
        return $values === [] ? '' : '图片内仅使用以下已确认文案：' . self::join($values, '；');
    }

    private static function ratioText(string $ratio, string $language = 'zh-CN'): string
    {
        return trim($ratio) === '' ? '' : ($language === 'en-US' ? ', ratio ' : '，比例 ') . trim($ratio);
    }

    private static function safeCopy(array $copy): array
    {
        return array_filter([
            'headline' => self::text((string)($copy['headline'] ?? ''), 80),
            'subheadline' => self::text((string)($copy['subheadline'] ?? ''), 160),
            'body' => self::text((string)($copy['body'] ?? ''), 320),
        ]);
    }

    private static function safeModelOptions(array $options): array
    {
        return array_intersect_key($options, array_flip(['ratio', 'quality', 'resolution', 'duration', 'negative_prompt', 'channel', 'model_id', 'market_product_id', 'market_sku_id', 'sku_id']));
    }

    private static function language(array $options, array $context = []): string
    {
        $language = (string)($options['prompt_language'] ?? $context['prompt_language'] ?? 'zh-CN');
        return $language === 'en-US' ? 'en-US' : 'zh-CN';
    }

    private static function ratio(array $options, array $section = []): string
    {
        return trim((string)($section['ratio'] ?? $options['ratio'] ?? $options['size'] ?? ''));
    }

    private static function normalizeLegacyIntent(string $prompt): array
    {
        $prompt = trim($prompt);
        if (!preg_match('/(?:section\s+purpose|narrative|visible\s+evidence\s+to\s+preserve|use\s+ratio)\s*:/i', $prompt)) {
            return ['intent' => $prompt, 'ratio' => ''];
        }
        $next = 'Section\\s+purpose|Narrative|Visible\\s+evidence\\s+to\\s+preserve|Use\\s+ratio|Do\\s+not\\s+invent';
        $field = static function (string $name) use ($prompt, $next): string {
            $pattern = '/(?:^|\\R)\\s*' . preg_quote($name, '/') . '\\s*:\\s*(.+?)(?=\\R\\s*(?:' . $next . ')\\s*(?::|$)|\\z)/isu';
            return preg_match($pattern, $prompt, $matches) === 1 ? trim((string)$matches[1]) : '';
        };
        $purpose = $field('Section purpose');
        $narrative = $field('Narrative');
        preg_match('/(?:^|\\R)\\s*Use\\s+ratio\\s*:?\\s*([0-9]+:[0-9]+)/iu', $prompt, $ratio);
        return [
            'intent' => $purpose !== '' ? $purpose : ($narrative !== '' ? $narrative : $prompt),
            'ratio' => trim((string)($ratio[1] ?? '')),
        ];
    }

    private static function array($value): array { return is_array($value) ? $value : []; }
    private static function text(string $value, int $limit): string
    {
        $value = self::normalizeUtf8($value);
        $value = preg_replace('/[\x{10000}-\x{10FFFF}]/u', '', $value) ?? $value;
        return mb_substr(trim($value), 0, $limit, 'UTF-8');
    }

    private static function normalizeUtf8(string $value): string
    {
        if ($value === '' || mb_check_encoding($value, 'UTF-8')) {
            return $value;
        }
        $source = mb_detect_encoding($value, ['GB18030', 'GBK', 'BIG-5'], true);
        if ($source !== false) {
            return mb_convert_encoding($value, 'UTF-8', $source);
        }
        $clean = @iconv('UTF-8', 'UTF-8//IGNORE', $value);
        return is_string($clean) ? $clean : '';
    }
    private static function stringList($value): array
    {
        $items = is_array($value) ? $value : [$value]; $out = [];
        foreach ($items as $item) { if (is_scalar($item) && trim((string)$item) !== '') $out[] = trim((string)$item); }
        return array_values(array_unique($out));
    }
    private static function join(array $items, string $separator): string { return implode($separator, self::stringList($items)); }
    private static function sentence(string $value): string { return rtrim(trim($value), "。.;； ") . '。'; }
    private static function clean(string $value): string
    {
        $value = self::normalizeUtf8($value);
        $value = preg_replace('/[。.]\s*[。.]+/u', '。', $value) ?: $value;
        $value = preg_replace('/\.\s*\./u', '.', $value) ?: $value;
        $value = preg_replace('/。\s*\./u', '。', $value) ?: $value;
        $value = preg_replace('/\.\s*。/u', '。', $value) ?: $value;
        $value = preg_replace('/[ \t]{2,}/u', ' ', $value) ?: $value;
        return trim($value);
    }
}
