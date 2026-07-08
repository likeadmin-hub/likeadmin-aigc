<?php

namespace app\common\service;

use app\common\model\PcFeedback;
use Exception;

class PcFeedbackService
{
    public const TYPES = [
        'image',
        'video',
        'avatar',
        'canvas',
        'story',
        'membership',
        'invoice',
        'other',
        'feature',
        'suggestion',
    ];
    public const STATUS_PENDING = 0;
    public const STATUS_PROCESSING = 1;
    public const STATUS_DONE = 2;

    public static function submit(int $tenantId, int $userId, array $params): array
    {
        if ($tenantId <= 0) {
            throw new Exception('站点信息异常');
        }
        $type = self::normalizeType((string)($params['type'] ?? 'image'));
        $content = trim((string)($params['content'] ?? ''));
        $contact = trim((string)($params['contact'] ?? ''));
        $images = self::normalizeImages($params['images'] ?? []);

        if ($content === '') {
            throw new Exception('请输入反馈内容');
        }
        if (mb_strlen($content) > 400) {
            throw new Exception('反馈内容不能超过400字');
        }
        if (empty($images)) {
            throw new Exception('请上传反馈图片');
        }

        $feedback = PcFeedback::create([
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'type' => $type,
            'content' => $content,
            'images' => $images,
            'contact' => $contact,
            'status' => self::STATUS_PENDING,
            'reply' => '',
            'create_time' => time(),
            'update_time' => time(),
        ]);

        return self::format($feedback->toArray());
    }

    public static function userLists(int $tenantId, int $userId, array $params = []): array
    {
        if ($userId <= 0) {
            throw new Exception('请先登录后查看反馈历史');
        }
        $page = max((int)($params['page_no'] ?? $params['page'] ?? 1), 1);
        $limit = min(max((int)($params['page_size'] ?? $params['limit'] ?? 10), 1), 50);

        $query = PcFeedback::where([
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'delete_time' => 0,
        ]);
        $count = (clone $query)->count();
        $lists = $query->order('id desc')
            ->page($page, $limit)
            ->select()
            ->append(['images_url'])
            ->toArray();

        return [
            'lists' => array_map([self::class, 'format'], $lists),
            'count' => $count,
            'page_no' => $page,
            'page_size' => $limit,
        ];
    }

    public static function tenantLists(int $tenantId, array $params = []): array
    {
        $page = max((int)($params['page_no'] ?? $params['page'] ?? 1), 1);
        $limit = min(max((int)($params['page_size'] ?? $params['limit'] ?? 15), 1), 100);
        $type = trim((string)($params['type'] ?? ''));
        $status = (string)($params['status'] ?? '');
        $keyword = trim((string)($params['keyword'] ?? ''));

        $query = PcFeedback::with(['user'])
            ->where([
                'tenant_id' => $tenantId,
                'delete_time' => 0,
            ]);
        if ($type !== '') {
            $query->where('type', self::normalizeType($type));
        }
        if ($status !== '') {
            $query->where('status', (int)$status);
        }
        if ($keyword !== '') {
            $query->whereLike('content|contact', '%' . $keyword . '%');
        }

        $count = (clone $query)->count();
        $lists = $query->order('id desc')
            ->page($page, $limit)
            ->select()
            ->append(['images_url'])
            ->toArray();

        return [
            'lists' => array_map([self::class, 'format'], $lists),
            'count' => $count,
            'page_no' => $page,
            'page_size' => $limit,
        ];
    }

    public static function reply(int $tenantId, int $id, array $params): void
    {
        $feedback = PcFeedback::where([
            'tenant_id' => $tenantId,
            'id' => $id,
            'delete_time' => 0,
        ])->findOrEmpty();
        if ($feedback->isEmpty()) {
            throw new Exception('反馈记录不存在');
        }
        $feedback->save([
            'status' => min(max((int)($params['status'] ?? self::STATUS_DONE), 0), 2),
            'reply' => mb_substr(trim((string)($params['reply'] ?? '')), 0, 500),
            'update_time' => time(),
        ]);
    }

    public static function statusLabels(): array
    {
        return [
            self::STATUS_PENDING => '待处理',
            self::STATUS_PROCESSING => '处理中',
            self::STATUS_DONE => '已处理',
        ];
    }

    public static function typeOptions(): array
    {
        return [
            'image' => '图片生成',
            'video' => '视频生成',
            'avatar' => '数字人',
            'canvas' => '智能画布',
            'story' => '故事创作',
            'membership' => '会员与' . PointUnitService::unit(),
            'invoice' => '开具发票',
            'other' => '其他咨询/建议',
            'feature' => '功能反馈',
            'suggestion' => '优化建议',
        ];
    }

    private static function normalizeType(string $type): string
    {
        return in_array($type, self::TYPES, true) ? $type : 'image';
    }

    private static function normalizeImages($images): array
    {
        if (is_string($images)) {
            $decoded = json_decode($images, true);
            $images = is_array($decoded) ? $decoded : [$images];
        }
        $images = array_values(array_unique(array_filter(array_map(static function ($uri) {
            return FileService::setFileUrl((string)$uri);
        }, (array)$images))));
        return array_slice($images, 0, 4);
    }

    private static function format(array $row): array
    {
        $row['type_label'] = self::typeOptions()[$row['type'] ?? ''] ?? '图片生成';
        $row['status_label'] = self::statusLabels()[(int)($row['status'] ?? 0)] ?? '待处理';
        if (!isset($row['images_url'])) {
            $images = $row['images'] ?? [];
            if (is_string($images)) {
                $decoded = json_decode($images, true);
                $images = is_array($decoded) ? $decoded : [];
            }
            $row['images_url'] = array_values(array_map(static fn($uri) => FileService::getFileUrl((string)$uri), array_filter((array)$images)));
        }
        return $row;
    }
}
