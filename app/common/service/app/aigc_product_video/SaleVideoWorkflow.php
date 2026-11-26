<?php
declare(strict_types=1);
namespace app\common\service\app\aigc_product_video;

final class SaleVideoWorkflow
{
    /** Only product detail links can start a product import. */
    public static function target(string $input): array
    {
        $input = trim($input);
        $parts = parse_url($input);
        $host = strtolower($parts['host'] ?? '');
        if (!in_array($host, ['www.tiktok.com', 'tiktok.com', 'shop.tiktok.com', 'shop.tiktokglobalshop.com'], true)) {
            return ProductVideoAmazon::target($input);
        }
        $path = $parts['path'] ?? '';
        if (strlen($input) > 2000 || ($parts['scheme'] ?? '') !== 'https' || isset($parts['user'])
            || isset($parts['pass']) || isset($parts['port']) || preg_match('/[\x00-\x20\\\\]/', $input)
            || !preg_match('~^/(?:view/product|shop/product|(?:shop/)?(?:[a-z]{2}/)?pdp(?:/[^/?#]+)?)/[0-9]+/?$~iD', $path)) {
            throw new \InvalidArgumentException('请填写 TikTok Shop 商品详情页链接');
        }
        return ['url'=>'https://'.$host.$path, 'provider'=>'tiktok'];
    }

    public static function fetchShop(string $url, bool $image): array
    {
        $page = \app\common\service\open_platform\OpenSafeHttp::get($url, $image ? 10485760 : 2097152, 15);
        if (!$image && (self::target($page['url'])['provider'] ?? '') !== 'tiktok') {
            throw new \InvalidArgumentException('商品页面暂不可读取，请手动上传商品图');
        }
        return $page;
    }

    public const DIRECTION = '制作有人声的出镜带货短视频。用目标语言自然说出台词；正脸讲话时口型同步，商品特写时延续同一声音。按选定时长安排能说完的短句，留出实际演示时间。主体、手持或佩戴方式与引用图片一致。';
    public const UGC = '以使用者第一人称自拍分享。开头用一句与商品相关的生活场景引起兴趣，中段实际演示一个有依据的卖点，穿插对应商品细节，最后自然收尾。使用口语，不编造使用经历、价格或效果。';
    public const WRITE = '按输入地区和目标语言写可直接拍摄的口播脚本。整体设定说明出镜人、场景和商品外观；分镜逐段写时间、动作、引用图、正脸台词或画外音。只引用提供的素材。遵守用户已有台词及事实。只输出 JSON 对象，video_prompt 为可编辑的完整脚本文字。';
}
