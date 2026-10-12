<?php
require dirname(__DIR__,2).'/vendor/autoload.php';(new think\App())->initialize();
use app\common\service\open_platform\{OpenMarketResponse as R,OpenStore as S};
function check($ok,$why){if(!$ok)throw new RuntimeException($why);}
$body='{"code":1,"data":{"task_id":"upstream-original-id","frozen_points":999,"actual_points":700,"usage":{"total_tokens":17,"points_cost":700,"actual_points":700},"result":{"images":[],"content":{"actual_points":123,"text":"user JSON is opaque"}},"api_key":"supplier-secret","tenant_cost":88}}';
$projected=R::project($body,['reserved'=>30,'charged'=>12]);$data=json_decode($projected);
check($data->data->task_id==='upstream-original-id'&&(float)$data->data->frozen_points===30.&&(float)$data->data->actual_points===12.&&(float)$data->data->usage->points_cost===12.,'Supplier cost was exposed or task ID changed');
check($data->data->usage->total_tokens===17&&$data->data->result->content->actual_points===123&&$data->data->result->images===[],'Projection changed usage or opaque business output');
check(!isset($data->data->api_key,$data->data->tenant_cost),'Supplier control fields leaked');
$object=R::project('{"code":1,"data":{"result":{},"usage":{"points_cost":7}}}',['charged'=>3]);check(json_decode($object)->data->result instanceof stdClass,'Empty object became an array');
$plain="data: {\"choices\":[]}\n\n";check(R::project($plain,[])===$plain,'Non-JSON protocol changed');
$json='{"choices":[{"message":{"content":"hello"}}],"usage":{"total_tokens":8}}';check(R::project($json,[])===$json,'Unchanged OpenAI response was reserialized');
$legacy=json_decode(R::project('{"usage":{"points":"100","total_tokens":8}}',['charged'=>1.234]));check($legacy->usage->points==='1.23'&&$legacy->usage->total_tokens===8,'Legacy user charge alias or string type changed');
echo "PASS retail protocol amounts, original IDs/usage, opaque results and JSON object preservation\n";
