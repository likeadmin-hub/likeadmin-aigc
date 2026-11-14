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
        // ORM origins containing Raw expressions suppress later updates to that
        // field. Retain the exact persisted decimal bindings as plain strings.
        $data=$row->getData();$normalize=false;
        foreach(['upstream_price','usage_unit_size','sale_points']as$field)if(($data[$field]??null) instanceof \think\db\Raw){
            $value=$data[$field];$bind=$value->getBind();
            if($value->getValue()!=='?'||count($bind)!==1||!isset($bind[0][0],$bind[0][1])||$bind[0][1]!==\PDO::PARAM_STR)throw new \RuntimeException('SKU 批次价格绑定无效');
            $data[$field]=(string)$bind[0][0];$normalize=true;
        }
        if($normalize)$row=$row->newInstance($data);
        $id=(int)$row['id'];$key=(string)$row['sku_key'];
        if($oldKey!==''&&$oldKey!==$key){unset($this->rows[$oldKey][$id]);if(empty($this->rows[$oldKey]))unset($this->rows[$oldKey]);}
        $this->rows[$key][$id]=$row;
    }
}
