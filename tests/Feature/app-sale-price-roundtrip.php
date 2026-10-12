<?php
require dirname(__DIR__, 2) . '/vendor/autoload.php';
(new think\App())->initialize();
use think\facade\Db;
try {
    $name=Db::query('SELECT DATABASE() db')[0]['db'];
    if($name!=='x_cn' || !in_array((string)config('database.connections.mysql.hostname'), ['127.0.0.1', 'localhost'], true))throw new RuntimeException('Local database guard failed');
    $apps=['local_redraw','one_click_cleanup','product_suite','product_multi_angle','fashion_lookbook'];
    Db::startTrans();
    try {
        foreach(array_merge($apps,['product_image','hairstyle','fitting']) as $app){
            $class='app\\common\\service\\app\\aigc_'.$app.'\\Aigc'.str_replace(' ','',ucwords(str_replace('_',' ',$app))).'Service';
            foreach([null,0,8.5,null] as $price){
                if(in_array($app,$apps,true))$payload=['unit_price'=>$price];
                else {
                    $payload=['config_json'=>$class::config(1)['config_json']];
                    if($app==='product_image')$payload['config_json']['unit_price']=$price;
                    elseif($app==='hairstyle')$payload['config_json']['operation_prices']['hair_style']=$price;
                    else $payload['config_json']['mode_prices']['single']=$price;
                }
                $class::saveConfig(1,$payload);
                $data=$class::config(1);
                $actual=match($app){
                    'product_image'=>$data['config_json']['unit_price'],
                    'hairstyle'=>$data['config_json']['operation_prices']['hair_style'],
                    'fitting'=>$data['config_json']['mode_prices']['single'],
                    default=>$data['unit_price']
                };
                if($actual!==($price===null?null:(float)$price))throw new RuntimeException($app.' save/read mismatch expected='.var_export($price,true).' actual='.var_export($actual,true));
            }
        }
        foreach(['photo_restore','model_wear','style_transfer','background_removal','image_translate','outpaint'] as $app){
            $class='app\\common\\service\\app\\aigc_'.$app.'\\Aigc'.str_replace(' ','',ucwords(str_replace('_',' ',$app))).'Service';
            $rows=$class::priceDetail(1)['price_config'];
            if(!$rows)throw new RuntimeException($app.' has no configured test package');
            foreach([null,0,8.5,null] as $price){
                $rows[0]['unit_price']=$price;
                $class::savePrice(1,['price_config'=>$rows]);
                $actual=$class::priceDetail(1)['price_config'][0]['unit_price'];
                if($actual!==($price===null?null:(float)$price))throw new RuntimeException($app.' package save/read mismatch');
            }
        }
        echo "Fourteen apps: inherit -> free -> custom -> inherit save/read round trips passed; rolled back.\n";
    } finally {Db::rollback();}
} catch(Throwable $e){fwrite(STDERR,$e->getMessage()."\n");exit(1);}
