<?php
/** No provider/database calls: capture the actual canvas-to-market boundary. */
namespace app\common\service\power {
    class MarketTextModelRuntimeService {
        public static function generate($tenant,$user,$params,$event=null){ return $params; }
    }
}
namespace {
    require dirname(__DIR__,4).'/vendor/autoload.php';
    use app\common\service\app\aigc_canvas\AigcCanvasService as Canvas;
    $checks=0;
    foreach(['generateText','streamText'] as $method){
        $r=new ReflectionMethod(Canvas::class,$method);$lines=file($r->getFileName());$body=implode('',array_slice($lines,$r->getStartLine()-1,$r->getEndLine()-$r->getStartLine()+1));
        if(!str_contains($body,"'_disable_model_fallback' => self::textModelSelection(\$params) !== []"))throw new RuntimeException($method.' permits silent fallback');$checks++;
        if(!str_contains($body,"'business_id' => (int)\$run['id']"))throw new RuntimeException($method.' loses task audit identity');$checks++;
    }
    foreach(['4','DeepSeek-V4-Flash'] as $selection){$result=Canvas::llmText(1,1,['model_code'=>$selection,'content'=>'OK','_disable_model_fallback'=>true,'business_id'=>42]);
        if($result['model_selection']['model_code']!==$selection || !$result['_disable_model_fallback'] || $result['business_id']!==42)throw new RuntimeException('Selection policy lost');$checks++;
    }
    $result=Canvas::llmText(1,1,['content'=>'Agent default']);if(isset($result['_disable_model_fallback']))throw new RuntimeException('Agent default policy changed');$checks++;
    echo "Canvas text selection: {$checks} checks passed; no provider calls\n";
}
