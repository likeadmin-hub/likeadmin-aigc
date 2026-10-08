<?php
namespace app\common\service\app\aigc_short_drama;

use RuntimeException;

/** Shared semantic repair rounds. Format recovery is bounded separately. */
final class ShortDramaRepairBudget
{
    private array $rounds = [];
    public function __construct(private int $limit = 2, array $saved = [])
    {
        foreach ($saved as $key) if (is_string($key)) $this->rounds[$key] = true;
    }
    public function consume(string $stage, int $round): void
    {
        $key = $stage . ':' . $round;
        if (isset($this->rounds[$key])) return; // replay is not a new repair
        if (count($this->rounds) >= $this->limit) throw new RuntimeException('本集自动内容修复已达到上限，候选剧本与已完成结果保留；请查看具体问题后调整要求或继续处理',429);
        $this->rounds[$key] = true;
    }
    public function snapshot(): array
    {
        return ['limit'=>$this->limit, 'used'=>count($this->rounds), 'rounds'=>array_keys($this->rounds)];
    }
}
