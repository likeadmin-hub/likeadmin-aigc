<?php
namespace app\common\service\workbench;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/** Half-open Shanghai calendar windows; SQL uses epoch buckets, independent of DB timezone. */
final class OperationsRange
{
    public static function resolve(array $params, ?int $now = null): array
    {
        $now ??= time();
        $zone = new DateTimeZone('Asia/Shanghai');
        $today = (new DateTimeImmutable('@'.$now))->setTimezone($zone)->setTime(0, 0);
        $key = (string)($params['range'] ?? '7d');
        if ($key === 'custom') {
            $start = self::date((string)($params['start_date'] ?? ''), $zone);
            $last = self::date((string)($params['end_date'] ?? ''), $zone);
            if ($last < $start || $last > $today || $start->diff($last)->days >= 90) {
                throw new InvalidArgumentException('日期范围需为不超过90天的有效日期，结束日期不能晚于今天');
            }
        } else {
            if (!in_array($key, ['today', 'yesterday', '7d', '30d'], true)) throw new InvalidArgumentException('日期范围无效');
            $last = $key === 'yesterday' ? $today->modify('-1 day') : $today;
            $days = $key === '7d' ? 7 : ($key === '30d' ? 30 : 1);
            $start = $last->modify('-'.($days-1).' days');
        }
        $days = (int)$start->diff($last)->days + 1;
        $from = $start->getTimestamp();
        $to = min($last->modify('+1 day')->getTimestamp(), $now+1);
        $step = $days === 1 ? 3600 : 86400;
        $labels = [];
        for ($at = $from; $at < $to; $at += $step) {
            $labels[] = (new DateTimeImmutable('@'.$at))->setTimezone($zone)->format($step === 3600 ? 'H:00' : 'm-d');
        }
        return ['key'=>$key, 'start_date'=>$start->format('Y-m-d'), 'end_date'=>$last->format('Y-m-d'),
            'start_at'=>$from, 'end_at'=>$to, 'previous_start_at'=>$from-$days*86400,
            'previous_end_at'=>$to-$days*86400, 'bucket'=>$step===3600?'hour':'day',
            'timezone'=>'Asia/Shanghai', 'step'=>$step, 'labels'=>$labels];
    }

    private static function date(string $date, DateTimeZone $zone): DateTimeImmutable
    {
        $value = DateTimeImmutable::createFromFormat('!Y-m-d', $date, $zone);
        if (!$value || $value->format('Y-m-d') !== $date) throw new InvalidArgumentException('日期格式应为YYYY-MM-DD');
        return $value;
    }
}
