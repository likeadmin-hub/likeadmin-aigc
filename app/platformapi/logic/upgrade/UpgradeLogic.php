<?php
// +----------------------------------------------------------------------
// | likeadmin快速开发前后端分离管理后台（PHP版）
// +----------------------------------------------------------------------
// | 欢迎阅读学习系统程序代码，建议反馈是我们前进的动力
// | 开源版本可自由商用，可去除界面版权logo
// | gitee下载：https://gitee.com/likeshop_gitee/likeadmin
// | github下载：https://github.com/likeshop-github/likeadmin
// | 访问官网：https://www.likeadmin.cn
// | likeadmin团队 版权所有 拥有最终解释权
// +----------------------------------------------------------------------
// | author: likeadminTeam
// +----------------------------------------------------------------------

namespace app\platformapi\logic\upgrade;

use app\common\logic\BaseLogic;
use app\common\model\auth\TenantSystemMenu;
use app\common\model\tenant\Tenant;
use app\common\service\database\SqlMigrationExecutor;
use app\platformapi\logic\tenant\TenantSystemMenuLogic;
use Exception;
use think\facade\Cache;
use think\facade\Db;
use think\facade\Log;
use WpOrg\Requests\Requests;
use WpOrg\Requests\Response;

/**
 * 升级逻辑
 */
class UpgradeLogic extends BaseLogic
{
    const BASE_URL = 'https://server.mddai.cn';

    const PRODUCT_CODE = '462953db655787cb99deb5893f8d523a';

    private const UPGRADE_TIMEOUT = 600;

    /**
     * @notes 格式化列表数据
     * @param $lists
     * @param $pageNo
     * @return array
     * @author 段誉
     */
    public static function formatLists($lists, $pageNo): array
    {
        $localData = local_version();
        $localVersion = $localData['version'];

        foreach ($lists as $k => $item) {
            // 版本描述
            $lists[$k]['version_str'] = '';
            $lists[$k]['able_update'] = 0;
            if ($localVersion == $item['version_no']) {
                $lists[$k]['version_str'] = '您的系统当前处于此版本';
            }
            if (version_compare($localVersion, $item['version_no'], '<') && ($item['can_download'] ?? false) === true) {
                $lists[$k]['version_str'] = '系统可更新至此版本';
                $lists[$k]['able_update'] = 1;
            }

            // 最新的版本号标志
            $lists[$k]['new_version'] = 0;

            // 注意,是否需要重新发布描述
            $lists[$k]['notice'] = [];
            if ($item['uniapp_publish'] == 1) {
                $lists[$k]['notice'][] = '更新至当前版本后需重新发布手机端前端前台';
            }
            if ($item['pc_admin_publish'] == 1) {
                $lists[$k]['notice'][] = '更新至当前版本后需重新发布前端PC后台';
            }
            if ($item['pc_shop_publish'] == 1) {
                $lists[$k]['notice'][] = '更新至当前版本后需重新发布前端PC前台';
            }
            $lists[$k]['notice'][] = $item['publish_content'] ?? '';

            // 处理更新内容信息
            $contents = $item['update_content'];
            $add = [];
            $optimize = [];
            $repair = [];
            $contentDesc = [];
            if (!empty($contents)) {
                foreach ($contents as $content) {
                    if ($content['type'] == 1) {
                        $add[] = '新增:' . $content['update_function'];
                    }
                    if ($content['type'] == 2) {
                        $optimize[] = '优化:' . $content['update_function'];
                    }
                    if ($content['type'] == 3) {
                        $repair[] = '修复:' . $content['update_function'];
                    }
                }
                $contentDesc = array_merge($add, $optimize, $repair);
            }
            $lists[$k]['add'] = $add;
            $lists[$k]['optimize'] = $optimize;
            $lists[$k]['repair'] = $repair;
            $lists[$k]['content_desc'] = $contentDesc;
            unset($lists[$k]['update_content']);
        }
        if ($lists) $lists[0]['new_version'] = ($pageNo == 1) ? 1 : 0;
        return $lists;
    }

    /**
     * @notes 更新操作
     * @param $params
     * @return bool
     * @author 段誉
     * @date 2021/8/14 17:19
     */
    public static function upgrade($params): bool
    {
        try {
            if (!filter_var($params['backup_confirmed'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
                throw new Exception('请先确认已完成站点文件和数据库备份');
            }
            $service = new \app\common\service\update\SystemPackageUpdateService();
            $version = self::cloudVersionById((int)$params['id']);
            $package = $service->downloadPackage($version);
            $preflight = $service->preflight((int)$package['id']);
            if (empty($preflight['passed'])) throw new Exception(implode(';', $preflight['errors'] ?? []));
            $service->apply((int)$package['id']);
            return true;
        } catch (\Throwable $e) {
            self::$error = $e->getMessage();
            return false;
        }
    }

    private static function cloudVersionById(int $id): string
    {
        $data = (new \app\common\service\update\SystemPackageUpdateService())->versions();
        foreach (($data['lists'] ?? []) as $row) {
            if ((int)($row['id'] ?? 0) === $id) return (string)$row['version'];
        }
        throw new Exception('更新源未提供该版本');
    }

    public static function verify($params): mixed
    {
        throw new Exception('旧授权接口已停用，请使用本站版本更新服务');
    }

    /**
     * @notes 获取远程版本数据
     * @param null $pageNo
     * @param null $pageSize
     * @return mixed
     * @author 段誉
     * @date 2021/8/14 17:20
     */
    public static function getRemoteVersion($pageNo = null, $pageSize = null): mixed
    {
        $data = (new \app\common\service\update\SystemPackageUpdateService())->versions();
        $rows = $data['lists'] ?? [];
        foreach ($rows as &$row) {
            $row['version_no'] = $row['version'];
            $row['uniapp_publish'] = $row['pc_admin_publish'] = $row['pc_shop_publish'] = 0;
            $content = $row['changelog'] ?? [];
            if (!is_array($content)) $content = array_filter(explode("\n", (string)$content));
            $row['update_content'] = array_map(static fn($item) => is_array($item) ? $item : ['type'=>2,'update_function'=>(string)$item], $content);
        }
        unset($row);
        return ['lists' => array_slice($rows, max(0, ((int)($pageNo ?: 1) - 1) * (int)($pageSize ?: 15)), (int)($pageSize ?: 15)),
            'count' => count($rows)];
    }

    /**
     * @notes 更新包下载链接
     * @param $params
     * @return array|false
     * @author 段誉
     * @date 2022/3/25 17:50
     */
    public static function getPkgLine($params): bool|array
    {
        try {
            if (!in_array((int)($params['update_type'] ?? 0), [1,2], true)) {
                throw new Exception('此更新源未提供独立终端包或完整重装包，请使用版本更新页');
            }
            $version = self::cloudVersionById((int)$params['id']);
            $response = (new \app\common\service\update\UpdateSourceClient())->systemRequest('system/package', [
                'target_version' => $version, 'current_version' => \app\common\service\update\UpdateSourceClient::currentCoreVersion(),
                'upgrade_mode' => 'step',
            ]);
            $source = \app\common\service\update\UpdateSourceClient::getSource();
            $context = (new \app\common\service\update\UpdateLicenseService())->verifiedSiteContext();
            $data = \app\common\service\update\SystemUpdateProtocol::package($response['response_json'], $source['public_key'], $context, $version);
            return ['line' => $data['download_url'], 'expires_at' => $data['site_grant']['expires_at']];
        } catch (\Throwable $e) {
            self::$error = $e->getMessage();
            return false;
        }
    }

    /**
     * @notes 添加日志
     * @param mixed $versionId (版本id)
     * @param mixed $updateType (更新类型)
     * @param bool $status (更新状态)
     * @return bool|Response
     * @author 段誉
     * @date 2021/10/9 14:48
     */
    public static function addLog(mixed $versionId, mixed $updateType, bool $status = true): bool|Response
    {
        //版本信息
        $versionData = self::getVersionDataById($versionId);
        $domain = request()->host(true);
        try {
            $paramsData = [
                'version_id'   => $versionData['id'],
                'version_no'   => $versionData['version_no'],
                'domain'       => $domain,
                'type'         => 2,//付费版
                'product_code' => self::PRODUCT_CODE,
                'update_type'  => $updateType,
                'status'       => $status ? 1 : 0,
                'error'        => empty(self::$error) ? '' : self::$error,
            ];
            $requestUrl = self::BASE_URL . '/indexapi/version/log';
            return Requests::post($requestUrl, [], $paramsData);
        } catch (Exception $e) {
            Log::write('更新日志:' . '更新失败' . $e->getMessage());
            return false;
        }
    }

    /**
     * @notes 通过版本记录id获取版本信息
     * @param $id
     * @return array
     * @author 段誉
     * @date 2021/10/9 11:40
     */
    public static function getVersionDataById($id): array
    {
        $cacheVersion = self::getRemoteVersion()['lists'] ?? [];
        if (!empty($cacheVersion)) {
            $versionColumn = array_column($cacheVersion, null, 'id');
            if (!empty($versionColumn[$id])) {
                return $versionColumn[$id];
            }
        }
        return [];
    }

    /**
     * @notes 下载远程文件
     * @param $url
     * @param string $savePath
     * @return array|false
     * @author 段誉
     * @date 2021/8/14 17:20
     */
    public static function downFile($url, string $savePath = './upgrade/'): bool|array
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_HEADER, TRUE);
        curl_setopt($ch, CURLOPT_NOBODY, FALSE);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 30);
        curl_setopt($ch, CURLOPT_TIMEOUT, self::UPGRADE_TIMEOUT);
        $response = curl_exec($ch);
        $body = '';
        if (curl_getinfo($ch, CURLINFO_HTTP_CODE) == '200') {
            $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
            $body = substr($response, $headerSize);
        }
        curl_close($ch);
        // 文件名
        $fullName = basename($url);
        // 文件保存完整路径
        $savePath = $savePath . $fullName;
        // 创建目录并设置权限
        $basePath = dirname($savePath);
        if (!file_exists($basePath)) {
            @mkdir($basePath, 0777, true);
            @chmod($basePath, 0777);
        }
        if (file_put_contents($savePath, $body)) {
            return [
                'save_path' => $savePath,
                'file_name' => $fullName,
            ];
        }
        return false;
    }

    /**
     * @notes 获取项目路径
     * @return string
     * @author 段誉
     * @date 2021/8/14 17:20
     */
    public static function getProjectPath(): string
    {
        $path = defined('ROOT_PATH') ? dirname(ROOT_PATH) : root_path();
        if (!str_ends_with($path, '/')) {
            $path = $path . '/';
        }
        return $path;
    }

    /**
     * @notes 更新MysqlSql
     * @param $dir
     * @return bool
     * @author 段誉
     * @date 2021/8/14 17:20
     */
    public static function upgradeSql($dir): bool
    {
        // 没有sql文件时无需更新
        if (!file_exists($dir)) {
            return true;
        }
        // 遍历指定目录下的指定后缀文件
        $sqlFiles = get_scanDir($dir, '', 'sql');
        if (false === $sqlFiles) {
            return false;
        }

        // 当前数据库前缀
        $sqlPrefix = config('database.connections.mysql.prefix');

        foreach ($sqlFiles as $item) {
            if (get_extension($item) != 'sql') {
                continue;
            }
            if (self::isReadmeSql($item)) {
                continue;
            }
            $sqlContent = file_get_contents($dir . $item);
            if (empty($sqlContent) || SqlMigrationExecutor::split($sqlContent) === []) {
                continue;
            }
            SqlMigrationExecutor::execute($sqlContent, $sqlPrefix);
        }
        return true;
    }

    private static function isReadmeSql(string $path): bool
    {
        $filename = strtolower(basename($path));
        return $filename === 'readme.sql' || str_starts_with($filename, 'readme.');
    }

    /**
     * @notes 更新PgSql
     * @param $dir
     * @return bool
     * @author fzr
     * @date 2024/01/26 10:00
     */
    public static function upgradePgSql($dir): bool
    {
        // 没有sql文件时无需更新
        if (!file_exists($dir)) {
            return true;
        }

        // 遍历指定目录下的指定后缀文件
        $sqlFiles = get_scanDir($dir, '', 'sql');
        if (false === $sqlFiles) {
            return false;
        }

        // 当前数据库前缀
        $sqlPrefix = config('database.connections.pgsql.prefix');
        $db = app('db')->connect('pgsql');

        foreach ($sqlFiles as $item) {
            if (get_extension($item) != 'sql') {
                continue;
            }
            $sqlContent = file_get_contents($dir . $item);
            if (empty($sqlContent)) {
                continue;
            }
            SqlMigrationExecutor::execute($sqlContent, $sqlPrefix, $db, false, 'pgsql');
        }
        return true;
    }

    /**
     * @notes 更新文件
     * @param $tempFile
     * @param $oldFile
     * @return bool
     * @author 段誉
     * @date 2021/8/14 17:21
     */
    public static function upgradeFile($tempFile, $oldFile, string $relative = ''): bool
    {
        $tempFile = rtrim((string)$tempFile, DIRECTORY_SEPARATOR);
        $oldFile = rtrim((string)$oldFile, DIRECTORY_SEPARATOR);
        if ($tempFile === '' || $oldFile === '' || !is_dir($tempFile)) {
            return false;
        }

        try {
            if (file_exists($oldFile) && !is_dir($oldFile)) {
                return false;
            }
            if (!is_dir($oldFile) && !@mkdir($oldFile, 0777, true) && !is_dir($oldFile)) {
                return false;
            }

            $iterator = new \FilesystemIterator($tempFile, \FilesystemIterator::SKIP_DOTS);
            foreach ($iterator as $item) {
                if ($item->isLink()) {
                    return false;
                }
                $entryPath = ltrim($relative . '/' . $item->getFilename(), '/');
                if (preg_match('#^(?:oem|public/oem-assets)(?:/|$)#i', $entryPath)) continue;
                $fileName = $item->getPathname();
                $target = $oldFile . DIRECTORY_SEPARATOR . $item->getFilename();
                if ($item->isDir()) {
                    if (file_exists($target) && !is_dir($target)) {
                        return false;
                    }
                    if (!self::upgradeFile($fileName, $target, $entryPath)) {
                        return false;
                    }
                    continue;
                }
                if (!$item->isFile() || is_dir($target)) {
                    return false;
                }
                if (!file_exists($target) || md5_file($fileName) !== md5_file($target)) {
                    if (!@copy($fileName, $target)) {
                        return false;
                    }
                }
            }
        } catch (\Throwable $e) {
            return false;
        }
        return true;
    }

    /**
     * @notes 更新菜单操作
     * @param string $dir
     * @return bool
     * @author yfdong
     * @date 2024/12/04 22:41
     */
    private static function upgradeMenu(string $dir)
    {
        // 存在文件时代表菜单信息有更新，需要进行全量更新
        if (!file_exists($dir)) {
            return true;
        }
        // 获取全部租户信息
        $tenantUser = Tenant::query()->select()->toArray();
        foreach ($tenantUser as $tenant) {
            // 删除对应租户菜单信息
            TenantSystemMenu::query()->where(['tenant_id' => $tenant['id']])->delete();

            // 增加对应菜单信息 创建租户菜单权限
            TenantSystemMenuLogic::initialization($tenant['id']);
        }
        return true;
    }
}
