<?php
// Executable without application bootstrap or a database. Exercise actual batch
// resolution with mixed tenants, owners, corrupt mappings, and deleted children.
namespace think\facade {
    class Db {
        public static array $tables = [];
        public static int $queries = 0;
        public static function name($table) { return new TraceQuery(self::$tables[$table] ?? []); }
    }
    class TraceQuery {
        private array $rows;
        public function __construct($rows) { $this->rows = $rows; Db::$queries++; }
        public function where($field, $value) { $this->rows = array_values(array_filter($this->rows, fn($r) => $r[$field] == $value)); return $this; }
        public function whereIn($field, $values) { $this->rows = array_values(array_filter($this->rows, fn($r) => in_array($r[$field], $values, true))); return $this; }
        public function field($fields) { return $this; }
        public function select() { return $this; }
        public function toArray() { return array_map(fn($r) => array_intersect_key($r, array_flip(['id','user_id','provider_task_id'])), $this->rows); }
    }
}
namespace {
    require __DIR__ . '/../app/common/service/ai/UserTaskTraceService.php';
    use think\facade\Db;
    use app\common\service\ai\UserTaskTraceService;
    function check($condition, $message) { if (!$condition) throw new \RuntimeException($message); }
    Db::$tables['aigc_image_task'] = [
        ['id'=>11,'tenant_id'=>1,'user_id'=>7,'delete_time'=>0,'provider_task_id'=>'upstream-11','api_key'=>'NEVER EXPOSE'],
        ['id'=>12,'tenant_id'=>1,'user_id'=>8,'delete_time'=>0,'provider_task_id'=>'other-user'],
        ['id'=>13,'tenant_id'=>2,'user_id'=>7,'delete_time'=>0,'provider_task_id'=>'other-tenant'],
        ['id'=>14,'tenant_id'=>1,'user_id'=>7,'delete_time'=>123,'provider_task_id'=>'deleted'],
        ['id'=>15,'tenant_id'=>1,'user_id'=>7,'delete_time'=>0,'provider_task_id'=>''],
    ];
    $rows = [
        ['id'=>101,'tenant_id'=>1,'user_id'=>7,'image_task_ids'=>'[11,12,13,14,15]'],
        ['id'=>102,'tenant_id'=>1,'user_id'=>8,'image_task_id'=>12],
    ];
    $result = UserTaskTraceService::appendRows($rows);
    check($result[0]['upstream_task_ids'] === ['upstream-11'], 'Foreign owner, tenant, deleted or empty identifier leaked');
    check($result[1]['upstream_task_ids'] === ['other-user'], 'Correct second owner not resolved');
    check(Db::$queries === 1, 'List must batch all rows in one query per media table and tenant');
    check(!str_contains(json_encode($result), 'NEVER EXPOSE'), 'Provider fields leaked');
    $before = Db::$queries;
    UserTaskTraceService::appendMapped($result[0]);
    check(Db::$queries === $before, 'Formatter repeated already-batched lookup');
    check(UserTaskTraceService::appendMapped(['id'=>1,'image_task_id'=>11])['upstream_task_ids'] === [], 'Missing parent ownership must fail closed');
    echo "PASS: scoped upstream resolution, no secrets, batch query budget, no repeated formatter queries\n";
}
