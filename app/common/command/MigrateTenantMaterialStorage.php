<?php

namespace app\common\command;

use app\common\model\TenantConfig;
use app\common\model\file\TenantFile;
use app\common\service\storage\StorageConfigService;
use OSS\OssClient;
use Qcloud\Cos\Client as CosClient;
use think\console\Command;
use think\console\Input;
use think\console\input\Option;
use think\console\Output;

/**
 * Copies tenant material-library files to a newly selected storage engine.
 *
 * The database record is only switched after the destination object exists.
 * Source objects are deliberately retained so a failed or interrupted migration
 * remains recoverable.
 */
class MigrateTenantMaterialStorage extends Command
{
    protected function configure(): void
    {
        $this->setName('storage:migrate-tenant-materials')
            ->setDescription('迁移租户素材中心的历史文件到当前存储（默认仅预览）')
            ->addOption('tenant-id', null, Option::VALUE_REQUIRED, '租户 ID')
            ->addOption('source-engine', null, Option::VALUE_OPTIONAL, '历史存储引擎', 'qcloud')
            ->addOption('target-engine', null, Option::VALUE_OPTIONAL, '目标存储引擎；默认租户当前默认引擎')
            ->addOption('limit', null, Option::VALUE_OPTIONAL, '本次最多迁移条数', 2000)
            ->addOption('apply', null, Option::VALUE_NONE, '执行迁移；未传时只统计');
    }

    protected function execute(Input $input, Output $output): int
    {
        $tenantId = max(0, (int)$input->getOption('tenant-id'));
        $sourceEngine = strtolower(trim((string)$input->getOption('source-engine')));
        $limit = max(1, min(5000, (int)$input->getOption('limit')));
        $apply = (bool)$input->getOption('apply');
        if ($tenantId <= 0) {
            $output->error('tenant-id 必须为正整数');
            return 1;
        }
        if ($sourceEngine !== 'qcloud') {
            $output->error('当前迁移器仅支持腾讯云 COS 作为历史来源');
            return 1;
        }

        $targetEngine = strtolower(trim((string)$input->getOption('target-engine')));
        if ($targetEngine === '') {
            $targetEngine = strtolower(trim((string)TenantConfig::where([
                'tenant_id' => $tenantId,
                'type' => 'storage',
                'name' => 'default',
            ])->value('value')));
        }
        if ($targetEngine !== 'aliyun') {
            $output->error('当前迁移器仅支持迁移至阿里云 OSS；请先确认租户默认存储配置');
            return 1;
        }
        if ($sourceEngine === $targetEngine) {
            $output->error('历史存储与目标存储相同，无需迁移');
            return 1;
        }

        $source = StorageConfigService::getStoredFileConfig($tenantId, 'tenant', $sourceEngine)['engine'][$sourceEngine] ?? [];
        $target = StorageConfigService::getStoredFileConfig($tenantId, 'tenant', $targetEngine)['engine'][$targetEngine] ?? [];
        foreach (['bucket', 'region', 'access_key', 'secret_key'] as $key) {
            if (trim((string)($source[$key] ?? '')) === '') {
                $output->error('历史 COS 存储配置不完整，无法读取源文件');
                return 1;
            }
        }
        foreach (['bucket', 'domain', 'access_key', 'secret_key'] as $key) {
            if (trim((string)($target[$key] ?? '')) === '') {
                $output->error('当前 OSS 存储配置不完整，无法写入目标文件');
                return 1;
            }
        }

        $query = TenantFile::where('tenant_id', $tenantId)
            ->where('storage_scope', 'tenant')
            ->where('storage_engine', $sourceEngine)
            ->where('uri', '<>', '')
            ->order('id', 'asc');
        $matched = (clone $query)->count();
        $files = $query->limit($limit)->select();
        $output->writeln(sprintf('%s matched=%d batch=%d source=%s target=%s', $apply ? 'migrating' : 'dry-run', $matched, count($files), $sourceEngine, $targetEngine));
        if (!$apply || $files->isEmpty()) {
            return 0;
        }

        $sourceClient = new CosClient([
            'region' => $source['region'],
            'credentials' => ['secretId' => $source['access_key'], 'secretKey' => $source['secret_key']],
        ]);
        $targetClient = new OssClient($target['access_key'], $target['secret_key'], $target['domain'], true);
        $migrated = 0;
        $failed = 0;
        foreach ($files as $file) {
            $uri = ltrim((string)$file->uri, '/');
            $temporaryPath = tempnam(sys_get_temp_dir(), 'tenant-material-');
            try {
                if ($temporaryPath === false) {
                    throw new \RuntimeException('无法创建临时文件');
                }
                $sourceClient->getObject([
                    'Bucket' => $source['bucket'],
                    'Key' => $uri,
                    'SaveAs' => $temporaryPath,
                ]);
                if (!is_file($temporaryPath) || filesize($temporaryPath) === 0) {
                    throw new \RuntimeException('源文件为空或下载失败');
                }
                $options = [];
                $mime = function_exists('mime_content_type') ? (string)@mime_content_type($temporaryPath) : '';
                if ($mime !== '') {
                    $options[OssClient::OSS_CONTENT_TYPE] = $mime;
                }
                $targetClient->uploadFile($target['bucket'], $uri, $temporaryPath, $options);
                if (!$targetClient->doesObjectExist($target['bucket'], $uri)) {
                    throw new \RuntimeException('目标文件校验失败');
                }
                $updated = TenantFile::where('id', (int)$file->id)
                    ->where('tenant_id', $tenantId)
                    ->where('storage_engine', $sourceEngine)
                    ->update([
                        'storage_scope' => 'tenant',
                        'storage_engine' => $targetEngine,
                        'storage_domain' => (string)$target['domain'],
                        'update_time' => time(),
                    ]);
                if ($updated !== 1) {
                    throw new \RuntimeException('素材记录在迁移过程中已变化，未切换存储标记');
                }
                $migrated++;
            } catch (\Throwable $exception) {
                $failed++;
                $output->writeln(sprintf('failed id=%d reason=%s', (int)$file->id, $this->safeError($exception)));
            } finally {
                if ($temporaryPath && is_file($temporaryPath)) {
                    @unlink($temporaryPath);
                }
            }
        }
        $output->writeln(sprintf('completed migrated=%d failed=%d', $migrated, $failed));
        return $failed === 0 ? 0 : 1;
    }

    private function safeError(\Throwable $exception): string
    {
        return mb_substr(trim((string)$exception->getMessage()) ?: '迁移失败', 0, 300, 'UTF-8');
    }
}
