<?php
/** Isolated model, contract and wallet fixtures. Supplier responses are literals. */
require dirname(__DIR__,2).'/vendor/autoload.php';

use app\common\service\open_platform\{OpenNativeImageItems as Items, OpenNativeMarketPricing as Native, OpenMarketResponse as Response};
use app\common\service\open_platform\{OpenStore as Store, OpenManagement as Management, OpenContracts as Contracts,
    OpenCallContext as Context, OpenVerification, OpenGateway as Gateway, OpenMarketBilling as MarketBilling,
    OpenMarketContractCompiler as Compiler, OpenVault, OpenDirectory, OpenPublicDocumentation};
use app\common\model\power\{PowerMarketSku as Sku, PowerMarketProduct as Product};
use app\common\service\power\MarketImageItemBilling as Billing;
use app\common\service\power\{MarketPriceRuleService as Rates, TenantPowerMarketService};
use think\facade\Db;

function itemAssert(bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
}
$input = Billing::declaration([
    'price'=>['points'=>3], 'meter_summary'=>'生成张数', 'charge_base_mode'=>'add',
    'charge_items'=>[['code'=>'input_images','unit'=>'input_image','rate'=>2,'included_count'=>1]],
], 'official-33-f9a445be9ef6a7f58d');
$raw = ['model'=>'official-33-f9a445be9ef6a7f58d','params'=>[
    'count'=>2,'image_urls'=>['https://fixtures.invalid/a.png','https://fixtures.invalid/a.png'],
    'elements'=>[['name'=>'subject']],
]];
$product=['model_type'=>'image','resource_type'=>'model'];
$binding=Items::binding($product,['source_payload'=>['image_item_billing'=>$input]]);
itemAssert($binding['mode']==='image_count' && $binding['basis']==='image','Reviewed native image count binding');
$itemFacts=Items::facts($input,$raw,[]);
itemAssert($itemFacts===['input_count'=>3,'quantity'=>2],'Count follows the wire and preserves duplicate slots');
itemAssert(Items::facts($input,['count'=>10],[])['quantity']===10,'Valid supplier batch sizes are not capped by the quote adapter');
$price=Billing::prices(['id'=>1,'product_id'=>1,'source_payload'=>['image_item_billing'=>$input]],1,[],[]);
$native=['kind'=>'image','submitted_images'=>2,'market_snapshot'=>['image_item_pricing'=>$price,'image_item_facts'=>['input_count'=>3]]];
$completed=['data'=>['result'=>['results'=>[['type'=>'image','url'=>'https://fixtures.invalid/a.png','transfer_status'=>'success']]]]];
$charge=Items::settle($native,$completed,false);
itemAssert($charge['tenant']===7. && $charge['user']===7. && $charge['usage']['units']===1.,'Partial image output keeps input charges and settles actual output');
itemAssert(Items::settle($native,[],true)['user']===0.,'Confirmed failure has no charge');
$reply='{"data":{"token_billing":{"status":"settled","actual_points":"888.000000","items":[{"code":"image_output","label":"图片输出","author":"","tokens":1000,"points_per_million_tokens":"888000","points":"888"}]}}}';
itemAssert(Response::bodySupported($reply),'Registered receipt envelope is accepted for billing');
$item=Billing::details(['mode'=>'token_items','items'=>['image_output'=>['tenant'=>'2000.000000','label'=>'图片输出','unit'=>'百万 Token']]],[],1,['quantities'=>['image_output'=>1000],'adjustment'=>1]);
$body=Response::project($reply,['charged'=>2.,'reserved'=>5.,'image_billing'=>$item]);
itemAssert(!str_contains($body,'888') && json_decode($body,true)['data']['token_billing']['actual_points']==='2.00','Retail projection uses the frozen local rates and total');
echo "PASS native SKU binding, unchanged input slots, partial output, refund and retail receipt projection\n";

(new think\App())->initialize();
$database = config('database.connections.mysql');
itemAssert($database['hostname']==='127.0.0.1' && $database['database']==='z_cn','Local fixture database only');
$wallets = static fn() => [(float)Db::name('tenant')->where('id',1)->value('point_balance'),
    (float)Db::name('user')->where('id',1)->value('user_money')];
$initial = $wallets();
Db::startTrans();
try {
    Db::name('power_market_price_rule')->whereIn('owner_id',[0,1])->delete();
    request()->tenantId = 1;
    request()->userId = 1;
    $model = Product::where(['resource_type'=>'model','model_type'=>'image','status'=>1])->find()->toArray();
    $sku = Sku::where(['status'=>1,'sale_status'=>1])->find()->toArray();
    unset($model['id'],$sku['id']);
    $model['create_time'] = $model['update_time'] = $sku['create_time'] = $sku['update_time'] = time();

    $tokenDeclaration = ['price'=>['points'=>0],'reserve_upper_points'=>5,'charge_base_mode'=>'replace','charge_items'=>[]];
    foreach (['text_input_uncached','text_input_cached','image_input_uncached','image_input_cached','image_output'] as $code) {
        $tokenDeclaration['charge_items'][] = ['code'=>$code,'unit'=>'token','unit_size'=>1000000,'rate'=>1000];
    }
    $token = Billing::declaration($tokenDeclaration,'native-fixture');
    $svgDeclaration = $tokenDeclaration;
    foreach (['svg_author_input','svg_author_output'] as $code) {
        $svgDeclaration['charge_items'][] = ['code'=>$code,'author'=>'gpt-5.6-luna','unit'=>'token','unit_size'=>1000000,'rate'=>2000];
    }
    $svg = Billing::declaration($svgDeclaration,'native-fixture');
    $svgFacts = Items::facts($svg,['output_format'=>'svg'],[]);
    $svgPrice = Billing::prices(['id'=>0,'product_id'=>0,'source_payload'=>['image_item_billing'=>$svg]],1,[],[]);
    $svgNative = ['kind'=>'image','submitted_images'=>1,'market_snapshot'=>[
        'image_item_pricing'=>$svgPrice,'image_item_facts'=>$svgFacts,
    ]];
    $svgQuery = $completed;
    $svgQuery['data']['result']['results'][0]['url'] = 'https://fixtures.invalid/ready.svg';
    $svgQuery['data']['token_billing'] = ['status'=>'settled','actual_points'=>3,'items'=>array_map(static fn($line)=>[
        'code'=>$line['code'],'author'=>$line['author']??'','tokens'=>in_array($line['code'],['svg_author_input','image_output'],true)?1000:0,
        'points_per_million_tokens'=>$line['rate'],
    ],$svgDeclaration['charge_items'])];
    $svgCharge = Items::settle($svgNative,$svgQuery,false);
    itemAssert($svgCharge['user']===3. && count($svgCharge['usage']['billing_details']['items'])===7,'SVG author tokens were lost or duplicated');
    $svgQuery['token_billing'] = array_replace($svgQuery['data']['token_billing'],['actual_points'=>4]);
    itemAssert(Items::settle($svgNative,$svgQuery,false)===null,'Conflicting receipt aliases settled');

    foreach (['image_count','token_items','failed'] as $case) {
        $countMode = $case==='image_count';
        $tag = bin2hex(random_bytes(5));
        $modelCode = 'opf_item_'.$tag;
        $wire = ['model'=>$modelCode,'channel'=>'native-image-fixture','prompt'=>'Mock endpoint only'];
        $fixtureImage = 'https://93.184.216.34/local-fixture.png'; // Literal IP for URL validation only. No downloads.
        if ($countMode) $wire += ['count'=>2,'image_urls'=>[$fixtureImage,$fixtureImage],
            'elements'=>[(object)['name'=>'subject','image_urls'=>[$fixtureImage,$fixtureImage]]]];
        $query = ['code'=>1,'msg'=>'success','data'=>['task_id'=>'local-'.$tag,'status'=>'completed',
            'type'=>'image','result'=>['results'=>[['type'=>'image','url'=>'https://fixtures.invalid/ready.png','transfer_status'=>'success']]]]];
        $meta = ['method'=>'POST','call_type'=>2,'protocol'=>'image_generate','supports_reference_images'=>true,
            'max_reference_images'=>4,'params_schema'=>[
                'model'=>['type'=>'string'],'channel'=>['type'=>'string'],'prompt'=>['type'=>'string','required'=>true],
                'count'=>['type'=>'integer','minimum'=>1,'maximum'=>10],
                'image_urls'=>['type'=>'array','items'=>['type'=>'string']],
                'elements'=>['type'=>'array','items'=>['type'=>'object','properties'=>[
                    'name'=>['type'=>'string'],'image_urls'=>['type'=>'array','items'=>['type'=>'string']],
                ]]],
            ],'developer_doc_content'=>"\x60\x60\x60json\n".Store::encode($wire)."\n\x60\x60\x60\n\x60\x60\x60json\n".Store::encode($query)."\n\x60\x60\x60"];
        $fixtureProduct = Product::create(array_replace($model,['name'=>'Native item '.$case,
            'upstream_model_code'=>$modelCode,'upstream_channel_code'=>'native-image-fixture',
            'product_code'=>$modelCode,'upstream_resource_key'=>$modelCode,'source_payload'=>['market_metadata'=>$meta]]));
        $fixtureSku = Sku::create(array_replace($sku,['product_id'=>$fixtureProduct['id'],'sku_key'=>$modelCode,
            'title'=>'Fixture '.$case,'upstream_price'=>$countMode?3:0,'sale_points'=>$countMode?3:0,
            'usage_unit'=>'per_call','usage_unit_size'=>1,'locked_params'=>[],'source_payload'=>[
                'image_item_billing'=>$countMode?$input:$token,
            ]]));
        [$line,$platform,$retail] = $countMode ? ['input_images',5,7] : ['image_output',1200,1350];
        foreach ([0=>$platform,1=>$retail] as $owner=>$value) {
            $data = $fixtureSku->toArray();
            $current = array_column(Billing::display(Billing::prices($data,$owner),$owner)['items'],null,'code');
            Rates::saveImageItems($owner,$data,[$line=>['expected'=>$current[$line]['sale'],'value'=>$value]]);
            $settings = Store::settings($owner);
            unset($settings['enabled']);
            $settings['models'][] = (string)$fixtureProduct['id'];
            $settings['features']['playground'] = true;
            Management::persistSettings($owner,true,$settings);
        }
        $retailProduct = TenantPowerMarketService::detail(1,(int)$fixtureProduct['id']);
        $compiled = Compiler::compileProduct($retailProduct);
        itemAssert(count($compiled['drafts'])===1,'Compiler rejected split SKU '.Store::encode($compiled['blockers']));
        $draft = $compiled['drafts'][0];
        $rule = $draft['config']['billing'][0];
        itemAssert($draft['path']==='/api/v1/tasks' && ($rule['native_meter']['version']??null)===Items::VERSION,
            'Published contract must preserve path and bind itemized SKU');
        $values = Contracts::validate($draft);
        $contract = $values+['version'=>1,'status'=>'published']+Store::timestamp();
        Store::table('contract')->insert($contract);
        $catalog = OpenDirectory::filter('/api/v1/models',[['model_code'=>$modelCode,'channel_code'=>'native-image-fixture','pricing'=>[]]],[$retailProduct]);
        $publicRates = array_column($catalog[0]['pricing']['pricing_matrix'][0]['billing_details']['items'],null,'code');
        itemAssert($publicRates[$line]['unit_points']===(float)$retail && !isset($publicRates[$line]['cost'],$publicRates[$line]['sale']),
            'Public rate projection leaked wholesale prices or ignored retail rates');
        $guides = OpenPublicDocumentation::fallbacks(1,[],[$contract]);
        itemAssert(str_contains($guides[0]['content'],$retail.' 积分 / '.($countMode?'张输入图':'百万 Token')),
            'Pricing documentation displayed scalar fallback instead of itemized retail rates');
        $snapshotRowCount = Store::table('request')->count();
        $before = $wallets();
        $quote = Management::quote(1,1,$draft['code'],$wire);
        [$operation,$params] = Contracts::forQuote($draft['code'],$wire);
        $budget = MarketBilling::quote(1,$operation,$params);
        $expected = $countMode ? [16.,20.] : [6.,6.75];
        itemAssert([$budget['tenant'],$budget['user']]===$expected && $quote['reserved_points']===$expected[1]
            && $quote['items'][0]['billing_details']===$budget['snapshot']['rules'][0]['billing_details']
            && $wallets()===$before && Store::table('request')->count()===$snapshotRowCount,'Estimate/submit price drift or estimate side effects');
        Native::assertPreparedTransport($budget['snapshot'],$params);
        if ($countMode) {
            $maxQuote = Management::quote(1,1,$draft['code'],array_replace($wire,['count'=>10]));
            itemAssert($maxQuote['reserved_points']===44.,'Reviewed batch upper bound did not price all ten outputs');
            $nested = ['model'=>$modelCode,'count'=>10,'params'=>(object)array_diff_key($wire,['model'=>true,'channel'=>true])];
            $nestedBudget = MarketBilling::quote(1,$operation,$nested);
            itemAssert($nestedBudget===$budget,'Nested raw business params did not take precedence over root');
        }
        $budget['snapshot']['customer_callback'] = ['url'=>'https://fixtures.invalid/callback','secret_blob'=>OpenVault::seal('local-only')];

        $rid = 'req_image_item_'.$tag;
        $row = ['request_id'=>$rid,'tenant_id'=>1,'user_id'=>1,'key_id'=>0,'kind'=>'market','method'=>'POST','path'=>'/api/v1/tasks',
            'status'=>'accepted','billing_status'=>'pending','active'=>1,'upstream_task_id'=>'local-'.$tag,'consumption_ids'=>'[]',
            'snapshot'=>Store::encode($budget['snapshot']),'payload_hash'=>hash('sha256',$rid),'response_blob'=>'',
            'budget_user'=>$budget['user'],'budget_tenant'=>$budget['tenant']]+Store::timestamp();
        $row['id'] = Store::table('request')->insertGetId($row);
        Context::enter($rid);
        MarketBilling::reserve($row,$budget);
        $balanceDelta = static function () use ($before,$wallets): array {
            $after = $wallets();
            return [round($before[0]-$after[0],2),round($before[1]-$after[1],2)];
        };
        $read = static fn() => Store::table('request')->where('request_id',$rid)->find();
        itemAssert($balanceDelta()===$expected,'Wallet debits differ from quote');
        $observe = static function (array $mock) use ($row,$operation): void {
            $wire = ['status'=>200,'headers'=>['content-type'=>'application/json'],'body'=>Store::encode($mock)];
            itemAssert(Gateway::marketQueryAccepted($wire,$row['upstream_task_id'],$operation),'Mock gateway receipt rejected');
            Gateway::observeTask($row,$mock);
        };
        if ($case==='failed') {
            $query['data']['status'] = 'failed';
            unset($query['data']['result']);
        } elseif (!$countMode) {
            $observe($query);
            itemAssert($read()['billing_status']==='pending_usage' && $balanceDelta()===$expected
                && Store::table('webhook')->where('request_id',$rid)->count()===0,'Missing receipt released the reserve or notified early');
            $receipt = ['status'=>'settled','actual_points'=>'1.000000','items'=>array_map(static fn($item)=>[
                'code'=>$item['code'],'label'=>$item['code'],'author'=>'','tokens'=>$item['code']==='image_output'?1000:0,
                'points_per_million_tokens'=>'1000.000000','points'=>$item['code']==='image_output'?'1.000000':'0.000000',
            ],$tokenDeclaration['charge_items'])];
            $bad = $query;
            $bad['data']['token_billing'] = array_replace($receipt,['actual_points'=>'5.01']);
            $observe($bad);
            itemAssert($read()['billing_status']==='pending_usage' && $balanceDelta()===$expected,'Out-of-bounds receipt escaped reconciliation');
            $bad['data']['token_billing'] = $receipt;
            $bad['data']['token_billing']['items'][0]['points_per_million_tokens'] = '-1000';
            $observe($bad);
            itemAssert($read()['billing_status']==='pending_usage' && $balanceDelta()===$expected,'Malformed rate escaped reconciliation');
            $query['data']['token_billing'] = $receipt;
        }
        Rates::saveImageItems(1,$fixtureSku->toArray(),[$line=>['expected'=>$retail,'value'=>$retail*2]]);
        $observe($query);
        $done = $read();
        $settled = match ($case) {'image_count'=>[13.,17.],'token_items'=>[1.2,1.35],'failed'=>[0.,0.]};
        $state = match ($case) {'image_count'=>'partial_success','token_items'=>'success','failed'=>'failed'};
        itemAssert($done['status']===$state && $done['billing_status']===($case==='failed'?'refunded':'settled')
            && [(float)$done['actual_tenant'],(float)$done['actual_user']]===$settled
            && $balanceDelta()===$settled,'Settled amount or task outcome did not follow frozen price');
        OpenVerification::assertLedger($done);
        if ($case==='token_items') {
            $retailBody = Store::json(Response::project(Store::encode($query),Response::amounts($done)));
            itemAssert($retailBody['data']['token_billing']['actual_points']==='1.35'
                && $retailBody['data']['task_id']===$query['data']['task_id']
                && $retailBody['data']['result']===$query['data']['result'],'Response exposed supplier charges or rewrote business result');
            $webhook = Store::table('webhook')->where('request_id',$rid)->find();
            $payload = Store::json(OpenVault::open($webhook['payload_blob']));
            itemAssert($payload['data']['data']['token_billing']['actual_points']==='1.35'
                && $webhook['status']==='pending','Callback did not use the settled retail receipt');
        }
        $observe($query);
        itemAssert($balanceDelta()===$settled && Store::table('webhook')->where('request_id',$rid)->count()===1,
            'Repeated callback triggered another debit or delivery');
        Context::leave();
        echo "PASS native $case compiler, online estimate, wallet reservation, mocked query, frozen settlement and ledger\n";
    }
} finally {
    Context::leave();
    Db::rollback();
}
itemAssert($wallets()===$initial,'Fixture transaction changed wallet');
echo "PASS fixture rollback restored both wallets. No supplier transport.\n";
