<?php
/** Isolated read-view fixtures: no real publication, generation or price changes. */
require dirname(__DIR__,2).'/vendor/autoload.php';(new think\App())->initialize();
use app\common\service\open_platform\{OpenDocuments as D,OpenStore as S,OpenContracts as C,OpenVerification as V,OpenPublicDocumentation as P};
use app\common\model\power\{PowerMarketProduct as Product,PowerMarketSku as Sku,TenantPowerMarketSkuPrice as Retail};
use think\facade\Db;
function checkPublicDocs(bool $ok,string $why): void {if(!$ok)throw new RuntimeException($why);}
function categoryDocs(array $docs,string $category): array {return array_values(array_filter($docs,fn($d)=>$d['category']===$category));}
$db=config('database.connections.mysql');checkPublicDocs($db['hostname']==='127.0.0.1'&&$db['database']==='z_cn','Local rollback only');
$original=S::table('contract')->where('status','published')->select()->toArray();$hashes=[];foreach($original as $row)$hashes[$row['id']]=[hash('sha256',$row['config']),V::digest($row)];
$storedDocs=S::table('document')->order('id')->select()->toArray();$requests=S::table('request')->count();$tenant=987654399;$tag=bin2hex(random_bytes(5));
Db::startTrans();try{
    $empty=D::publicLists($tenant);$prices=categoryDocs($empty,'定价');$changes=categoryDocs($empty,'更新日志');
    checkPublicDocs(count($prices)===1&&count($changes)===1,'Missing generated pricing/changelog fallback for a tenant with no visible contracts');
    foreach([$prices[0],$changes[0]] as $doc)checkPublicDocs(($doc['generated']??false)===true&&$doc['status']==='generated'&&!empty($doc['source'])&&str_contains($doc['content'],'暂无')&&str_contains($doc['content'],'/user/developer/catalog'),'Empty catalog fallback invented a publication or omitted useful navigation');
    $base=Product::find(61)->toArray();unset($base['id']);$base['create_time']=time();$base['update_time']=time();
    $product=Product::create(array_replace($base,['name'=>'Public docs fixture','product_code'=>'public_docs_'.$tag,'upstream_resource_key'=>'public_docs_'.$tag]));
    $template=Sku::where(['product_id'=>61,'status'=>1,'sale_status'=>1])->find()->toArray();unset($template['id']);$template['create_time']=time();$template['update_time']=time();
    $sku=Sku::create(array_replace($template,['product_id'=>$product['id'],'sku_key'=>'published_spec_'.$tag,'sale_points'=>10,'usage_unit'=>'character','usage_unit_size'=>1000,'locked_params'=>[]]));
    $unpublished=Sku::create(array_replace($template,['product_id'=>$product['id'],'sku_key'=>'unpublished_spec_'.$tag,'sale_points'=>900,'usage_unit'=>'character','usage_unit_size'=>1000,'locked_params'=>[]]));
    Retail::create(['tenant_id'=>$tenant,'sku_id'=>$sku['id'],'sale_status'=>1,'sale_points'=>25,'create_time'=>time(),'update_time'=>time()]);
    $global=S::settings(0);$global['models'][]=(string)$product['id'];unset($global['enabled']);S::table('setting')->where('tenant_id',0)->update(['config'=>S::encode($global)]);
    S::table('setting')->insert(['tenant_id'=>$tenant,'enabled'=>1,'config'=>S::encode(array_replace($global,['models'=>[(string)$product['id']]]))]+S::timestamp());
    $code='public.docs.'.$tag;
    $media=['schema'=>['type'=>'object'],'examples'=>['typed'=>['value'=>(object)['empty'=>new \stdClass(),'list'=>[]]]]];
    $config=['executor'=>'json','role'=>'submit','billable'=>true,'title'=>'Fixture API','input'=>['type'=>'object','properties'=>['text'=>['type'=>'string']]],'example'=>['text'=>'hello'],'responses'=>['200'=>['description'=>'OK','content'=>['application/json'=>$media]]],'billing'=>[['sku_id'=>(int)$sku['id'],'basis'=>'input','max_units'=>1]]];
    $row=['code'=>$code,'kind'=>'market','method'=>'POST','path'=>'/api/v1/apps/public_docs_fixture/output','product_id'=>$product['id'],'version'=>3,'status'=>'published','config'=>C::encodeConfig($config)]+S::timestamp();$id=S::table('contract')->insertGetId($row);$hash=hash('sha256',$row['config']);$digest=V::digest($row);
    S::table('contract')->insert(array_replace($row,['code'=>$code.'.hidden','product_id'=>987654398,'config'=>C::encodeConfig(array_replace($config,['title'=>'Hidden unpublished permission marker']))]));
    $guide=['tenant_id'=>0,'slug'=>'public-guide-'.$tag,'version'=>1,'title'=>'Fixture API guide','category'=>'指南','status'=>'published','content'=>"# Fixture API\n\nPOST `/api/v1/apps/public_docs_fixture/output`\n\n## 接入\n\nA concise public introduction with [help](/docs).\n\n## 参数\n\nFurther explanation must not become the full description.",'contract_code'=>$code,'sort'=>1]+S::timestamp();S::table('document')->insert($guide);
    $docs=D::publicLists($tenant);$pricing=categoryDocs($docs,'定价')[0];$change=categoryDocs($docs,'更新日志')[0];
    checkPublicDocs(str_contains($pricing['content'],'25')&&str_contains($pricing['content'],'1000')&&str_contains($pricing['content'],$sku['sku_key']),'Generated prices ignored effective tenant retail or SKU unit');
    checkPublicDocs(!str_contains($pricing['content'],$unpublished['sku_key'])&&!str_contains($pricing['content'],'Hidden unpublished permission marker')&&!str_contains($pricing['content'],'platform_sale_points'),'Unpublished SKU, foreign contract or cost leaked');
    checkPublicDocs(str_contains($change['content'],'v3')&&!str_contains($change['content'],'Hidden unpublished permission marker'),'Change log invented or omitted visible versions');
    $other=D::publicLists($tenant+1);checkPublicDocs(!str_contains(categoryDocs($other,'定价')[0]['content'],$code),'Cross-tenant generated price leak');
    $op=((array)D::openapi($tenant)['paths'])[$row['path']]['post'];checkPublicDocs($op['description']==='A concise public introduction with help.','Public description did not use a bounded introduction');
    checkPublicDocs($op['responses']['200']['content']['application/json']['examples']['typed']['value']->empty instanceof \stdClass,'Description projection collapsed a typed response instance');
    $config['description']='Existing author description';S::table('contract')->where('id',$id)->update(['config'=>C::encodeConfig($config)]);$op=((array)D::openapi($tenant)['paths'])[$row['path']]['post'];checkPublicDocs($op['description']===$config['description'],'Authored operation description was overwritten');S::table('contract')->where('id',$id)->update(['config'=>$row['config']]);
    $manual=$guide;$manual['slug']='manual-pricing-'.$tag;$manual['category']='定价';$manual['contract_code']='';$manual['content']='Authored global pricing';S::table('document')->insert($manual);$manual['tenant_id']=$tenant;$manual['content']='Tenant override pricing';S::table('document')->insert($manual);
    $prices=categoryDocs(D::publicLists($tenant),'定价');checkPublicDocs(count($prices)===1&&$prices[0]['content']==='Tenant override pricing'&&empty($prices[0]['generated']),'Generated document displaced an authored tenant override');
    checkPublicDocs(!categoryDocs(D::publicLists($tenant,'no match '.$tag),'定价'),'Search falsely generated a category hidden by an authored document');
    $draft=$manual;$draft['slug']='draft-changelog-'.$tag;$draft['category']='更新日志';$draft['status']='draft';S::table('document')->insert($draft);checkPublicDocs((categoryDocs(D::publicLists($tenant),'更新日志')[0]['generated']??false)===true,'Draft suppressed a public fallback');
    $manual['slug']='manual-change-'.$tag;$manual['category']='更新日志';S::table('document')->insert($manual);checkPublicDocs(empty(categoryDocs(D::publicLists($tenant),'更新日志')[0]['generated']),'Authored changelog not respected');
    checkPublicDocs(hash('sha256',S::table('contract')->where('id',$id)->value('config'))===$hash&&V::digest(S::table('contract')->where('id',$id)->find())===$digest,'Read views changed stored config or digest');
    checkPublicDocs(P::introduction("# Title\n\n```json\n{}\n```\n\n".str_repeat('plain ',100))!==''&&mb_strlen(P::introduction(str_repeat('plain ',100)))<=280,'Introduction bound failed');
    checkPublicDocs(P::introduction("# Title\n\ncompiler source_digest internal\n\nPublic integration paragraph.")==='Public integration paragraph.','Internal compiler paragraph reached public summary');
}finally{Db::rollback();}
checkPublicDocs(S::table('document')->order('id')->select()->toArray()===$storedDocs&&S::table('request')->count()===$requests,'Read view fixture changed real docs or requests');
foreach($hashes as $id=>$pair){$row=S::table('contract')->where('id',$id)->find();checkPublicDocs(hash('sha256',$row['config'])===$pair[0]&&V::digest($row)===$pair[1],'Existing published contract changed');}
echo "PASS generated current pricing/versions, bound published SKU retail, no-contract guidance, tenant isolation, authored overrides/search, bounded public descriptions and immutable typed contracts; rollback only\n";
