<?php

declare(strict_types=1);
namespace app\common\service\license;
use app\common\service\ConfigService;

class CopyrightPolicyService
{
    public static function policy(): array
    {
        $site=(new SiteLicenseAccessService())->snapshot();
        $configured=self::safeItems((array)ConfigService::get('copyright','config',[]));
        $policy=['source'=>'none','can_customize'=>$site['can_customize'],'items'=>[],
            'reason_code'=>$site['reason_code'],'registration_items'=>self::registrationItems($configured)];
        if ($site['can_customize']) {
            $policy['source']='tenant_custom';
            $policy['items']=$configured;
        } elseif (!empty($site['issuer']['copyright_provided'])) {
            $policy['source']='license_issuer'; $policy['items']=$site['issuer']['copyright'];
        }
        return $policy;
    }

    public static function publicSite(): array
    {
        $site=(new SiteLicenseAccessService())->snapshot();
        return array_intersect_key($site,array_flip(['edition','is_perpetual','certificate_status','can_customize','access_status','reason_code','issuer']));
    }

    public static function assertCanCustomize(): void
    {
        $service=new SiteLicenseAccessService();
        $site=$service->snapshot();
        if (!$site['can_customize'] && $site['edition']==='commercial' && in_array($site['access_status'],['pending','expired','invalidated'],true)) {
            $site=$service->refresh(true);
        }
        if (!$site['can_customize']) throw new \RuntimeException('当前平台授权不允许自定义版权 (COMMERCIAL_LICENSE_REQUIRED)');
    }

    /** Filing identities are legal site information, independent of branding rights. */
    public static function registrationItems(array $items): array
    {
        return array_values(array_filter(self::safeItems($items), static fn(array $item): bool =>
            preg_match('/(?:ICP\s*[备证]|公网安备|网安备|备案)/iu', $item['key']) === 1
        ));
    }

    public static function safeItems(array $items): array
    {
        return array_values(array_map(static fn($item)=>[
            'key'=>(string)($item['key']??''),'value'=>SignedLicenseProtocol::httpUrl((string)($item['value']??'')),
        ],array_filter($items,'is_array')));
    }
}
