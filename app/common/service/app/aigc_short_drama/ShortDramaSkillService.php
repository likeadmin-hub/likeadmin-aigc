<?php

namespace app\common\service\app\aigc_short_drama;

use app\common\model\app\aigc_short_drama\AigcShortDramaSkill;
use app\common\model\app\aigc_short_drama\AigcShortDramaSkillCategory;
use app\common\model\file\TenantFile;
use app\common\service\FileService;
use Exception;
use think\facade\Db;

/** Tenant-operated Flova-style Skill catalogue for the short-drama workflow. */
final class ShortDramaSkillService
{
    public const DRAFT = 'draft';
    public const TESTING = 'testing';
    public const ACTIVE = 'active';
    public const PAUSED = 'paused';
    public const ARCHIVED = 'archived';

    public static function categories(int $tenantId, bool $activeOnly = true): array
    {
        self::seedCategories($tenantId);
        $query = AigcShortDramaSkillCategory::where(['tenant_id' => $tenantId, 'delete_time' => 0]);
        if ($activeOnly) $query->where('status', 1);
        return $query->order(['sort' => 'desc', 'id' => 'asc'])->select()->toArray();
    }

    public static function saveCategory(int $tenantId, array $params): array
    {
        $id = (int)($params['id'] ?? 0); $name = mb_substr(trim((string)($params['name'] ?? '')), 0, 60, 'UTF-8');
        if ($name === '') throw new Exception('请输入分类名称');
        $data = ['name' => $name, 'icon' => mb_substr(trim((string)($params['icon'] ?? '')), 0, 120, 'UTF-8'),
            'sort' => max(0, min(9999, (int)($params['sort'] ?? 0))), 'status' => empty($params['status']) ? 0 : 1, 'update_time' => time()];
        if ($id) {
            $row = AigcShortDramaSkillCategory::where(['tenant_id' => $tenantId, 'id' => $id, 'delete_time' => 0])->findOrEmpty();
            if ($row->isEmpty()) throw new Exception('分类不存在'); $row->save($data); return $row->toArray();
        }
        return AigcShortDramaSkillCategory::create($data + ['tenant_id' => $tenantId, 'create_time' => time(), 'delete_time' => 0])->toArray();
    }

    public static function lists(int $tenantId, array $params = []): array
    {
        $query = AigcShortDramaSkill::where(['tenant_id' => $tenantId, 'delete_time' => 0]);
        $keyword = trim((string)($params['keyword'] ?? ''));
        if ($keyword !== '') {
            $query->where(static function ($search) use ($keyword): void {
                $search->whereLike('name', '%' . $keyword . '%')
                    ->whereOrLike('skill_key', '%' . $keyword . '%')
                    ->whereOrLike('description', '%' . $keyword . '%');
            });
        }
        if (($status = (string)($params['status'] ?? '')) !== '' && $status !== 'all') $query->where('status', (int)$status);
        if (($release = trim((string)($params['release_status'] ?? ''))) !== '') $query->where('release_status', $release);
        if (($categoryId = (int)($params['category_id'] ?? 0)) > 0) $query->whereRaw('JSON_CONTAINS(COALESCE(category_ids_json, \'[]\'), ?)', [json_encode($categoryId)]);
        $pageNo = max(1, (int)($params['page_no'] ?? 1)); $pageSize = min(100, max(1, (int)($params['page_size'] ?? 20)));
        $count = (int)(clone $query)->count();
        $rows = $query->order(['sort' => 'desc', 'id' => 'desc'])->limit(($pageNo - 1) * $pageSize, $pageSize)->select()->toArray();
        return ['lists' => array_map([self::class, 'format'], $rows), 'count' => $count, 'page_no' => $pageNo, 'page_size' => $pageSize];
    }

    public static function detail(int $tenantId, int $id, bool $publishedOnly = false): array
    {
        $skill = self::find($tenantId, $id);
        if ($publishedOnly && ((int)$skill['status'] !== 1 || (string)$skill['release_status'] !== self::ACTIVE || (int)$skill['published_version'] <= 0)) throw new Exception('Skill 当前不可用');
        return $publishedOnly ? self::published($skill->toArray()) : self::format($skill->toArray(), true);
    }

    public static function create(int $tenantId, int $adminId, array $params): array
    {
        return Db::transaction(static function () use ($tenantId, $adminId, $params): array {
        $data = self::payload($tenantId, $params, true); self::assertKey($tenantId, $data['skill_key']); $time = time();
        $skill = AigcShortDramaSkill::create($data + ['tenant_id' => $tenantId, 'creator_admin_id' => $adminId, 'version' => 1,
            'published_version' => 0, 'release_status' => self::DRAFT, 'create_time' => $time, 'update_time' => $time, 'delete_time' => 0]);
        self::snapshot($tenantId, $skill->toArray(), $adminId, self::DRAFT); return self::format($skill->toArray(), true);
        });
    }

    public static function update(int $tenantId, int $adminId, array $params): array
    {
        return Db::transaction(static function () use ($tenantId, $adminId, $params): array {
        $skill = self::find($tenantId, (int)($params['id'] ?? 0)); $data = self::payload($tenantId, $params, false);
        self::assertDraftVersion($skill, $params);
        if ($data['skill_key'] !== (string)$skill['skill_key']) self::assertKey($tenantId, $data['skill_key'], (int)$skill['id']);
        $data += ['version' => (int)$skill['version'] + 1, 'release_status' => (int)$skill['published_version'] > 0 ? (string)$skill['release_status'] : self::DRAFT, 'update_time' => time()];
        $skill->save($data); self::snapshot($tenantId, $skill->toArray(), $adminId, self::DRAFT); return self::format($skill->toArray(), true);
        });
    }

    public static function release(int $tenantId, int $adminId, array $params): array
    {
        return Db::transaction(static function () use ($tenantId, $adminId, $params): array {
        $skill = self::find($tenantId, (int)($params['id'] ?? 0)); $release = trim((string)($params['release_status'] ?? self::ACTIVE));
        self::assertDraftVersion($skill, $params);
        if (!in_array($release, [self::DRAFT, self::TESTING, self::ACTIVE, self::PAUSED, self::ARCHIVED], true)) throw new Exception('发布状态无效');
        $data = ['release_status' => $release, 'update_time' => time()];
        if ($release === self::ACTIVE) {
            if ((int)$skill['status'] !== 1) throw new Exception('请先启用 Skill 再发布');
            $definition = ShortDramaSkillRuntime::normalizeDefinition(self::decode($skill['definition_json']));
            foreach (ShortDramaSkillRuntime::STAGES as $key => $label) {
                if ($definition['stages'][$key] === '') throw new Exception('请完善“' . $label . '”后再发布');
            }
            $data['published_version'] = (int)$skill['version']; $data['published_at'] = time();
        }
        $skill->save($data); self::snapshot($tenantId, $skill->toArray(), $adminId, $release); return self::format($skill->toArray(), true);
        });
    }

    public static function rollback(int $tenantId, int $adminId, array $params): array
    {
        return Db::transaction(static function () use ($tenantId, $adminId, $params): array {
        $skill = self::find($tenantId, (int)($params['id'] ?? 0)); $version = (int)($params['version'] ?? 0);
        $row = Db::name('aigc_short_drama_skill_version')->where(['tenant_id' => $tenantId, 'skill_id' => (int)$skill['id']])->where('version', $version)->find();
        if (!$row) throw new Exception('Skill 版本不存在'); $snapshot = self::decode($row['snapshot_json'] ?? []); if (!$snapshot) throw new Exception('Skill 版本无效');
        $data = self::payload($tenantId, $snapshot, false) + ['version' => (int)$skill['version'] + 1, 'release_status' => (int)$skill['published_version'] > 0 ? (string)$skill['release_status'] : self::DRAFT, 'update_time' => time()];
        $skill->save($data); self::snapshot($tenantId, $skill->toArray(), $adminId, self::DRAFT); return self::format($skill->toArray(), true);
        });
    }

    public static function status(int $tenantId, int $id, bool $enabled): void { self::find($tenantId, $id)->save(['status' => $enabled ? 1 : 0, 'update_time' => time()]); }
    public static function delete(int $tenantId, int $id): void { self::find($tenantId, $id)->save(['delete_time' => time(), 'update_time' => time()]); }
    public static function versions(int $tenantId, int $id): array { self::find($tenantId, $id); return Db::name('aigc_short_drama_skill_version')->where(['tenant_id' => $tenantId, 'skill_id' => $id])->order('version', 'desc')->select()->toArray(); }

    public static function featured(int $tenantId, array $params = []): array
    {
        self::seedCategories($tenantId); $query = AigcShortDramaSkill::where(['tenant_id' => $tenantId, 'status' => 1, 'release_status' => self::ACTIVE, 'delete_time' => 0])->where('published_version', '>', 0);
        $keyword = mb_strtolower(trim((string)($params['keyword'] ?? '')), 'UTF-8');
        $categoryId = (int)($params['category_id'] ?? 0);
        $homeRecommended = (int)($params['home_recommended'] ?? 0) === 1;
        if ($homeRecommended) $query->where('home_recommended', 1);
        $limit = min($homeRecommended ? 5 : 100, max(1, (int)($params['limit'] ?? 30)));
        $published = array_map([self::class, 'published'], $query->order(['sort' => 'desc', 'id' => 'desc'])->select()->toArray());
        $published = array_values(array_filter($published, static function ($item) use ($keyword, $categoryId): bool {
            return ($keyword === '' || mb_strpos(mb_strtolower($item['name'] . ' ' . $item['description'], 'UTF-8'), $keyword) !== false)
                && ($categoryId <= 0 || in_array($categoryId, (array)$item['category_ids']));
        }));
        return ['categories' => self::categories($tenantId), 'lists' => array_slice($published, 0, $limit), 'count' => count($published)];
    }

    public static function mine(int $tenantId, int $userId): array
    {
        $defaults = Db::name('aigc_short_drama_user_skill')->where(['tenant_id' => $tenantId, 'user_id' => $userId, 'enabled' => 1, 'delete_time' => 0])->column('skill_id');
        $skills = $defaults ? AigcShortDramaSkill::whereIn('id', $defaults)->where(['tenant_id' => $tenantId, 'delete_time' => 0])->where('published_version', '>', 0)->select()->toArray() : [];
        return ['defaults' => array_map(static function ($skill): array {
            return self::published($skill) + ['available' => (int)$skill['status'] === 1 && $skill['release_status'] === self::ACTIVE];
        }, $skills), 'history' => self::history($tenantId, $userId)['lists']];
    }

    public static function setDefault(int $tenantId, int $userId, int $skillId, bool $enabled): void
    {
        if ($enabled) self::detail($tenantId, $skillId, true);
        $where = ['tenant_id' => $tenantId, 'user_id' => $userId, 'skill_id' => $skillId]; $time = time();
        $row = Db::name('aigc_short_drama_user_skill')->where($where)->find();
        if ($row) Db::name('aigc_short_drama_user_skill')->where('id', $row['id'])->update(['enabled' => $enabled ? 1 : 0, 'last_enabled_at' => $time, 'delete_time' => 0, 'update_time' => $time]);
        else Db::name('aigc_short_drama_user_skill')->insert($where + ['enabled' => $enabled ? 1 : 0, 'last_enabled_at' => $time, 'create_time' => $time, 'update_time' => $time, 'delete_time' => 0]);
    }

    public static function history(int $tenantId, int $userId, array $params = []): array
    {
        $limit = min(100, max(1, (int)($params['limit'] ?? 30)));
        return ['lists' => Db::name('aigc_short_drama_skill_usage')->where(['tenant_id' => $tenantId, 'user_id' => $userId, 'delete_time' => 0])->order('id', 'desc')->limit($limit)->select()->toArray()];
    }

    public static function recommend(int $tenantId, array $params, int $userId = 0): array
    {
        $content = mb_strtolower(trim((string)($params['prompt'] ?? '')), 'UTF-8'); if ($content === '') return ['lists' => []];
        $rows = self::featured($tenantId, ['limit' => 80])['lists']; $matches = [];
        $defaults = $userId > 0 ? Db::name('aigc_short_drama_user_skill')->where(['tenant_id' => $tenantId, 'user_id' => $userId, 'enabled' => 1, 'delete_time' => 0])->column('skill_id') : [];
        foreach ($rows as $skill) {
            $definition = (array)($skill['definition'] ?? []); $score = 0; $reasons = [];
            foreach (array_merge([(string)$skill['invocation_rule'], (string)$skill['description']], (array)($definition['keywords'] ?? []), (array)($definition['positive_examples'] ?? [])) as $term) {
                $term = mb_strtolower(trim((string)$term), 'UTF-8'); if ($term !== '' && mb_strpos($content, $term) !== false) { $score += 4; $reasons[] = mb_substr($term, 0, 30, 'UTF-8'); }
            }
            foreach ((array)($definition['negative_examples'] ?? []) as $term) if (($term = mb_strtolower(trim((string)$term), 'UTF-8')) !== '' && mb_strpos($content, $term) !== false) $score -= 6;
            if ($score > 0 && in_array($skill['id'], $defaults)) $score++;
            if ($score > 0) $matches[] = $skill + ['match_score' => $score, 'match_reason' => '创作题材与此 Skill 的适用方向匹配，请确认是否使用'];
        }
        usort($matches, static fn($a, $b) => $b['match_score'] <=> $a['match_score']);
        return ['lists' => array_slice($matches, 0, 3), 'uncertain' => !$matches || (count($matches) > 1 && $matches[0]['match_score'] - $matches[1]['match_score'] < 2)];
    }

    /** Resolve and freeze the published version when a project is submitted. */
    public static function resolveForTask(int $tenantId, array $params): array
    {
        $id = (int)($params['skill_id'] ?? 0); if (!$id) return [];
        $skill = self::find($tenantId, $id);
        if ((int)$skill['status'] !== 1 || (string)$skill['release_status'] !== self::ACTIVE || (int)$skill['published_version'] <= 0) throw new Exception('所选 Skill 当前不可用，请重新选择');
        if ((int)($params['skill_version'] ?? 0) > 0 && (int)$params['skill_version'] !== (int)$skill['published_version']) throw new Exception('Skill 已更新，请重新选择并确认新版本');
        $version = Db::name('aigc_short_drama_skill_version')->where(['tenant_id' => $tenantId, 'skill_id' => $id, 'version' => (int)$skill['published_version']])->find();
        if (!$version) throw new Exception('Skill 已发布版本不存在'); $snapshot = self::decode($version['snapshot_json'] ?? []);
        return ['id' => $id, 'version' => (int)$version['version'], 'source' => in_array(($params['skill_source'] ?? ''), ['manual', 'recommended'], true) ? $params['skill_source'] : 'manual',
            'name' => (string)($snapshot['name'] ?? ''), 'skill_key' => (string)($snapshot['skill_key'] ?? ''), 'definition' => (array)($snapshot['definition'] ?? []), 'model_policy' => (array)($snapshot['model_policy'] ?? []), 'execution_policy' => (array)($snapshot['execution_policy'] ?? [])];
    }

    public static function recordUsage(int $tenantId, int $userId, int $projectId, string $taskId, array $snapshot, string $status = 'submitted'): void
    {
        if (!$snapshot) return;
        // Retries and duplicate callbacks retain the original task/version record.
        if (Db::name('aigc_short_drama_skill_usage')->where(['tenant_id' => $tenantId, 'task_id' => $taskId])->find()) return;
        try { Db::name('aigc_short_drama_skill_usage')->insert(['tenant_id' => $tenantId, 'user_id' => $userId, 'project_id' => $projectId, 'task_id' => $taskId,
            'skill_id' => (int)$snapshot['id'], 'skill_version' => (int)$snapshot['version'], 'skill_source' => (string)$snapshot['source'], 'skill_name' => (string)$snapshot['name'], 'status' => $status,
            'snapshot_json' => json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'create_time' => time(), 'update_time' => time(), 'delete_time' => 0]);
        } catch (\Throwable $e) {
            if (!Db::name('aigc_short_drama_skill_usage')->where(['tenant_id' => $tenantId, 'task_id' => $taskId, 'user_id' => $userId, 'skill_id' => (int)$snapshot['id'], 'skill_version' => (int)$snapshot['version']])->find()) throw $e;
        }
    }

    private static function payload(int $tenantId, array $params, bool $creating): array
    {
        $definition = ShortDramaSkillRuntime::normalizeDefinition((array)($params['definition'] ?? []));
        $key = strtolower(trim((string)($params['skill_key'] ?? ''))); $key = preg_replace('/[^a-z0-9_]/', '_', $key) ?: '';
        if ($creating && $key === '') throw new Exception('请输入 Skill 标识'); if (!preg_match('/^[a-z][a-z0-9_]{1,79}$/', $key)) throw new Exception('Skill 标识需为小写字母开头的下划线命名');
        $name = mb_substr(trim((string)($params['name'] ?? '')), 0, 120, 'UTF-8'); if ($name === '') throw new Exception('请输入 Skill 名称');
        $rule = mb_substr(trim((string)($params['invocation_rule'] ?? '')), 0, 200, 'UTF-8'); if ($rule === '') throw new Exception('请输入 Skill 调用规则');
        $categoryIds = array_values(array_unique(array_map('intval', (array)($params['category_ids'] ?? []))));
        if ($categoryIds) { $valid = Db::name('aigc_short_drama_skill_category')->where(['tenant_id' => $tenantId, 'delete_time' => 0])->whereIn('id', $categoryIds)->column('id'); if (count($valid) !== count($categoryIds)) throw new Exception('Skill 分类无效'); }
        $coverAssetId = max(0, (int)($params['cover_asset_id'] ?? 0));
        $coverUrl = mb_substr(trim((string)($params['cover_url'] ?? '')), 0, 500, 'UTF-8');
        if ($coverUrl !== '' || $coverAssetId > 0) {
            // TenantFile uses nullable SoftDelete timestamps. Adding
            // `delete_time = 0` makes every normal material-library record
            // invisible, because active files store NULL instead of 0.
            // Let the model's SoftDelete scope handle deleted files.
            $files = TenantFile::where('tenant_id', $tenantId);
            if ($coverUrl !== '') {
                $uri = ltrim((string)(parse_url($coverUrl, PHP_URL_PATH) ?: $coverUrl), '/');
                $files->whereIn('uri', array_values(array_unique([$coverUrl, $uri, '/' . $uri])));
            } else $files->where('id', $coverAssetId);
            $file = $files->find();
            if (!$file) throw new Exception('请从当前租户素材库选择封面图片或视频');
            $coverAssetId = (int)$file['id'];
            $coverUrl = FileService::getFileUrlByStorage((string)$file['uri'], (string)$file['storage_scope'], (string)$file['storage_engine'], (string)$file['storage_domain']);
        }
        return ['skill_key' => $key, 'name' => $name, 'description' => mb_substr(trim((string)($params['description'] ?? '')), 0, 600, 'UTF-8'), 'invocation_rule' => $rule,
            'category_ids_json' => json_encode($categoryIds), 'cover_asset_id' => $coverAssetId, 'cover_url' => $coverUrl,
            'cover_type' => in_array(($params['cover_type'] ?? ''), ['image', 'video'], true) ? $params['cover_type'] : 'image', 'definition_json' => json_encode($definition, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'model_policy_json' => json_encode((array)($params['model_policy'] ?? []), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'execution_policy_json' => json_encode((array)($params['execution_policy'] ?? []), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'status' => empty($params['status']) ? 0 : 1, 'home_recommended' => empty($params['home_recommended']) ? 0 : 1, 'sort' => max(0, min(9999, (int)($params['sort'] ?? 0)))] ;
    }

    private static function snapshot(int $tenantId, array $skill, int $adminId, string $status): void
    {
        $where = ['tenant_id' => $tenantId, 'skill_id' => (int)$skill['id'], 'version' => (int)$skill['version']];
        $data = ['release_status' => $status, 'snapshot_json' => json_encode(self::format($skill, true), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'created_by' => $adminId, 'published_by' => $status === self::ACTIVE ? $adminId : 0, 'published_at' => $status === self::ACTIVE ? time() : 0, 'update_time' => time()];
        $row = Db::name('aigc_short_drama_skill_version')->where($where)->find();
        if ($row) {
            // Definition snapshots are immutable. Publishing only changes release metadata.
            if ((int)$row['published_at'] === 0 && $status === self::ACTIVE) {
                Db::name('aigc_short_drama_skill_version')->where('id', $row['id'])->update(['release_status' => self::ACTIVE, 'published_at' => time(), 'published_by' => $adminId, 'update_time' => time()]);
            }
            return;
        }
        Db::name('aigc_short_drama_skill_version')->insert($where + $data + ['create_time' => time()]);
    }
    private static function published(array $skill): array
    {
        $row = Db::name('aigc_short_drama_skill_version')->where(['tenant_id' => (int)$skill['tenant_id'], 'skill_id' => (int)$skill['id'], 'version' => (int)$skill['published_version']])->find();
        if (!$row) throw new Exception('Skill 已发布版本不存在');
        $snapshot = self::decode($row['snapshot_json']);
        $snapshot['published_version'] = (int)$row['version'];
        $snapshot['version'] = (int)$row['version'];
        $snapshot['published_at'] = (int)$row['published_at'];
        $snapshot['release_status'] = self::ACTIVE;
        $snapshot = array_replace($snapshot, self::usageStats((int)$skill['tenant_id'], (int)$skill['id']));
        return $snapshot;
    }
    private static function usageStats(int $tenantId, int $id): array
    {
        $query = Db::name('aigc_short_drama_skill_usage')->where(['tenant_id' => $tenantId, 'skill_id' => $id, 'delete_time' => 0]);
        $count = (int)(clone $query)->count();
        $successful = (int)(clone $query)->where('status', 'success')->count();
        return ['usage_count' => $count, 'success_rate' => $count ? round($successful / $count * 100, 1) : 0, 'last_used_at' => (int)(clone $query)->max('create_time')];
    }
    private static function find(int $tenantId, int $id): AigcShortDramaSkill { $row = AigcShortDramaSkill::where(['tenant_id' => $tenantId, 'id' => $id, 'delete_time' => 0])->lock(true)->findOrEmpty(); if ($row->isEmpty()) throw new Exception('Skill 不存在'); return $row; }
    private static function assertDraftVersion(AigcShortDramaSkill $skill, array $params): void
    {
        if (isset($params['version']) && (int)$params['version'] !== (int)$skill['version']) throw new Exception('Skill 已被其他管理员修改，请刷新后重试');
    }
    private static function assertKey(int $tenantId, string $key, int $ignoreId = 0): void { $query = AigcShortDramaSkill::where(['tenant_id' => $tenantId, 'skill_key' => $key, 'delete_time' => 0]); if ($ignoreId) $query->where('id', '<>', $ignoreId); if (!$query->findOrEmpty()->isEmpty()) throw new Exception('Skill 标识已存在'); }
    private static function decode(mixed $value): array { if (is_array($value)) return $value; $value = json_decode((string)$value, true); return is_array($value) ? $value : []; }
    private static function format(array $row, bool $detail = false): array
    {
        $definition = ShortDramaSkillRuntime::normalizeDefinition(self::decode($row['definition_json'] ?? []));
        $categories = self::categories((int)$row['tenant_id']); $names = []; foreach ((array)self::decode($row['category_ids_json'] ?? []) as $id) foreach ($categories as $category) if ((int)$category['id'] === (int)$id) $names[] = $category['name'];
        $result = ['id' => (int)($row['id'] ?? 0), 'skill_key' => (string)($row['skill_key'] ?? ''), 'name' => (string)($row['name'] ?? ''), 'description' => (string)($row['description'] ?? ''),
            'invocation_rule' => (string)($row['invocation_rule'] ?? ''), 'category_ids' => self::decode($row['category_ids_json'] ?? []), 'category_names' => $names, 'cover_asset_id' => (int)($row['cover_asset_id'] ?? 0),
            'cover_url' => (string)($row['cover_url'] ?? ''), 'cover_type' => (string)($row['cover_type'] ?? 'image'), 'status' => (int)($row['status'] ?? 0), 'home_recommended' => (int)($row['home_recommended'] ?? 0), 'sort' => (int)($row['sort'] ?? 0),
            'version' => (int)($row['version'] ?? 1), 'published_version' => (int)($row['published_version'] ?? 0), 'release_status' => (string)($row['release_status'] ?? self::DRAFT), 'published_at' => (int)($row['published_at'] ?? 0)];
        $result['creator_admin_id'] = (int)($row['creator_admin_id'] ?? 0);
        $result['update_time'] = $row['update_time'] ?? 0;
        return $detail ? $result + ['definition' => $definition, 'model_policy' => self::decode($row['model_policy_json'] ?? []), 'execution_policy' => self::decode($row['execution_policy_json'] ?? [])] : array_replace($result, self::usageStats((int)$row['tenant_id'], (int)$row['id']));
    }
    private static function seedCategories(int $tenantId): void
    {
        if (AigcShortDramaSkillCategory::where(['tenant_id' => $tenantId, 'delete_time' => 0])->count() > 0) return;
        $names = ['全部','热门玩法','大师美学','剧情短片','商业广告','动漫游戏','短剧/微短剧','音乐/MV','产品展示','平面设计','生活 Vlog','工具增强']; $time = time();
        foreach ($names as $index => $name) AigcShortDramaSkillCategory::create(['tenant_id' => $tenantId, 'name' => $name, 'icon' => '', 'sort' => 100 - $index, 'status' => 1, 'create_time' => $time, 'update_time' => $time, 'delete_time' => 0]);
    }
}
