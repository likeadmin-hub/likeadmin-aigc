<?php
namespace app\common\service\power;

use InvalidArgumentException;

/** Explicit offline verification of frozen copies. No catalogue attach/write, runtime boot, HTTP, tasks or point access. */
final class MarketOwnedPlatformPreparationVerification
{
    public static function verify(array $bundle,array $snapshot,string $artifact): array
    {
        $before=MarketGenerationReviewedContract::digest($snapshot);
        $catalogue=MarketGenerationContractReview::reviewedCatalogue($bundle,$artifact);
        $contracts=[];foreach($catalogue['contracts']as$c)$contracts[self::key($c)]=$c;
        $rows=[];foreach($snapshot['entries']as$row){
            $p=$row['product'];$identity=['generation_type'=>$row['generation_type'],
                'model_code'=>$p['upstream_model_code'],'channel_code'=>$p['upstream_channel_code']];
            $rows[self::key($identity)]=$row;
        }
        $result=['scope'=>'offline_prepare_and_same_frozen_snapshot_replay','live_generation'=>false,
            'catalogue_written'=>false,'models'=>[],'success'=>true];
        foreach($bundle['model_states']as$state){
            $key=self::key($state);if(!isset($rows[$key]))throw new InvalidArgumentException('准备验证缺少精确原始声明');
            $row=$rows[$key];$metadata=MarketOwnedPlatformContractBuilder::declarationMetadata($row);$contract=$contracts[$key]??null;
            if($contract!==null)$metadata[MarketGenerationReviewedContract::PIN]=$contract;
            $frozen=['resource_type'=>'model','model_code'=>$state['model_code'],'channel_code'=>$state['channel_code'],
                'params_schema'=>$metadata['params_schema']??[],'default_params'=>$metadata['default_params']??[],
                'market_metadata'=>$metadata,'locked_params'=>[]];
            // Explicit saved protocol is preserved. No inference from product.id or a similarly named channel.
            if(is_string($row['effective_protocol']??null))$frozen['generation_protocol']=$row['effective_protocol'];
            $fingerprint=MarketGenerationReviewedContract::digest($frozen);
            $model=array_intersect_key($state,array_flip(['generation_type','model_code','channel_code']))+[
                'contract_state'=>$state['status'],'source_candidate_attached_to_copy'=>$contract!==null,
                'declaration_fingerprint'=>MarketGenerationReviewedContract::declarationFingerprint($metadata),
                'cases'=>[],'frozen_unchanged'=>true];
            foreach(self::cases($state,$contract)as$case){
                $caseResult=['case'=>$case['name'],'expectation'=>$case['expectation'],'layer'=>$case['layer']];
                try{
                    $a=self::prepare($state['generation_type'],$frozen,$case['request']);
                    $b=self::prepare($state['generation_type'],$frozen,$case['request']);
                    if($a!==$b)throw new \LogicException('Frozen preparation was not deterministic');
                    if(($a['model']??null)!==$state['model_code'])throw new \LogicException('Model envelope changed');
                    if($contract!==null&&($a['channel']??null)!==$state['channel_code'])throw new \LogicException('Channel envelope changed');
                    $caseResult+=['status'=>'prepared_and_replayed','native_sha256'=>MarketGenerationReviewedContract::digest($a)];
                    if($case['expectation']==='reject')$result['success']=false;
                }catch(\Throwable $error){
                    // InvalidArgumentException inherits LogicException. Only
                    // known parameter refusals may satisfy an expected reject;
                    // internal LogicException/TypeError/Error must fail the run.
                    $invariantFailure=!($error instanceof InvalidArgumentException||get_class($error)===\Exception::class);
                    $caseResult+=['status'=>$invariantFailure?'invariant_failed':'rejected','error_class'=>get_class($error)];
                    // Never return exception messages: these may contain private declarations/materials.
                    if($invariantFailure||$case['expectation']==='prepare')$result['success']=false;
                }
                $model['cases'][]=$caseResult;
            }
            $model['frozen_unchanged']=$fingerprint===MarketGenerationReviewedContract::digest($frozen);
            if(!$model['frozen_unchanged'])$result['success']=false;
            $result['models'][]=$model;
        }
        $result['original_snapshot_unchanged']=$before===MarketGenerationReviewedContract::digest($snapshot);
        $result['success']=$result['success']&&$result['original_snapshot_unchanged']&&count($result['models'])===18;
        return $result;
    }

    private static function key(array $identity): string
    {return implode('|',[$identity['generation_type'],$identity['model_code'],$identity['channel_code']]);}

    private static function prepare(string $type,array $snapshot,array $request): array
    {
        return $type==='image'?MarketImageModelRuntimeService::prepareRequest($snapshot,$request)->nativeInput
            :MarketVideoRuntimeService::prepareRequest($snapshot,$request)->nativeInput;
    }

    /** No uploaded/private material: public-looking invalid-domain URLs pass only through pure preparation. */
    private static function references(array $counts): array
    {
        $assets=[];foreach($counts as$kind=>$count)for($i=0;$i<$count;$i++)$assets[]=[
            'type'=>$kind,'role'=>'reference_'.$kind,'url'=>'https://fixtures.invalid/'.$kind.'/'.$i];
        return $assets;
    }

    public static function cases(array $state,?array $contract): array
    {
        $model=$state['model_code'];$image=$state['generation_type']==='image';
        $base=['prompt'=>'Offline source verification','quantity'=>1,'ratio'=>'16:9'];
        $parameters=$state['facts']['parameters']??[];
        $base['quality']=$image?'1k':'720p';
        foreach($image?['resolution','image_size']:['quality','resolution']as$field){
            $values=$parameters[$field]['declared_enum']??$parameters[$field]['display_options']??[];
            if($values!==[]){$base['quality']=(string)$values[0];break;}
        }
        if(!$image)$base['duration']=str_starts_with($model,'veo3.1-')?8:($model==='grok-video'?6:5);
        if($model==='h3-video')$base['quality']='2K';
        if($model==='wan3.0-video')$base['quality']='720P';
        if($model==='q4-preview-video'){$base['quality']='720p';$base['reference_assets']=[['type'=>'image','role'=>'first_frame_image','url'=>'https://fixtures.invalid/q4/frame']];}
        $cases=[];$add=static function(string $name,array $request,string $expectation,string $layer='runtime_prepare')use(&$cases){
            $cases[]=['name'=>$name,'request'=>$request,'expectation'=>$expectation,'layer'=>$layer];};
        $add($model==='q4-preview-video'?'single_image':'text',$base,'prepare');
        if($contract!==null){
            foreach($contract['material_capabilities']['modes']as$mode){
                if($mode['fields']===[])continue;
                $request=$base;$request['material_mode']=$mode['view_key'];$assets=[];
                foreach($mode['fields']as$field){
                    $count=(int)($field['maximum']??1);$kind=$field['kind'];$role=$field['role'];
                    for($i=0;$i<$count;$i++)$assets[]=['type'=>$kind,'role'=>$role==='first'?'first_frame_image':($role==='last'?'last_frame_image':'reference_'.$kind),
                        'url'=>'https://fixtures.invalid/'.$kind.'/'.$role.'/'.$i];
                }
                // Wan's two mutually exclusive document slots are not a valid combined reference request.
                if($model==='wan3.0-video')$assets=array_values(array_filter($assets,static fn($a)=>$a['type']!=='link'));
                $request['reference_assets']=$assets;
                $add($mode['code'].'_maximum',$request,'prepare');
                if($assets!==[]){$overflow=$request;$extra=$assets[0];$extra['url']='https://fixtures.invalid/overflow';$overflow['reference_assets'][]=$extra;
                    $add($mode['code'].'_overflow',$overflow,'reject');}
            }
        }else{
            $maximum=$state['facts']['saved_public_capabilities']['max_reference_images']??null;
            if(is_int($maximum)&&$maximum>0){
                $request=$base;$request['reference_assets']=self::references(['image'=>$maximum]);
                if($image)$request['reference_images']=array_column($request['reference_assets'],'url');
                $add('saved_reference_maximum',$request,'observe','unreviewed_runtime_prepare');
                $request['reference_assets']=self::references(['image'=>$maximum+1]);
                if($image)$request['reference_images']=array_column($request['reference_assets'],'url');
                $add('saved_reference_overflow',$request,'observe','unreviewed_runtime_boundary_not_admission');
            }
        }
        foreach($state['facts']['source_constraints']['pixel_matrix']??[]as$ratio=>$cells)foreach($cells as$resolution=>$pixels){
            $request=$base;$request['ratio']=$ratio;$request['quality']=$resolution;
            $add('source_matrix_'.$resolution.'_'.$ratio,$request,'observe','source_mapping_cell_runtime_envelope_only');
        }
        return $cases;
    }
}
