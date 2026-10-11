<?php
require dirname(__DIR__,2).'/vendor/autoload.php';(new think\App())->initialize();
use app\common\service\open_platform\OpenStore as S;
use app\common\service\open_platform\OpenDocuments as D;
use think\facade\Db;
$c=config('database.connections.mysql');if($c['hostname']!=='127.0.0.1'||$c['database']!=='z_cn')throw new RuntimeException('Local transactional fixture only');
Db::startTrans();try{
 $t=987654326;$settings=S::settings(0);$settings['apps'][]='aigc_llm';unset($settings['enabled']);if(S::table('setting')->where('tenant_id',0)->find())S::table('setting')->where('tenant_id',0)->update(['config'=>S::encode($settings)]);else S::table('setting')->insert(['tenant_id'=>0,'enabled'=>1,'config'=>S::encode($settings)]+S::timestamp());S::table('setting')->insert(['tenant_id'=>$t,'enabled'=>1,'config'=>S::encode($settings)]+S::timestamp());
 $path='/api/v1/tools/aigc_llm/tasks';$prefix='docs_fixture_'.bin2hex(random_bytes(5));
 foreach(['model-a','model-b'] as $i=>$model){$config=['app_code'=>'aigc_llm','billable'=>true,'title'=>$model,'model'=>$model,'executor'=>'tool','input'=>['type'=>'object','properties'=>['model'=>['type'=>'string','enum'=>[$model]]]],'example'=>['model'=>$model],'responses'=>['200'=>['description'=>$model]]];S::table('contract')->insert(['code'=>$prefix.$i,'kind'=>'tool','path'=>$path,'method'=>'POST','status'=>'published','version'=>1,'product_id'=>0,'config'=>S::encode($config)]+S::timestamp());}
 $spec=D::openapi($t);$op=$spec['paths']->{$path}['post']??((array)$spec['paths'])[$path]['post'];
 if(count($op['x-contract-variants'])<2)throw new RuntimeException('A model contract was dropped');$variants=array_slice($op['x-contract-variants'],-2);
 if($variants[0]['operation']['requestBody']['content']['application/json']['example']->model!=='model-a'||$variants[1]['operation']['requestBody']['content']['application/json']['example']->model!=='model-b')throw new RuntimeException('Variant request example mismatch');
 if(count($op['requestBody']['content']['application/json']['schema']['anyOf'])<2)throw new RuntimeException('Multi-contract schema lost');
 echo "PASS multiple published models preserve individual schemas, responses and examples\n";
}finally{Db::rollback();}
