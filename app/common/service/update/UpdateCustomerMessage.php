<?php
namespace app\common\service\update;

/** Presentation only. Update services keep their original diagnostics and safety checks. */
class UpdateCustomerMessage
{
    public static function format(string $message, string $errorCode = ''): string
    {
        if ($message === '') return '';
        if (str_contains($message, '本项目启动脚本仍运行')) {
            return '请先停止本项目的进程守护和计划任务，再重新更新。';
        }
        if (str_contains($message, '本项目任务或 cron 进程仍运行')) {
            return '请等待当前任务完成，再停止本项目的进程守护和计划任务，然后重新更新。';
        }
        if (preg_match('/无法核验本项目任务进程|无法枚举.*项目进程|进程读取权限不足|无法解析任务进程的工作目录/', $message)) {
            return '无法确认本项目的任务进程是否已停止，请联系服务器管理员检查进程状态和读取权限后，再重新更新。';
        }
        $protocolMessages = [
            'LICENSE_NETWORK_UNAVAILABLE' => '暂时无法连接更新服务，请检查服务器网络后重试。',
            'LICENSE_SOURCE_MISSING' => '更新服务尚未配置完整，请联系管理员完成配置后重试。',
            'SYSTEM_CONTEXT_CHANGED' => '授权信息或更新服务配置已变化，请刷新页面后重新更新。',
            'SYSTEM_DOWNLOAD_REAUTHORIZE' => '更新包的下载凭证已失效，请重新下载后再更新。',
            'SYSTEM_PACKAGE_HASH_MISMATCH' => '更新包不完整或已损坏，请重新下载后再更新。',
        ];
        if (isset($protocolMessages[$errorCode])) return $protocolMessages[$errorCode];
        $rules = [
            '/目录不可写|无法(?:创建|写入|替换|复制|备份).*?(?:目录|文件)/' => '更新目录无法写入，请联系服务器管理员检查站点目录的写入权限后重试。',
            '/open_basedir/' => '服务器限制了更新目录的访问，请联系服务器管理员检查目录访问权限后重试。',
            '/磁盘.*空间不足/' => '服务器剩余空间不足，请清理磁盘或扩容后重试，至少保留更新包大小三倍的可用空间。',
            '/没有可用解压能力|解压失败|unzip|PharData|7z/' => '更新包无法解压，请联系服务器管理员检查解压功能，或重新下载更新包后重试。',
            '/SQLSTATE|MySQL server has gone away|数据库连接/' => '数据库连接异常，请联系服务器管理员检查数据库运行状态后重试。',
            '/签名|checksum|文件校验失败|(?:更新包|系统包).*(?:格式错误|缺少|不一致|不允许|非法路径)/' => '更新包校验未通过，请重新下载更新包；如果仍无法更新，请联系技术支持。',
            '/运行时(?:指针|目录|元数据)无效|依赖包缺失|无法判定.*任务进程影响/' => '系统运行环境检查未通过，请联系技术支持检查后，再重新更新。',
        ];
        foreach ($rules as $pattern => $description) {
            if (preg_match($pattern, $message)) return $description;
        }
        // Keep useful, already readable instructions, without appended protocol identifiers.
        $message = trim((string)preg_replace('/\s*\([A-Z][A-Z0-9_]+\)\s*$/', '', $message));
        if (!preg_match('/[\x{4e00}-\x{9fff}]/u', $message)
            || preg_match('#(?:[a-zA-Z0-9_.-]+/)+|\\\\|\.(?:php|sh|mjs|json|sql)\b|Supervisor|\bcron\b|SQLSTATE|Composer|[A-Za-z]+(?:Service|Exception|Controller)\b|[A-Z][A-Z0-9]+_[A-Z0-9_]+#', $message)) {
            return '更新未能完成，请联系技术支持并提供更新日志，以便检查后重新更新。';
        }
        return $message;
    }

    /** Run after the service has saved its unmodified execution record. */
    public static function present(array $result): array
    {
        if (!empty($result['error'])) $result['error'] = self::logged((string)$result['error'], (string)($result['error_code'] ?? ''));
        if (isset($result['errors']) && is_array($result['errors'])) {
            $result['errors'] = array_values(array_unique(array_map(static fn($error) => self::logged((string)$error), $result['errors'])));
        }
        if (isset($result['extract']) && is_array($result['extract'])) {
            foreach (['error', 'suggestion'] as $field) {
                if (!empty($result['extract'][$field])) $result['extract'][$field] = self::logged((string)$result['extract'][$field]);
            }
        }
        if (isset($result['process_impact']['reasons']) && is_array($result['process_impact']['reasons'])) {
            $summaries = [];
            foreach ($result['process_impact']['reasons'] as $reason) {
                $summaries[] = str_starts_with($reason, '数据库迁移:') ? '更新系统数据'
                    : (str_starts_with($reason, '任务依赖:') ? '更新任务运行组件'
                    : ($reason === '首次安装运行时隔离机制' ? '更新系统运行环境' : '更新任务处理程序'));
            }
            $result['process_impact']['reasons'] = array_values(array_unique($summaries));
        }
        return $result;
    }

    private static function logged(string $message, string $errorCode = ''): string
    {
        $description = self::format($message, $errorCode);
        if ($description !== $message) \think\facade\Log::error('[系统更新] ' . $message);
        return $description;
    }
}
