<?php
namespace app\common\service\app\aigc_short_drama;

/** Read-only projection of durable provider receipts for task cost display. */
final class ShortDramaPlanningSpend
{
    public static function fromRows(array $rows, float $parentCharge = 0): array
    {
        $settled = 0.0;
        $providerUnits = 0;
        $pendingUnits = 0;
        foreach ($rows as $row) {
            $receipt = json_decode((string)($row['result_json'] ?? ''), true);
            if (!is_array($receipt) || !is_array($receipt['result'] ?? null)) continue;
            $billing = (array)($receipt['result']['billing'] ?? []);
            if (!$billing) continue;
            $providerUnits++;
            $status = (string)($billing['billing_status'] ?? '');
            if (in_array($status, ['settled', 'deducted'], true)) {
                $settled += (float)($billing['user_charge_points'] ?? 0);
            } elseif (in_array($status, ['reserved', 'pending_usage', 'settlement_failed'], true)) {
                $pendingUnits++;
            }
        }
        if ($providerUnits === 0) $settled = $parentCharge;
        return ['settled_points' => number_format($settled, 2, '.', ''),
            'provider_units' => $providerUnits, 'pending_units' => $pendingUnits];
    }
}
