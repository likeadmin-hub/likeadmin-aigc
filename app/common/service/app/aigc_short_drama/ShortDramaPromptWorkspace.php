<?php

namespace app\common\service\app\aigc_short_drama;

use app\common\model\app\aigc_short_drama\AigcShortDramaConfig;
use think\facade\Db;
use RuntimeException;

final class ShortDramaPromptWorkspace
{
    private const TABLE = 'aigc_short_drama_prompt_revision';

    public static function legacy(int $tenantId): array
    {
        // Deliberately retain old whole-row precedence until explicit migration.
        $row = AigcShortDramaConfig::whereIn('tenant_id', [$tenantId, 0])->orderRaw('tenant_id = ' . $tenantId . ' desc')->findOrEmpty();
        $config = $row->isEmpty() ? [] : json_decode((string)$row['config_json'], true);
        return array_intersect_key((array)$config, array_flip(['script_system_prompt', 'script_prompt_template', 'multi_episode_script_system_prompt', 'multi_episode_script_prompt_template', 'prompt_config']));
    }

    private static function latest(int $tenantId): array
    {
        $row = Db::name(self::TABLE)->where('tenant_id', $tenantId)->order('revision', 'desc')->find();
        return $row ? json_decode($row['snapshot_json'], true, 512, JSON_THROW_ON_ERROR) : [];
    }

    /** Pure per-key inheritance, also used by previews and contract tests. */
    public static function resolve(int $tenantId, array $tenant, array $platform, array $legacy = []): array
    {
        if (!empty($tenant['pinned_effective']) && (int)($tenant['tenant_id'] ?? -1) === $tenantId) return $tenant;
        $defaults = ShortDramaPromptCatalog::defaults();
        $mode = $tenant['mode'] ?? ($legacy ? 'legacy' : 'workspace');
        $platformOverrides = ($platform['mode'] ?? '') === 'workspace' ? (array)($platform['overrides'] ?? []) : [];
        $overrides = $mode === 'workspace' ? (array)($tenant['overrides'] ?? []) : [];
        $values = $defaults;
        $sources = array_fill_keys(array_keys($defaults), 'application');
        if ($mode === 'workspace') {
            foreach (['platform' => $platformOverrides, 'tenant' => $overrides] as $source => $entries) {
                foreach ($entries as $key => $value) {
                    if (!array_key_exists($key, $defaults)) continue;
                    $values[$key] = $value;
                    $sources[$key] = $source;
                }
            }
        }
        $snapshot = [
            'tenant_id' => $tenantId, 'revision' => (int)($tenant['revision'] ?? 0), 'mode' => $mode,
            'overrides' => $overrides, 'values' => $values, 'sources' => $sources,
            'legacy_config' => $mode === 'legacy' ? ($tenant['legacy_config'] ?? $legacy) : [],
            'catalog_version' => ShortDramaPromptCatalog::definition()['version'],
            // Include protected system templates: queued tasks must not pick up later code defaults.
            'systems' => ShortDramaPromptCatalog::definition()['systems'],
            'document_extra_defaults' => ShortDramaPromptDocuments::definition()['extra_defaults'],
        ];
        $snapshot['fingerprint'] = hash('sha256', json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        if ($mode === 'documents') {
            return ShortDramaPromptDocuments::resolve($snapshot, (array)($tenant['document_settings'] ?? []), $platform);
        }
        return $snapshot;
    }

    public static function capture(int $tenantId): array
    {
        return self::resolve($tenantId, self::latest($tenantId), $tenantId === 0 ? [] : self::latest(0), self::legacy($tenantId));
    }

    public static function forTask(int $tenantId, array $input): array
    {
        $snapshot = $input['_prompt_snapshot'] ?? null;
        if (is_array($snapshot) && (int)($snapshot['tenant_id'] ?? -1) === $tenantId) return $snapshot;
        $current = ShortDramaPromptCatalog::snapshot();
        if ($current !== null && (int)$current['tenant_id'] === $tenantId) return $current;
        // Historical tasks lack a snapshot. Never claim to know which historical prompt they used.
        $snapshot = self::capture($tenantId);
        $snapshot['historical_snapshot_missing'] = true;
        return $snapshot;
    }

    public static function detail(int $tenantId, bool $documents = false): array
    {
        $snapshot = self::capture($tenantId);
        if ($documents || $snapshot['mode'] === 'documents') return ShortDramaPromptDocuments::detail($snapshot);
        $inherited = self::resolve($tenantId, ['mode' => 'workspace'], $tenantId === 0 ? [] : self::latest(0));
        $groups = [];
        foreach (ShortDramaPromptCatalog::definition()['groups'] as $key => $label) {
            $items = [];
            foreach (ShortDramaPromptCatalog::definition()['items'] as $id => $item) {
                if ($item['group'] !== $key) continue;
                $items[] = $item + ['key' => $id, 'value' => $snapshot['values'][$id], 'source' => $snapshot['sources'][$id], 'inherited' => $inherited['values'][$id], 'inherited_source' => $inherited['sources'][$id]];
            }
            $groups[] = ['key' => $key, 'label' => $label, 'description' => ShortDramaPromptCatalog::definition()['group_descriptions'][$key] ?? '', 'items' => $items];
        }
        $legacy = $snapshot['legacy_config'];
        $migrationOverrides = array_intersect_key((array)($legacy['prompt_config'] ?? []), ShortDramaPromptCatalog::defaults());
        return ['groups' => $groups, 'revision' => $snapshot['revision'], 'fingerprint' => $snapshot['fingerprint'], 'mode' => $snapshot['mode'], 'overrides' => (object)$snapshot['overrides'], 'legacy_config' => (object)$legacy, 'migration_overrides' => (object)$migrationOverrides, 'protected_rules' => ShortDramaPromptCatalog::priority(), 'protected_systems' => $snapshot['systems']];
    }

    public static function draft(int $tenantId, array $params): array
    {
        $current = self::capture($tenantId);
        if (($params['fingerprint'] ?? '') !== $current['fingerprint']) throw new RuntimeException('配置已被更新，请刷新后重新比较和保存');
        if ((int)($params['format_version'] ?? 0) === ShortDramaPromptDocuments::FORMAT) {
            if (!array_key_exists('document_settings', $params) || !is_array($params['document_settings'])) throw new RuntimeException('缺少完整文档配置，未执行保存');
            $settings = ShortDramaPromptDocuments::validate((array)($params['document_settings'] ?? []));
            return self::resolve($tenantId, ['mode' => 'documents', 'revision' => $current['revision'], 'document_settings' => $settings], $tenantId === 0 ? [] : self::latest(0));
        }
        if (isset($params['format_version']) && (int)$params['format_version'] !== 2) throw new RuntimeException('不支持该提示词配置格式，请刷新页面');
        if ($current['mode'] === 'documents') throw new RuntimeException('请使用新版创作文档编辑器保存');
        $overrides = ShortDramaPromptCatalog::validate((array)($params['overrides'] ?? []));
        return self::resolve($tenantId, ['mode' => 'workspace', 'revision' => $current['revision'], 'overrides' => $overrides], $tenantId === 0 ? [] : self::latest(0));
    }

    public static function save(int $tenantId, int $adminId, array $params): array
    {
        $current = self::capture($tenantId);
        if ((int)($params['format_version'] ?? 0) === ShortDramaPromptDocuments::FORMAT && $current['mode'] !== 'documents') {
            $migration = ShortDramaPromptDocuments::migration($current);
            if ($migration['required'] && empty($params['confirm_migration'])) throw new RuntimeException('请预览整套迁移差异并确认后启用文档版');
            foreach ($migration['issues'] as $issue) {
                if (!in_array($issue['key'], (array)($params['resolved_migration_issues'] ?? []), true)) throw new RuntimeException('请核对并处理旧配置：' . $issue['key']);
            }
        }
        if ($current['mode'] === 'legacy' && empty($params['confirm_migration'])) throw new RuntimeException('请先预览旧配置差异并确认迁移，旧配置在确认前继续生效');
        $next = self::draft($tenantId, $params);
        return self::append($tenantId, $adminId, $current, $next, 'save');
    }

    private static function append(int $tenantId, int $adminId, array $current, array $next, string $action): array
    {
        return Db::transaction(static function () use ($tenantId, $adminId, $current, $next, $action): array {
            if (self::capture($tenantId)['fingerprint'] !== $current['fingerprint']) throw new RuntimeException('配置已被更新，请刷新后重试');
            if ($current['revision'] === 0 && !Db::name(self::TABLE)->where(['tenant_id' => $tenantId, 'revision' => 0])->find()) {
                self::insert($tenantId, $adminId, $current, 'baseline');
            }
            $next['revision'] = $current['revision'] + 1;
            unset($next['fingerprint']);
            $next['fingerprint'] = hash('sha256', json_encode($next, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
            try {
                self::insert($tenantId, $adminId, $next, $action);
            } catch (\Throwable $e) {
                // Unique (tenant_id, revision) is the CAS guard for concurrent writers.
                if (str_contains($e->getMessage(), 'Duplicate') || str_contains($e->getMessage(), '1062')) throw new RuntimeException('其他管理员已保存新版本，请刷新后重试');
                throw $e;
            }
            return ['revision' => $next['revision']];
        });
    }

    private static function insert(int $tenantId, int $adminId, array $snapshot, string $action): void
    {
        Db::name(self::TABLE)->insert(['tenant_id' => $tenantId, 'revision' => $snapshot['revision'], 'admin_id' => $adminId, 'action' => $action, 'snapshot_json' => json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), 'create_time' => time()]);
    }

    public static function history(int $tenantId, int $before = PHP_INT_MAX): array
    {
        return Db::name(self::TABLE)->where('tenant_id', $tenantId)->where('revision', '<', $before)->order('revision', 'desc')->limit(20)->field('revision,admin_id,action,create_time')->select()->toArray();
    }

    public static function version(int $tenantId, int $revision): array
    {
        $row = Db::name(self::TABLE)->where(['tenant_id' => $tenantId, 'revision' => $revision])->find();
        if (!$row) throw new RuntimeException('配置版本不存在');
        return json_decode($row['snapshot_json'], true, 512, JSON_THROW_ON_ERROR);
    }

    public static function recordRequest(int $tenantId, int $userId, string $taskId, string $stage, array $audit): void
    {
        Db::name('aigc_short_drama_prompt_request')->insert([
            'tenant_id' => $tenantId, 'user_id' => $userId, 'task_id' => $taskId, 'stage' => $stage,
            'revision' => (int)($audit['revision'] ?? 0), 'audit_json' => json_encode($audit, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), 'create_time' => time(),
        ]);
    }

    public static function requests(int $tenantId, int $before = PHP_INT_MAX): array
    {
        return Db::name('aigc_short_drama_prompt_request')->where('tenant_id', $tenantId)->where('id', '<', $before)
            ->order('id', 'desc')->limit(20)->field('id,task_id,stage,revision,create_time')->select()->toArray();
    }

    public static function request(int $tenantId, int $id): array
    {
        $row = Db::name('aigc_short_drama_prompt_request')->where(['tenant_id' => $tenantId, 'id' => $id])->find();
        if (!$row) throw new RuntimeException('生成记录不存在或无权访问');
        return ['id' => $id, 'task_id' => $row['task_id'], 'stage' => $row['stage'], 'audit' => json_decode($row['audit_json'], true, 512, JSON_THROW_ON_ERROR)];
    }

    public static function rollback(int $tenantId, int $adminId, array $params): array
    {
        $current = self::capture($tenantId);
        if (($params['fingerprint'] ?? '') !== $current['fingerprint']) throw new RuntimeException('配置已被更新，请刷新后重试');
        $target = self::version($tenantId, (int)($params['revision'] ?? -1));
        // Pin the historical effective values, not today's platform defaults.
        if ($target['mode'] === 'workspace') $target['overrides'] = $target['values'];
        if ($target['mode'] === 'documents') $target['pinned_effective'] = true;
        return self::append($tenantId, $adminId, $current, $target, 'rollback');
    }

    public static function restoreApplication(int $tenantId, int $adminId, array $params): array
    {
        $current = self::capture($tenantId);
        if ($current['mode'] !== 'documents') throw new RuntimeException('请先确认迁移到文档版，旧配置不会被自动覆盖');
        if (($params['fingerprint'] ?? '') !== $current['fingerprint']) throw new RuntimeException('配置已被更新，请刷新后重试');
        $settings = $current['document_settings'];
        $target = (string)($params['target'] ?? '');
        $ids = array_keys(ShortDramaPromptDocuments::definition()['documents']);
        if ($target !== 'all' && !in_array($target, $ids, true)) throw new RuntimeException('请选择恢复当前项或全部项');
        foreach ($target === 'all' ? $ids : [$target] as $id) $settings[$id] = ['mode' => 'application'];
        $next = self::resolve($tenantId, ['mode' => 'documents', 'revision' => $current['revision'], 'document_settings' => $settings], $tenantId === 0 ? [] : self::latest(0));
        if (empty($params['confirm'])) return ['before' => $current, 'after' => $next];
        return self::append($tenantId, $adminId, $current, $next, 'restore_application');
    }
}
