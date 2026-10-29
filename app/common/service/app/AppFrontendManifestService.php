<?php

namespace app\common\service\app;

use app\common\service\membership\MembershipService;

class AppFrontendManifestService
{
    public static function tenantEntries(int $tenantId, string $terminal, int $userId = 0): array
    {
        $entries = AppRegistryService::frontendEntries($tenantId, $terminal);
        foreach ($entries as &$entry) {
            if (($entry['app_code'] ?? '') === 'aigc_copywriting') {
                $target=$terminal==='pc'?'pc':'h5';
                $pages=[];
                foreach (\app\common\service\app\aigc_copywriting\CopywritingToolService::lists($tenantId,true,$target) as $tool) {
                    $pages[]=['name'=>$tool['name'],'path'=>$terminal==='pc'?'/app/aigc_copywriting/tool/'.$tool['tool_code']:'/apps/aigc_copywriting/pages/tool/index','query'=>$terminal==='pc'?[]:['code'=>$tool['tool_code']]];
                }
                $meta=(array)($entry['meta']??[]);$meta['pages']=array_merge((array)($meta['pages']??[]),$pages);$entry['meta']=$meta;
            }
            $access = MembershipService::appAccess($tenantId, $userId, (string)($entry['app_code'] ?? ''));
            $entry['need_membership'] = $access['need_membership'];
            $entry['membership_allowed'] = $access['allowed'];
            $entry['member_status'] = $access['member_status'];
        }
        return $entries;
    }
}
