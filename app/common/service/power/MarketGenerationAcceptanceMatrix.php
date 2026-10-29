<?php
namespace app\common\service\power;

use InvalidArgumentException;

/** Initialise an explicit pending matrix from a read-only audit; never certify compatibility. */
final class MarketGenerationAcceptanceMatrix
{
    public const ENTRIES=['pc_studio','pc_home','standalone_app','short_drama','canvas'];

    public static function cellKey(array $identity,int $skuId,string $mode,string $entry,string $scenario): string
    {
        if(!in_array($entry,self::ENTRIES,true))throw new InvalidArgumentException('Unknown acceptance entry');
        return hash('sha256',json_encode([$identity,$skuId,$mode,$entry,$scenario],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
    }

    public static function build(array $audit): array
    {
        if((int)($audit['tenant_id']??0)<=0||!is_string($audit['generated_at']??null)||!is_array($audit['models']??null)||$audit['models']===[])
            throw new InvalidArgumentException('Missing catalogue observation or tenant');
        $resources=[];$identities=[];$cells=[];$skuKeys=[];$cases=0;$preparations=[];$meters=[];
        foreach($audit['models']as$model){
            foreach(['generation_type','resource_type','id','model_code','channel_code','app_code','api_code','declaration_fingerprint']as$key)
                if(!is_string($model[$key]??null))throw new InvalidArgumentException('Audit lacks exact resource identity or fingerprint');
            if(!in_array($model['generation_type'],['image','video'],true)||!in_array($model['resource_type'],['model','app_api'],true)
                ||(int)($model['product_id']??0)<=0||!preg_match('/^[a-f0-9]{64}$/D',$model['declaration_fingerprint']))throw new InvalidArgumentException('Invalid catalogue identity');
            if($model['resource_type']==='app_api'&&($model['app_code']===''||$model['api_code']===''))throw new InvalidArgumentException('Missing application API identity');
            if($model['resource_type']==='model'&&$model['model_code']==='')throw new InvalidArgumentException('Missing model code');
            $identity=array_intersect_key($model,array_flip(['generation_type','resource_type','id','product_id','model_code','channel_code','app_code','api_code']));
            $identityKey=hash('sha256',json_encode($identity,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
            if(isset($identities[$identityKey]))throw new InvalidArgumentException('Duplicate catalogue option');
            $identities[$identityKey]=true;
            $matrix=[];
            foreach((array)($model['sku_matrix']??[])as$sku){
                $id=(int)($sku['market_sku_id']??0);
                if($id<=0||empty($sku['request_cases']))throw new InvalidArgumentException('Run the source audit with request probes enabled');
                $skuKeys[$model['product_id'].':'.$id]=true;
                $meter=(string)($sku['meter_status']??'unverified');$meters[$meter]=($meters[$meter]??0)+1;
                foreach($sku['request_cases']as$case){
                    if(!is_string($case['case']??null)||!array_key_exists('material_mode',$case))throw new InvalidArgumentException('Request case lacks a mode binding');
                    $mode=(string)$case['material_mode'];$scenario=$case['case'];
                    foreach(self::ENTRIES as$entry){$key=self::cellKey($identity,$id,$mode,$entry,$scenario);
                        if(isset($cells[$key]))throw new InvalidArgumentException('Duplicate acceptance cell');$cells[$key]=true;}
                    $status=(string)($case['status']??'unobserved');$preparations[$status]=($preparations[$status]??0)+1;
                    $matrix[]=['sku_id'=>$id,'material_mode'=>$mode,'generation_method'=>(string)($case['generation_method']??''),
                        'scenario'=>$scenario,'local_preparation_observation'=>$status,'meter_observation'=>$meter,
                        'entry_acceptance'=>array_fill_keys(self::ENTRIES,'not_checked')];$cases++;
                }
            }
            $visibility=(array)($model['tool_catalog_visibility']??[]);
            $resources[]=['identity'=>$identity,'name'=>(string)($model['name']??''),'declaration_fingerprint'=>$model['declaration_fingerprint'],
                'native_contract_observation'=>(string)($model['native_contract']??'unverified'),
                'catalogue_visibility'=>['pc_studio'=>$visibility['standalone_'.$model['generation_type']]??'unknown',
                    'standalone_app'=>$visibility['standalone_'.$model['generation_type']]??'unknown',
                    'pc_home'=>'not_checked','short_drama'=>$visibility['short_drama']??'unknown','canvas'=>$visibility['canvas']??'unknown'],
                'cases'=>$matrix];
        }
        return ['version'=>1,'tenant_id'=>(int)$audit['tenant_id'],'observed_at'=>$audit['generated_at'],
            'scope'=>'Pending software acceptance; local construction, visibility and contract fingerprints never imply acceptance.',
            'cell_key'=>'SHA-256 JSON([identity, sku_id, material_mode, entry, scenario]); normalized cases expand across entry_acceptance keys.',
            'required_checkpoints'=>['identity_and_complete_contract','frontend_parameters','browser_upload_dto','quote_preparation_parity',
                'sku_pricing','reservation_refund_idempotency','history_snapshot_compatibility'],
            'upstream_generation'=>'not_tested_no_paid_authorization',
            'summary'=>['resources'=>count($resources),'unique_skus'=>count($skuKeys),'parameter_cases'=>$cases,
                'entry_cells'=>count($cells),'accepted_cells'=>0,'pending_cells'=>count($cells),'local_preparation_observations'=>$preparations,
                'meter_observations'=>$meters],
            'resources'=>$resources,
            'unavailable_applications'=>array_map(static fn($r)=>array_intersect_key($r,array_flip(['market_product_id','category_code','name','unavailable_reason'])),
                (array)($audit['unavailable_applications']??[]))];
    }
}
