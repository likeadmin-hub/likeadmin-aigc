<?php
namespace app\common\model\app\aigc_short_drama {
    class AigcShortDramaConfig {
        public static array $configs = [701 => ['default_text_model_id'=>'text-a'], 702 => ['default_text_model_id'=>'text-b']];
        private int $tenant;
        public static function whereIn($field, $ids): self { $row=new self(); $row->tenant=$ids[0]; return $row; }
        public function orderRaw($order): self { return $this; }
        public function findOrEmpty(): \ArrayObject {
            return new class(self::$configs[$this->tenant] ?? []) extends \ArrayObject {
                public function __construct(array $config) { parent::__construct(['config_json'=>json_encode($config)]); }
                public function isEmpty(): bool { return false; }
            };
        }
    }
}
namespace app\common\service\power {
    class MarketTextModelRuntimeService {
        public static array $calls=[];
        public static function resolveModel(int $tenant,$selection,bool $vision=false): array {
            $id=is_array($selection)?($selection['id']??''):$selection;
            self::$calls[]=[$tenant,$id,$vision];
            $allowed=$tenant===701?['text-a','old-text']:['text-b'];
            if ($id==='') $id=$allowed[0];
            if (!in_array($id,$allowed,true)) throw new \Exception('Unavailable');
            return ['id'=>$id,'name'=>$id,'model_code'=>$id];
        }
    }
}
