<?php
declare(strict_types=1);

namespace app\common\service\workbench;

use app\common\model\ai\AiConsumptionEvent;
use InvalidArgumentException;
use think\facade\Db;

final class PlatformSupplierCostReview
{
    private const POINTS_PATTERN = '/\A(?:0|[1-9]\d{0,11})(?:\.\d{1,6})?\z/';

    public static function confirmedPoints(array $ids): array
    {
        if (!$ids) return [];
        $events = Db::name('ai_consumption_event')->whereIn('consumption_id', $ids)
            ->where('event_type', AiConsumptionEvent::SUPPLIER_COST_CONFIRMED)
            ->where('event_status', 'confirmed')
            ->field('consumption_id,payload_summary')->order('id', 'asc')->select();
        $costs = [];
        foreach ($events as $event) {
            $payload = json_decode((string)$event['payload_summary'], true);
            $points = $payload['supplier_points'] ?? null;
            if (is_string($points) && preg_match(self::POINTS_PATTERN, $points)) {
                $costs[(int)$event['consumption_id']] ??= (float)$points;
            }
        }
        return $costs;
    }

    public static function confirm(int $id, int $adminId, string $points, string $reference): void
    {
        $points = trim($points);
        $reference = trim($reference);
        if ($id < 1 || $adminId < 1) throw new InvalidArgumentException('无效的消费记录或操作员');
        if (!preg_match(self::POINTS_PATTERN, $points)) throw new InvalidArgumentException('请输入最多六位小数的非负上游点数');
        if ($reference === '' || mb_strlen($reference) > 150 || preg_match('/[\x00-\x1F\x7F]/u', $reference)) {
            throw new InvalidArgumentException('请输入有效的上游账单流水号或历史费率凭证编号');
        }
        $points = rtrim(rtrim(str_contains($points, '.') ? $points : $points.'.0', '0'), '.');

        Db::transaction(static function () use ($id, $adminId, $points, $reference): void {
            $row = Db::name('ai_consumption_log')->where('id', $id)->lock(true)->find();
            if (!$row || $row['billing_status'] !== 'settled') throw new InvalidArgumentException('消费记录不存在或尚未结算');
            if (PlatformTaskCost::points($row) !== null) throw new InvalidArgumentException('该笔已有可核算的上游成本，请刷新');
            $event = Db::name('ai_consumption_event')->where('consumption_id', $id)
                ->where('event_type', AiConsumptionEvent::SUPPLIER_COST_CONFIRMED)
                ->where('event_status', 'confirmed')->find();
            if ($event) {
                $prior = json_decode((string)$event['payload_summary'], true);
                if (($prior['supplier_points'] ?? null) === $points && ($prior['reference'] ?? null) === $reference) return;
                throw new InvalidArgumentException('该笔已确认上游成本，请核对原有凭证');
            }
            AiConsumptionEvent::create([
                'consumption_id' => $id,
                'event_type' => AiConsumptionEvent::SUPPLIER_COST_CONFIRMED,
                'event_status' => 'confirmed',
                'payload_summary' => ['supplier_points'=>$points, 'reference'=>$reference, 'admin_id'=>$adminId],
                'create_time' => time(),
            ]);
        });
    }
}
