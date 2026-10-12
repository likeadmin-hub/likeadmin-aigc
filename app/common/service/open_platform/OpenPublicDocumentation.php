<?php
namespace app\common\service\open_platform;

use app\common\service\power\TenantPowerMarketService;

/** Read-only documentation projections. These are not stored publications or receipts. */
final class OpenPublicDocumentation
{
    public static function fallbacks(int $tenantId,array $documents,array $contracts): array
    {
        $contracts=array_values(array_filter($contracts,static fn($row)=>($row['status']??'')==='published'));
        $categories=array_column(array_filter($documents,static fn($row)=>($row['status']??'')==='published'&&empty($row['generated'])),'category');$generated=[];
        if(!in_array('定价',$categories,true))$generated[]=self::document('pricing','当前模型与接口定价','定价',self::pricing($tenantId,$contracts),$contracts);
        if(!in_array('更新日志',$categories,true))$generated[]=self::document('changes','当前接口版本记录','更新日志',self::changes($contracts),$contracts);
        return $generated;
    }

    public static function introduction(string $markdown): string
    {
        // Select one prose paragraph; headings, endpoints, tables and code are not summaries.
        $markdown=preg_replace('/```[^\r\n]*\R[\s\S]*?```/u','',$markdown)??'';
        foreach(preg_split('/(?:\r?\n){2,}/u',trim($markdown))?:[] as $paragraph){
            $paragraph=trim($paragraph);
            if($paragraph===''||preg_match('/^(?:#{1,6}\s|\||>|[-*+]\s|\d+\.\s|(?:GET|POST|PUT|PATCH|DELETE)\s)/u',$paragraph))continue;
            if(preg_match('/compiler|source_digest|review_note|contract_digest|gateway:|编译器|待验收草稿|来源摘要|真实网关回执/iu',$paragraph))continue;
            $paragraph=preg_replace('/\[([^\]]+)\]\([^)]*\)/u','$1',$paragraph)??'';
            $paragraph=str_replace(['`','**','__'],'',strip_tags($paragraph));
            $paragraph=preg_replace('/\s+/u',' ',trim($paragraph))??'';
            if($paragraph!=='')return mb_substr($paragraph,0,280);
        }
        return '';
    }

    private static function document(string $slug,string $title,string $category,string $content,array $contracts): array
    {
        return ['id'=>'generated-'.$slug,'slug'=>'open-platform-generated-'.$slug,'title'=>$title,'category'=>$category,'content'=>$content,'contract_code'=>'','version'=>1,'status'=>'generated','generated'=>true,'source'=>'visible-published-contracts:'.($slug==='pricing'?'tenant-retail-v1':'current-version-v1'),'update_time'=>$contracts?max(array_map(static fn($row)=>(int)($row['update_time']??0),$contracts)):0];
    }

    private static function pricing(int $tenantId,array $contracts): string
    {
        $content="# 当前模型与接口定价\n\n此页按当前可见的已发布接口自动生成，不是新接口发布记录。价格为当前租户商城的零售参考单价；实际调用仍需有效 API Key、会员资格及接口权限。会员优惠、参数数量和最终预估以 [在线调试](/user/developer/playground) 为准，结算沿用受理时价格快照。\n\n";
        if(!$contracts)return $content."本站暂无对当前租户可见的已发布接口。请在 [能力目录](/user/developer/catalog) 查看开放范围；商城上架不代表接口已发布。\n";
        $content.="| 接口 | 规格 SKU | 当前零售参考价 | 计费单位 |\n| --- | --- | --- | --- |\n";$products=[];
        foreach($contracts as $row){
            $config=OpenContracts::decodeConfig($row['config']);$name=self::cell((string)($config['title']??$row['code']));
            if(array_key_exists('billable',$config)&&empty($config['billable'])){$content.='| '.$name.' | — | 免费（契约明确不计费） | — |'."\n";continue;}
            $productId=(int)($row['product_id']??0);
            if($row['kind']!=='market'||$productId<=0){$content.='| '.$name.' | — | 按业务参数在线预估 | 以该接口声明为准 |'."\n";continue;}
            if(!array_key_exists($productId,$products))$products[$productId]=TenantPowerMarketService::detail($tenantId,$productId);
            $product=$products[$productId];$skus=array_column($product['skus']??[],null,'id');$seen=[];
            foreach($config['billing']??[] as $rule){
                $skuId=(int)($rule['sku_id']??0);if(isset($seen[$skuId]))continue;$seen[$skuId]=true;$sku=$skus[$skuId]??null;
                if(!$sku||empty($sku['sale_status'])||!is_numeric($sku['sale_points']??null)||!is_numeric($sku['usage_unit_size']??null)||!is_finite((float)$sku['sale_points'])||!is_finite((float)$sku['usage_unit_size'])||(float)$sku['sale_points']<0||(float)$sku['usage_unit_size']<=0){$content.='| '.$name.' | SKU '.($skuId?:'—').' | 当前规格暂不可售，请确认在线预估 | — |'."\n";continue;}
                $unit=match($sku['usage_unit']){'character'=>'字符','token'=>'Token','input_second'=>'输入秒','output_second'=>'输出秒','second','seconds','sec'=>'秒','image'=>'图片','per_call'=>($config['native_pricing']??'')===OpenNativeMarketPricing::ADAPTER&&($product['model_type']??'')==='image'?'成功图片':'次',default=>(string)$sku['usage_unit']};
                $content.='| '.$name.' | '.self::cell((string)$sku['sku_key']).' | '.self::number((float)$sku['sale_points']).' 积分 | 每 '.self::number((float)$sku['usage_unit_size']).' '.self::cell($unit).' |'."\n";
            }
            if(!$seen)$content.='| '.$name.' | — | 暂无公开 SKU 单价，请查看在线预估 | — |'."\n";
        }
        return $content."\n只列出已发布契约实际绑定的规格，不承诺未发布 SKU 可调用。收费与否以契约声明为准，零售单价为 0 不会把收费接口改成免费接口。异步查询不重复提交生成，实际费用可在 [用量与账单](/user/developer/usage) 核对。\n";
    }

    private static function changes(array $contracts): string
    {
        $content="# 当前接口版本记录\n\n此页只读展示当前可见、已发布的接口版本及其记录时间，不是完整变更历史或软件发布日志。版本记录时间来自当前契约记录，不推断未保存的历史事件；对应的接口文档见 API 参考。\n\n";
        if(!$contracts)return $content."本站暂无对当前租户可见的已发布接口版本。请在 [能力目录](/user/developer/catalog) 查看当前开放范围。\n";
        usort($contracts,static fn($a,$b)=>(int)($b['update_time']??0)<=>(int)($a['update_time']??0));
        $content.="| 接口 | 方法与路径 | 当前契约版本 | 版本记录时间 |\n| --- | --- | --- | --- |\n";
        foreach($contracts as $row){$config=OpenContracts::decodeConfig($row['config']);$time=(int)($row['update_time']??0);$content.='| '.self::cell((string)($config['title']??$row['code'])).' | '.self::cell($row['method'].' '.$row['path']).' | v'.(int)$row['version'].' | '.($time>0?date('Y-m-d H:i:s T',$time):'未记录').' |'."\n";}
        return $content;
    }

    private static function number(float $value): string {return rtrim(rtrim(number_format($value,6,'.',''),'0'),'.');}
    private static function cell(string $value): string {return str_replace(['|',"\r","\n"],['\\|',' ',' '],strip_tags($value));}
}
