<?php
declare(strict_types=1);
namespace B79SavedContracts;
use think\facade\Db;
use app\common\service\power\{MarketGenerationSchema as Schema,MarketGenerationReviewedContract as Reviewed,MarketGenerationAdapterRegistry as Registry,MarketGenerationNativeContract as Native,MarketImageModelRuntimeService as Images,MarketVideoRuntimeService as Videos};

// CLI only: coordinator runs on local develop. Local read-only transaction; no transport, quotes, synchronization or credential access.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
ini_set('display_errors','0'); ini_set('log_errors','0'); error_reporting(E_ALL);
$floor=ob_get_level(); ob_start(); $active=false;
function emit(array $a): void {global $floor;while(ob_get_level()>$floor)ob_end_clean();fwrite(STDOUT,json_encode($a,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n");}
function fail(\Throwable $e): void {global $active;if($active){try{Db::rollback();}catch(\Throwable){}}emit(['version'=>1,'status'=>'failed','exception_class'=>get_class($e),'raw_error_redacted'=>true]);exit(1);}
set_exception_handler(__NAMESPACE__.'\\fail');
function publicDefaults(array $a): array {
    $out=[];foreach($a as$k=>$v){if(is_string($k)&&preg_match('/(?:api_config|api_?key|secret|password|authorization|access_?token|private_?key|credential)/i',$k))continue;
        if(is_string($v)&&preg_match('~https?://[^/\s]+@~i',$v))continue;
        $out[$k]=is_array($v)?publicDefaults($v):$v;
    }return$out;
}
function metadata(array $m): array {
    $keys=['params_schema','material_capabilities','generation_protocol','default_params','capabilities','category_code','supported_ratios','ratio_options','ratios','aspect_ratio','supported_durations','duration_options','durations','supported_resolutions','resolution_options','input_modes','generation_modes','supported_asset_types','content_schema','supports_reference_images','supports_first_last_frame','max_reference_images','max_reference_audios','max_reference_videos','max_reference_assets','upstream_model','model_code','channel_code','model_id','ai_model_id'];
    $o=array_intersect_key($m,array_flip($keys));if(isset($o['default_params']))$o['default_params']=publicDefaults(Schema::array($o['default_params']));
    if(isset($m['upstream_metadata']))$o['upstream_metadata']=metadata(Schema::array($m['upstream_metadata']));
    return$o;
}
$root=dirname(__DIR__,2);require $root.'/vendor/autoload.php';(new \think\App($root))->initialize();
set_error_handler(static function(int $s):bool{if(!(error_reporting()&$s))return false;throw new \ErrorException('redacted',0,$s);});
if(!in_array(Db::connect()->getConfig('hostname'),['localhost','127.0.0.1'],true)||!in_array(Db::connect()->getConfig('database'),['x_cn','z_cn'],true))throw new \RuntimeException('local-only');
Db::execute('SET TRANSACTION READ ONLY');Db::startTrans();$active=true;
$expected=[234=>['image','nano-banana-2.1','Google'],169=>['image','gpt-image-2.5','OpenAIYI'],168=>['image','gpt-image-2.5-flare','OpenAIYI'],167=>['image','gpt-image-2.5-sunburst','OpenAIYI'],93=>['image','nano-banana-2','Google'],92=>['image','nano-banana-pro','Google'],91=>['image','gpt-image-2','OpenaiM'],90=>['image','gpt-image-2','OpenAIYI'],89=>['image','gpt-image-2-fast','openaiD'],88=>['image','gpt-image-2-pro','OpenaiM'],87=>['image','qwen-image-3.0-pro','dashscope_compatible'],86=>['image','qwen-image-3.0','dashscope_compatible'],235=>['video','q4-preview-video','q4_video'],98=>['video','h3-video','minimax'],97=>['video','grok-video','xAIQ'],96=>['video','veo3.1-fast','xAIQ'],95=>['video','veo3.1-pro','xAIQ'],94=>['video','wan3.0-video','dashscope_compatible'],275=>['image','official-33-c0b38c6fcb29f739b0','official'],277=>['image','official-33-f3baf6c03742072d3a','official']];
$out=['version'=>1,'status'=>'ok','captured_at'=>date(DATE_ATOM),'scope'=>'Exact saved business declarations; local read-only rollback, no API calls or quotes. Runtime and original saved views kept separately.','entries'=>[],'meter_resources'=>[]];
foreach($expected as$id=>[$type,$code,$channel]){
    $p=Db::name('power_market_product')->where('id',$id)->field('id,resource_type,model_type,upstream_model_code,upstream_channel_code,source_payload')->find();
    if(!$p||$p['resource_type']!=='model'||$p['model_type']!==$type||$p['upstream_model_code']!==$code||$p['upstream_channel_code']!==$channel)throw new \RuntimeException('identity');
    $p['source_payload']=Schema::array($p['source_payload']);$saved=Schema::array($p['source_payload']['market_metadata']??[]);
    $raw=(new \ReflectionMethod($type==='image'?Images::class:Videos::class,'metadata'))->invoke(null,$p);
    $m=metadata($raw);if(Reviewed::declarationFingerprint($m)!==Reviewed::declarationFingerprint($raw))throw new \RuntimeException('redaction-changed-binding');
    $r=['generation_type'=>$type,'product'=>array_diff_key($p,['source_payload'=>1]),'metadata'=>$m,'original_saved_metadata'=>metadata($saved),'declaration_fingerprint'=>Reviewed::declarationFingerprint($raw),'original_saved_declaration_fingerprint'=>Reviewed::declarationFingerprint($saved),'effective_upstream_model'=>$p['source_payload']['resource']['upstream_model']??$p['source_payload']['market_metadata']['upstream_metadata']['upstream_model']??null,'effective_api_config'=>'not_present_in_public_saved_declaration'];
    try{$r['effective_protocol']=Registry::modelProtocol($type,['resource_type'=>'model','model_code'=>$code,'channel_code'=>$channel,'market_metadata'=>$m]);}catch(\InvalidArgumentException){$r['effective_protocol']=null;}
    try{$r['native_mode_codes']=array_keys(Native::modes($m));}catch(\InvalidArgumentException){$r['native_mode_codes']=[];}
    if($id===275||$id===277)$out['meter_resources'][]=$r;else$out['entries'][]=$r;
}
$out['sku_bindings']=[];
foreach(Db::name('power_market_sku')->whereIn('id',[380,383,210,211,212,213,214,215])->field('id,product_id,sku_key,usage_unit,usage_unit_size,locked_params,source_payload')->select()->toArray()as$sku){
    $s=Schema::array($sku['source_payload']);unset($sku['source_payload']);$sku['locked_params']=publicDefaults(Schema::array($sku['locked_params']));
    $sku['saved_meter_contract']=$s['meter_contract']??null;$sku['source_field_names']=array_keys($s);$out['sku_bindings'][]=$sku;
}
if(count($out['sku_bindings'])!==8)throw new \RuntimeException('sku-count');
Db::rollback();$active=false;emit($out);
