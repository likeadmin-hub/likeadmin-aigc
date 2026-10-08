<?php

namespace app\common\service\update;

use think\facade\Db;

/** Private signed proof; never serialize download credentials into public package/task responses. */
class SystemPackageGrantService
{
    private UpdateSourceClient $client;

    public function __construct(?UpdateSourceClient $client = null)
    {
        $this->client = $client ?? new UpdateSourceClient();
    }

    public function save(int $packageId, array $response, array $package): void
    {
        $context = (new UpdateLicenseService())->verifiedSiteContext();
        $source = UpdateSourceClient::getSource();
        if (empty($context['verified']) || $context['status'] !== 'active'
            || SystemUpdateProtocol::contextKey($context, $source) !== $response['context_key']) {
            throw new UpdateProtocolException('SYSTEM_CONTEXT_CHANGED', '包下载期间授权或更新源已变更');
        }
        SystemUpdateProtocol::package($response['response_json'], $source['public_key'], $context,
            (string)$package['version'], (string)$package['sha256'], (string)$package['format']);
        $values = ['context_key' => $response['context_key'], 'response_json' => $response['response_json'], 'update_time' => time()];
        if (Db::name('system_package_grant')->where('package_id', $packageId)->find()) {
            Db::name('system_package_grant')->where('package_id', $packageId)->update($values);
        } else {
            Db::name('system_package_grant')->insert(array_merge($values, ['package_id' => $packageId, 'create_time' => time()]));
        }
    }

    public function ensure(array $package): array
    {
        (new UpdateLicenseService())->assertSystemUpdateAllowed((string)$package['version']);
        if (!is_file($package['local_path']) || !preg_match('/^[a-f0-9]{64}$/D', (string)$package['sha256'])
            || !hash_equals((string)$package['sha256'], (string)hash_file('sha256', $package['local_path']))) {
            throw new UpdateProtocolException('SYSTEM_PACKAGE_HASH_MISMATCH', '本地系统包摘要校验失败');
        }
        $context = (new UpdateLicenseService())->verifiedSiteContext();
        $source = UpdateSourceClient::getSource();
        $proof = Db::name('system_package_grant')->where('package_id', (int)$package['id'])->find();
        if ($proof && $proof['context_key'] === SystemUpdateProtocol::contextKey($context, $source)) {
            try {
                return SystemUpdateProtocol::package($proof['response_json'], $source['public_key'], $context,
                    $package['version'], $package['sha256'], $package['format']);
            } catch (UpdateProtocolException $e) {
                if ($e->errorCode !== 'SYSTEM_PACKAGE_GRANT_EXPIRED') throw $e;
            }
        }
        // Missing legacy proof, changed certificate/source, or an expired one-hour grant:
        // authorize this exact installed file online; a different package hash cannot replace it.
        $response = $this->client->systemRequest('system/package', [
            'target_version' => $package['version'], 'current_version' => UpdateSourceClient::currentCoreVersion(),
            'upgrade_mode' => 'step', 'action' => 'authorize',
        ]);
        $this->save((int)$package['id'], $response, $package);
        $fresh = (new UpdateLicenseService())->verifiedSiteContext();
        return SystemUpdateProtocol::package($response['response_json'], UpdateSourceClient::getSource()['public_key'],
            $fresh, $package['version'], $package['sha256'], $package['format']);
    }
}
