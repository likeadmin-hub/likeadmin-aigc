<?php
namespace think\facade {
    class Db {
        public static array $tables=[];
        public static function name($table) { return new \CanvasMemoryQuery($table); }
        public static function transaction($callback) { return $callback(); }
    }
    class Cache {
        public static array $values=[];
        public static function get($key,$default=null) { return self::$values[$key]??$default; }
        public static function set($key,$value) { self::$values[$key]=$value; }
    }
}
namespace {
    class CanvasMemoryQuery {
        private string $table; private array $filters=[]; private int $limit=1000;
        public function __construct($table) { $this->table=$table; }
        public function where($field,$operator=null,$value=null) {
            if (is_array($field)) foreach($field as $key=>$item) $this->filters[]=[$key,'=',$item];
            else $this->filters[]=[$field,$value===null?'=':$operator,$value??$operator];
            return $this;
        }
        public function lock($lock) { return $this; }
        public function order($field) { return $this; }
        public function limit($limit) { $this->limit=$limit;return $this; }
        private function matches($row) { foreach($this->filters as [$field,$operator,$value]) if ($operator==='>'?($row[$field]<=$value):(($row[$field]??null)!=$value)) return false;return true; }
        public function select() { $rows=[];foreach(\think\facade\Db::$tables[$this->table]??[] as $row) if ($this->matches($row)) $rows[]=$row;$rows=array_slice($rows,0,$this->limit);return new class($rows) { private array $rows;public function __construct($rows) {$this->rows=$rows;} public function toArray() {return $this->rows;} }; }
        public function find() { return $this->select()->toArray()[0]??null; }
        public function value($key) { return $this->find()[$key]??null; }
        public function count() { return count($this->select()->toArray()); }
        public function update($values) { $count=0;foreach(\think\facade\Db::$tables[$this->table] as &$row) if ($this->matches($row)) {$row=array_replace($row,$values);$count++;}return $count; }
    }
}
namespace app\common\service\app\aigc_short_drama {
    class AigcShortDramaService {
        public static array $calls=[];
        public static function message($tenant,$user,$params) {
            self::$calls[]=$params;
            $source=\think\facade\Db::name('aigc_short_drama_script_task')->where('task_id',$params['task_id'])->find();
            $new=$source;$new['task_id']='revised';$new['result_json']=str_replace('old dialogue','new dialogue',$source['result_json']);
            \think\facade\Db::$tables['aigc_short_drama_script_task'][]=$new;
            \think\facade\Db::name('aigc_short_drama_project')->where('id',$params['project_id'])->update(['last_task_id'=>'revised']);
            return ['task_id'=>'revised'];
        }
        public static function retry($tenant,$user,$id) { self::$calls[]=['retry'=>$id]; }
    }
}
namespace app\common\service\power {
    class MarketTextModelRuntimeService {
        public static array $models=[];public static array $errors=[];public static array $calls=[];
        public static function options($tenant,$vision=false) { return array_values(array_filter(self::$models,static fn($m)=>!$vision || !empty($m['supports_vision']))); }
        public static function resolveRoutedModel($tenant,$selection,$vision=false) {
            $models=self::options($tenant,$vision);
            foreach($models as $m) if ($m['id']===($selection['id']??'')) return $m;
            if (!$models) throw new \Exception('no models');return $models[0];
        }
        public static function generate($tenant,$user,$params,$event=null) {
            self::$calls[]=$params;
            if(isset(self::$errors[$params['model_selection']['id']])) throw self::$errors[$params['model_selection']['id']];
            return ['content'=>'vision description'];
        }
    }
}
namespace app\common\service\app\aigc_short_drama\canvas_agent {
    class ConversationImages { public static function urls($tenant,$user,$context) { return $context['test_images']??[]; } }
    class FeatureGate { public static function assertEnabled($tenant) {} public static function executionEnabled($tenant) {return true;} }
}
