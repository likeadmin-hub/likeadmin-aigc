<?php
namespace app\common\service\power;

use InvalidArgumentException;

/** Trusted per-SKU output bounds. Size labels and response dimensions are not measurements. */
final class MarketGenerationPixelMeter
{
    public static function contract(array $value, string $summary, string $unit): array
    {
        if (array_diff(array_keys($value),['version','basis','rounding','maximum_pixels_per_image','maximum_images','result_path','result_item_url'])!==[]
            || ($value['version']??null)!==3 || ($value['basis']??null)!=='megapixel'
            || !in_array($unit,['business_unit','megapixel','per_megapixel'],true)
            || !in_array($value['rounding']??null,['ceil','ceil_2'],true)
            || !is_int($value['maximum_pixels_per_image']??null) || $value['maximum_pixels_per_image']<1 || $value['maximum_pixels_per_image']>1000000000
            || !is_int($value['maximum_images']??null) || $value['maximum_images']<1 || $value['maximum_images']>16
            || !in_array($value['result_path']??null,['images','image_urls','data.images','data.image_urls','result.images','result.image_urls','data.result.images','data.result.image_urls','data.result.results'],true)
            || !in_array($value['result_item_url']??null,['','url','image_url','uri'],true))
            throw new InvalidArgumentException('像素计量上限或输出回执合同无效');
        return ['version'=>3,'source'=>'structured','basis'=>'megapixel','summary'=>$summary,'unit'=>$unit,'round_up'=>true,
            'rounding_scale'=>$value['rounding']==='ceil_2'?2:0]+array_intersect_key($value,array_flip(['maximum_pixels_per_image','maximum_images','result_path','result_item_url']));
    }

    public static function applies(array $meter): bool
    {
        return ($meter['version']??null)===3 && ($meter['basis']??null)==='megapixel';
    }

    public static function assertUnitSize($size): void
    {
        if(!is_numeric($size)||!is_finite((float)$size)||(float)$size<1)throw new InvalidArgumentException('像素计费单位无效');
    }

    public static function upperBound(array $meter, int $images): float
    {
        if ($images<1 || $images>$meter['maximum_images']) throw new InvalidArgumentException('图片数量超过像素计量合同上限');
        return $images*$meter['maximum_pixels_per_image']/1000000;
    }

    /** Only a declared result list; preserve repeated output slots, never read width/height here. */
    public static function urls(array $meter, array $response): array
    {
        $rows=$response;
        foreach(explode('.',$meter['result_path'])as$key){
            if(!is_array($rows)||!array_key_exists($key,$rows))throw new InvalidArgumentException('像素计费尚未收到声明的输出回执');
            $rows=$rows[$key];
        }
        if(!is_array($rows)||!array_is_list($rows)||count($rows)>$meter['maximum_images'])throw new InvalidArgumentException('像素输出回执数量或结构不符');
        $urls=[];
        foreach($rows as$row){
            if($meter['result_item_url']!==''){
                if(!is_array($row))throw new InvalidArgumentException('像素输出回执类型不符');
                if(isset($row['type'])&&!in_array($row['type'],['image','image_url'],true))throw new InvalidArgumentException('像素输出回执不是图片');
                if(array_key_exists('transfer_status',$row)){
                    if(in_array($row['transfer_status'],['failed','failure','hidden','canceled','cancelled'],true))continue;
                    if($row['transfer_status']!=='success')throw new InvalidArgumentException('像素输出尚未完成转存');
                }
                $row=$row[$meter['result_item_url']]??null;
            }
            if(!is_string($row)||!preg_match('~^(https?://|data:image/)~i',$row))throw new InvalidArgumentException('像素输出缺少可核验图片地址');
            $urls[]=$row;
        }
        if($urls===[])throw new InvalidArgumentException('像素计费尚未收到可核验图片');
        return $urls;
    }

    /** Input comes only from server image persistence, not provider/browser dimension fields. */
    public static function measured(array $meter, array $images, int $reservedImages): float
    {
        self::upperBound($meter,$reservedImages);
        if($images===[]||count($images)>$reservedImages)throw new InvalidArgumentException('实际图片数量超过预占数量或缺失');
        $pixels=0;
        foreach($images as$image){
            $width=$image['width']??null;$height=$image['height']??null;
            if(!is_int($width)||!is_int($height)||$width<1||$height<1||$width>65535||$height>65535)
                throw new InvalidArgumentException('像素计费缺少服务端实测图片尺寸');
            $size=$width*$height;
            if($size>$meter['maximum_pixels_per_image'])throw new InvalidArgumentException('实际图片像素超过冻结合同上限，请核查');
            $pixels+=$size;
        }
        return $pixels/1000000;
    }
}
