<?php
declare(strict_types=1);

namespace app\common\service\app\aigc_animate_replace;

use app\common\enum\FileEnum;
use app\common\service\ai\StudioMediaInputService;
use app\common\service\ai\StudioMediaService;
use app\common\service\app\AppAccessService;
use app\common\service\app\AppDisplayConfigService;
use app\common\service\membership\MembershipService;
use app\common\service\power\MarketAnimateReplace as Market;
use app\common\service\power\MarketGenerationMedia;
use app\common\service\power\MarketVideoRuntimeService as Video;
use app\common\service\storage\ManagedStorageReader;
use InvalidArgumentException;
use think\facade\Log;
use Throwable;

final class AnimateReplaceService
{
    private const MAX_BYTES = 10 * 1024 * 1024;
    private const MAX_SECONDS = 30;

    public static function config(int $tenant): array
    {
        return AppDisplayConfigService::appendToConfig($tenant, Market::APP, [
            'model_name' => 'Wan 2.2',
            'resolutions' => array_keys(Market::specs($tenant)),
        ]);
    }

    public static function setup(int $tenant, array $params): void
    {
        AppDisplayConfigService::saveFromConfigPayload($tenant, Market::APP, $params);
    }

    public static function input(array $params): array
    {
        $input = [];
        foreach (['image_uri', 'video_uri'] as $key) {
            $value = $params[$key] ?? '';
            if (!is_string($value) || trim($value) === '' || strlen($value) > 4096) {
                throw new InvalidArgumentException('请上传一段原视频和一张目标角色图片');
            }
            $input[$key] = trim($value);
        }
        $resolution = $params['resolution'] ?? '';
        if (!in_array($resolution, Market::RESOLUTIONS, true)) throw new InvalidArgumentException('请选择有效分辨率');
        return $input + ['resolution'=>$resolution];
    }

    public static function prepare(int $tenant, int $user, array $input): array
    {
        if (!AppAccessService::tenantCanUse($tenant, Market::APP)) throw new InvalidArgumentException('动画替换当前不可用');
        if ($user <= 0 || !MembershipService::userCanUseApp($tenant, $user, Market::APP)) {
            throw new InvalidArgumentException('当前套餐尚未开放动画替换');
        }
        $spec = Market::specs($tenant)[$input['resolution']] ?? null;
        if (!$spec) throw new InvalidArgumentException('所选分辨率暂无上架规格，请刷新配置后重试');
        $assets = self::media($tenant, $user, $input);
        $request = self::withAssets($spec + [
            'resolution'=>$input['resolution'],
            'duration'=>0,
            'provider_params'=>['nsfw_checker'=>true],
        ], $assets);
        return [$assets, $request];
    }

    public static function estimate(int $tenant, int $user, array $params): array
    {
        [$assets, $request] = self::prepare($tenant, $user, self::input($params));
        $quote = self::withVerifiedVideo($assets, static fn() => Video::quote($tenant, $request, Market::APP));
        return array_intersect_key($quote, array_flip(['user_charge_points', 'quantity', 'billing_unit', 'billing_unit_size']));
    }

    public static function media(int $tenant, int $user, array $input): array
    {
        try {
            $image = StudioMediaInputService::validateImages($tenant, $user, [['uri'=>$input['image_uri']]], 1)[0];
            $facts = StudioMediaService::facts($tenant, $image, FileEnum::IMAGE_TYPE);
            if (($facts['file_size'] ?? 0) <= 0 || $facts['file_size'] > self::MAX_BYTES
                || !in_array($facts['mime_type'] ?? '', ['image/jpeg', 'image/png', 'image/webp'], true)) {
                throw new InvalidArgumentException('请上传不超过 10 MB 的 JPG、PNG 或 WebP 图片');
            }
            $video = StudioMediaInputService::validateVideo($tenant, $user, $input['video_uri'], self::MAX_SECONDS,
                static fn(array $file, array $storage): string => ManagedStorageReader::read(
                    array_replace($file, $storage), $tenant, self::MAX_BYTES, 30
                )
            );
            if ($video['file_size'] <= 0 || $video['file_size'] > self::MAX_BYTES
                || !in_array($video['mime_type'], ['video/mp4', 'video/quicktime', 'video/x-matroska', 'application/x-matroska'], true)) {
                throw new InvalidArgumentException('请上传不超过 10 MB 的 MP4、MOV 或 MKV 视频');
            }
            $seconds = (float)$video['duration'];
            if (!is_finite($seconds) || $seconds <= 0 || $seconds > self::MAX_SECONDS) {
                throw new InvalidArgumentException('原视频时长须大于 0 秒且不超过 30 秒');
            }
            return ['image'=>$image + $facts, 'video'=>$video];
        } catch (InvalidArgumentException $error) {
            throw $error;
        } catch (Throwable $error) {
            Log::warning('Animate replace media: '.get_class($error));
            throw new InvalidArgumentException('素材暂时无法读取，请重新上传后重试', 0, $error);
        }
    }

    public static function withAssets(array $request, array $assets): array
    {
        $request['reference_assets'] = [];
        foreach (['image', 'video'] as $kind) {
            $request['reference_assets'][] = array_replace($assets[$kind], ['type'=>$kind, 'role'=>'reference_'.$kind]);
        }
        return $request;
    }

    public static function withVerifiedVideo(array $assets, callable $callback): mixed
    {
        return MarketGenerationMedia::withVerifiedVideo($assets['video']['url'], (float)$assets['video']['duration'], $callback);
    }
}
