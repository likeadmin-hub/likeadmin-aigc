<?php
namespace app\common\service\power;

use app\common\model\power\PowerMarketSku;

/** Current, locked rows for one product inside its synchronization transaction. */
final class MarketSkuSyncBatch
{
    private int $productId;
    private array $rows=[];

    public function __construct(int $productId)
    {
        $this->productId=$productId;
        foreach(PowerMarketSku::where('product_id',$productId)->order('id')->lock(true)->select() as$row)$this->remember($row);
    }
    public function productId(): int {return $this->productId;}
    public function find(string $key): ?PowerMarketSku
    {
        $rows=$this->rows[$key]??[];
        return $rows?reset($rows):null;
    }
    public function masked(): array {return array_values($this->rows['******']??[]);}
    public function remember(PowerMarketSku $row,string $oldKey=''): void
    {
        $id=(int)$row['id'];$key=(string)$row['sku_key'];
        if($oldKey!==''&&$oldKey!==$key){unset($this->rows[$oldKey][$id]);if(empty($this->rows[$oldKey]))unset($this->rows[$oldKey]);}
        $this->rows[$key][$id]=$row;
    }
}
