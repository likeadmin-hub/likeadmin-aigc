<?php
require dirname(__DIR__,2).'/vendor/autoload.php';
(new \think\App())->initialize();
use app\common\service\app\aigc_image\{AigcImageChannelService,AigcImageService};
use app\common\service\power\MarketGenerationCatalogService;
$tenant=(int)($argv[1]??1);$config=AigcImageChannelService::userConfig($tenant);$checked=[];
$catalog=MarketGenerationCatalogService::options($tenant,'image');
$ids=array_column($config['channels'],'code');
foreach($catalog['channels'] as $option){
 $id=$option['value']??$option['id'];
 if(!in_array($id,$ids,true))throw new RuntimeException('Missing creation channel: '.$id);
 if(AigcImageChannelService::normalizeRuntimeChannelCode($id)!==$id)throw new RuntimeException('Channel identity changed: '.$id);
 $channel=$config['channels'][array_search($id,$ids,true)];$quality=$channel['qualities'][0];$spec=$quality['ratios'][0];
 $params=['channel'=>$id,'quality'=>$quality['value'],'ratio'=>$spec['ratio'],'quantity'=>1];
 $selection=AigcImageChannelService::resolveSelection($tenant,$params);
 $quote=AigcImageService::estimate($tenant,$params);
 if((int)$quote['market_product_id']!==(int)$option['market_product_id'])throw new RuntimeException('Quoted wrong product: '.$id);
 if((int)$quote['market_sku_id']!==(int)$selection['spec']['market_sku_id'])throw new RuntimeException('Quoted wrong SKU: '.$id);
 $checked[]=$id;
}
echo 'PASS '.count($checked)." creation channels resolve and quote the selected product/SKU; paid_requests=0\n";
