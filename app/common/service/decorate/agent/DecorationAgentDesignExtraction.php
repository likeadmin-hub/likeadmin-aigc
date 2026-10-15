<?php
declare(strict_types=1);
namespace app\common\service\decorate\agent;

/** Source coordinates describe a generation reference, never a local image crop. */
final class DecorationAgentDesignExtraction
{
    public static function operation(array $op): array
    {
        if(($op['generation_mode']??'')!=='design_extract')throw new \InvalidArgumentException('设计素材提取模式无效');
        $region=$op['region']??null;
        if(!is_array($region)||array_diff(array_keys($region),['x','y','width','height'])||count($region)!==4)throw new \InvalidArgumentException('请在设计图中框选单个素材区域');
        $normalized=[];
        foreach(['x','y','width','height'] as $key){$value=$region[$key];if((!is_int($value)&&!is_string($value))||!preg_match('/^(0|[1-9][0-9]*)$/D',(string)$value)||(int)$value>1000)throw new \InvalidArgumentException('框选坐标必须是0至1000的整数');$normalized[$key]=(int)$value;}
        if($normalized['width']<=0||$normalized['height']<=0||$normalized['x']+$normalized['width']>1000||$normalized['y']+$normalized['height']>1000||$normalized['width']*$normalized['height']>600000)throw new \InvalidArgumentException('框选区域越界或超过设计图面积的60%，请仅选择一个素材');
        if(!in_array($op['asset_role']??'', ['icon','image'],true))throw new \InvalidArgumentException('素材用途无效');
        $background=$op['background']??null;
        if(!is_array($background)||array_diff(array_keys($background),['mode','color'])||($background['mode']??'')!=='solid'||!is_string($background['color']??null)||!preg_match('/^#[0-9a-f]{6}$/iD',$background['color']))throw new \InvalidArgumentException('当前提取仅支持明确的六位纯色背景');
        if(!is_string($op['prompt']??'')||mb_strlen($op['prompt']??'')>1000)throw new \InvalidArgumentException('素材说明不得超过1000字');
        if(!empty($op['reference_file_ids'])||!empty($op['reference_images']))throw new \InvalidArgumentException('提取素材只允许使用本轮已确认设计图');
        return ['generation_mode'=>'design_extract','region'=>$normalized,'asset_role'=>$op['asset_role'],'background'=>['mode'=>'solid','color'=>strtoupper($background['color'])]];
    }

    public static function prompt(array $request,array $approved): string
    {
        $extraction=self::operation($request);$r=$extraction['region'];$asset=$approved['asset'];
        return '依据附带的真实已确认整页设计图生成一个独立'.($extraction['asset_role']==='icon'?'图标':'配图').'素材。原图尺寸'.(int)$asset['width'].'×'.(int)$asset['height'].'像素；坐标相对原始设计图，不是浏览器留白或375px预览。定位区域左上x='.$r['x'].'/1000、y='.$r['y'].'/1000、宽='.$r['width'].'/1000、高='.$r['height'].'/1000。仅参考该区域内的单个目标，保持其实际形状、平面/立体画风、材质、颜色、构图和可见主体比例；不要依据页面主题擅自增加玻璃、金属或3D。输出独立素材，不含整页UI、区域外元素、标题文字、按钮或页面边框。背景使用真实纯色'.$extraction['background']['color'].'，不是透明图，不画棋盘格；尽量减少无关留白并匹配目标画布比例。区域是生成定位参考，不保证像素级原样切图。用户补充：'.trim((string)($request['prompt']??''));
    }

    public static function positive($value,string $label): int
    {
        if((!is_int($value)&&!is_string($value))||!preg_match('/^[1-9][0-9]*$/D',(string)$value)||filter_var($value,FILTER_VALIDATE_INT)===false)throw new \InvalidArgumentException($label.'无效');
        return (int)$value;
    }

    public static function normalize(array $p,bool $submit=false): array
    {
        $allowed=['candidate_id','revision','candidate_hash','expected_hash','design_run_id','design_revision','design_hash','widget_id','field','generation_mode','region','asset_role','background','ratio','prompt','image_model_selection','authorize_generation','budget_points'];
        if($submit)$allowed=[...$allowed,'quote_hash','idempotency_key'];
        if(array_diff(array_keys($p),$allowed))throw new \InvalidArgumentException('提取参数包含不支持的字段');
        if(($p['authorize_generation']??null)!==true)throw new \InvalidArgumentException('请明确授权本次AI生图');
        $result=[];foreach(['candidate_id','revision','design_run_id','design_revision'] as $field)$result[$field]=self::positive($p[$field]??null,$field);
        foreach(['candidate_hash','expected_hash','design_hash'] as $field){if(!is_string($p[$field]??null)||!preg_match('/^[a-f0-9]{64}$/D',$p[$field]))throw new \InvalidArgumentException('候选或设计版本校验无效');$result[$field]=$p[$field];}
        foreach(['widget_id','field'] as $field){if(!is_string($p[$field]??null)||$p[$field]===''||strlen($p[$field])>200)throw new \InvalidArgumentException('目标图片字段无效');$result[$field]=$p[$field];}
        $result=array_merge($result,self::operation($p));
        if(!in_array($p['ratio']??'', ['1:1','2:3','3:2','3:4','4:3','9:16','16:9','21:9'],true))throw new \InvalidArgumentException('图片比例无效');
        $result['ratio']=$p['ratio'];$result['prompt']=trim((string)($p['prompt']??''));$selection=$p['image_model_selection']??null;
        if(!is_array($selection)||array_diff(array_keys($selection),['market_product_id','market_sku_id']))throw new \InvalidArgumentException('请选择生图模型和规格');
        $result['image_model_selection']=['market_product_id'=>self::positive($selection['market_product_id']??null,'生图模型'),'market_sku_id'=>self::positive($selection['market_sku_id']??null,'生图规格')];
        $budget=$p['budget_points']??null;if((!is_int($budget)&&!is_float($budget)&&!is_string($budget))||!is_numeric($budget)||!is_finite((float)$budget)||(float)$budget<=0)throw new \InvalidArgumentException('请确认本次生图预算');
        $result['authorize_generation']=true;$result['budget_points']=(float)$budget;return $result;
    }

    public static function assertModel(int $tenant,array $selection,string $ratio): void
    {
        foreach(DecorationAgentMediaService::options($tenant) as $model){
            if((int)($model['market_product_id']??0)!==$selection['market_product_id'])continue;
            DecorationAgentMediaService::assertDesignReferenceModel($tenant,$selection,1);
            foreach($model['skus']??[] as $sku)if((int)($sku['market_sku_id']??0)===$selection['market_sku_id']){
                $ratios=$sku['ratio_options']??$model['ratio_options']??[];if($ratios&&!in_array($ratio,$ratios,true))throw new \InvalidArgumentException('所选图片规格不支持该比例');return;
            }
        }
        throw new \InvalidArgumentException('所选生图模型或规格当前不可用');
    }
}
