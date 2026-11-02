<?php
namespace Tests\Feature;
use PHPUnit\Framework\TestCase;
use app\common\service\power\MarketTextFailurePolicy as Policy;
final class MarketTextFailurePolicyTest extends TestCase
{
    public static function rejection():array{return ['content'=>'','usage'=>[],'provider_request_id'=>'resp_fixture',
        'protocol_evidence'=>['branch'=>'native_responses','terminal_seen'=>true,'terminal_status'=>'failed','output_present'=>false,'legacy_output_event_count'=>0],
        'protocol_diagnostic'=>['last_event'=>'response.failed','provider_code'=>'INSUFFICIENT_BALANCE','response_status'=>'failed','output_present'=>false,
            'output_items'=>0,'visible_chars'=>0,'reasoning_chars'=>0,'delta_count'=>0,'reasoning_delta_count'=>0,'function_delta_count'=>0]];}
    public function testOnlyDefinitiveBeforeGenerationBalanceFailureCanCloseWithoutUsage():void
    {
        $r=self::rejection();self::assertTrue(Policy::preGenerationRejection($r));
        foreach(['output_items','visible_chars','reasoning_chars','delta_count','reasoning_delta_count','function_delta_count']as$key){$p=$r;$p['protocol_diagnostic'][$key]=1;self::assertFalse(Policy::preGenerationRejection($p));}
        $p=$r;$p['usage']=['input_tokens'=>1];self::assertFalse(Policy::preGenerationRejection($p));
        $p=$r;$p['protocol_evidence']['terminal_seen']=false;self::assertFalse(Policy::preGenerationRejection($p));
        $p=$r;$p['protocol_diagnostic']['provider_code']='unknown';self::assertFalse(Policy::preGenerationRejection($p));
        $p=$r;$p['protocol_evidence']['output_present']=true;$p['protocol_diagnostic']['output_present']=true;self::assertTrue(Policy::preGenerationRejection($p));
        $p=$r;$p['usage']=['points_cost'=>1];self::assertFalse(Policy::preGenerationRejection($p));
    }
}
