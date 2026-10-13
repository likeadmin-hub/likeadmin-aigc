<?php
namespace app\common\service\update;

/** Presentation only. Update services keep their original diagnostics and safety checks. */
class UpdateCustomerMessage
{
    public static function format(string $message, string $errorCode = ''): string
    {
        if ($message === '') return '';
        if (str_contains($message, '文件回滚失败') || str_contains($message, '原子回滚失败')) {
            return '更新未完成，自动恢复也未成功。请联系技术支持检查站点和备份，确认恢复正常后再更新。';
        }
        if (str_contains($message, '上次 SQL 执行中断') || str_contains($message, '更新子进程状态待核验')) {
            return '上次更新中断，处理结果尚未确认。请勿重复更新，请联系技术支持核对数据库和更新记录后再继续。';
        }
        if (str_contains($message, '本项目启动脚本仍运行')) {
            return '请先停止本项目的进程守护和计划任务，再重新更新。';
        }
        if (str_contains($message, '本项目任务或 cron 进程仍运行')) {
            return '请等待当前任务完成，再停止本项目的进程守护和计划任务，然后重新更新。';
        }
        if (preg_match('/无法核验本项目任务进程|无法枚举.*项目进程|进程读取权限不足|无法解析任务进程的工作目录/', $message)) {
            return '无法确认本项目的任务进程是否已停止，请联系服务器管理员检查进程状态和读取权限后，再重新更新。';
        }
        if (preg_match('/请先安装 Composer 更新器桥接版本 (\d+\.\d+\.\d+)/', $message, $match)) {
            return '请先将系统升级到 ' . $match[1] . '，再继续更新后续版本。';
        }
        if (preg_match('/PHP\s*(?:>=\s*)?(\d+\.\d+(?:\.\d+)?)/', $message, $match)) {
            return '服务器运行环境版本不符合要求，请联系服务器管理员将网站和任务运行环境升级到 ' . $match[1] . ' 或更高版本，再重新更新。';
        }
        if (in_array($errorCode, ['', 'SYSTEM_UPDATE_FAILED', 'APP_UPDATE_FAILED'], true)
            && preg_match('/(?:^|\()([A-Z][A-Z0-9_]+)\)?$/', $message, $match)) $errorCode = $match[1];
        $protocolMessages = [
            'LICENSE_NETWORK_UNAVAILABLE' => '暂时无法连接更新服务，请检查服务器网络后重试。',
            'LICENSE_SOURCE_MISSING' => '更新服务尚未配置完整，请联系管理员完成配置后重试。',
            'SYSTEM_CONTEXT_CHANGED' => '授权信息或更新服务配置已变化，请刷新页面后重新更新。',
            'SYSTEM_DOWNLOAD_REAUTHORIZE' => '更新包的下载凭证已失效，请重新下载后再更新。',
            'SYSTEM_PACKAGE_HASH_MISMATCH' => '更新包不完整或已损坏，请重新下载后再更新。',
            'SYSTEM_PACKAGE_GRANT_EXPIRED' => '更新包的安装授权已过期，请重新获取更新包后再更新。',
            'SYSTEM_PACKAGE_GRANT_INVALID' => '此更新包的授权与当前站点不匹配，请联系管理员核对站点授权后重新获取更新包。',
            'SYSTEM_PACKAGE_UNAVAILABLE' => '当前版本暂无可用更新包，请联系技术支持确认可更新的版本。',
            'SYSTEM_RESPONSE_INVALID' => '更新服务返回的信息异常，请联系管理员检查更新服务配置后重试。',
            'LICENSE_SIGNATURE_INVALID' => '更新服务的安全校验未通过，请联系管理员检查更新服务配置后重试。',
            'LICENSE_MACHINE_FORBIDDEN' => '本站授权与当前服务器不匹配，请联系管理员核对站点授权。',
            'LICENSE_INVALID' => '本站授权文件无效，请联系管理员重新导入有效的授权文件。',
            'LICENSE_EXPIRED' => '本站授权已失效，请联系管理员核对或更新站点授权后重试。',
        ];
        if (isset($protocolMessages[$errorCode])) return $protocolMessages[$errorCode];
        $rules = [
            '/当前 PHP 缺少运行时扩展|Composer 依赖缺少 PHP 扩展/' => '服务器缺少更新所需的运行组件，请联系服务器管理员安装所需组件后重试。',
            '/PHP CLI|proc_open/' => '服务器无法启动更新检查程序，请联系服务器管理员检查运行环境和程序执行权限后重试。',
            '/已有.*(?:升级|更新).*执行/' => '已有更新任务正在执行，请等待完成后再操作。若长时间没有进展，请稍后检查更新记录或联系技术支持。',
            '/应用包 app_code/' => '应用更新包与所选应用不匹配，请重新下载对应应用的更新包。',
            '/桥接包|步进包|桥接版本/' => '请按页面提示逐个版本更新；如果没有可用版本，请联系技术支持确认升级顺序。',
            '/自举|元数据事务|事务被隐式提交/' => '系统更新状态检查未通过，请联系技术支持核对更新记录后再继续。',
            '/更新源响应格式错误/' => '更新服务返回的信息异常，请联系管理员检查更新服务配置后重试。',
            '/cURL error|更新源.*(?:连接失败|暂时不可用)/' => '暂时无法连接更新服务，请检查服务器网络后重试。',
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
            || preg_match('#(?:[a-zA-Z0-9_.-]+/)+|\\\\|\.(?:php|sh|mjs|json|sql)\b|Supervisor|\bcron\b|SQLSTATE|Composer|[A-Za-z]+(?:Service|Exception|Controller)\b|[A-Z][A-Z0-9]+_[A-Z0-9_]+|[a-zA-Z_][a-zA-Z0-9_]*\(|<[^>]+>#', $message)) {
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
        foreach (['versions', 'lists'] as $key) {
            if (isset($result[$key]) && is_array($result[$key])) {
                $result[$key] = array_map(static fn($version) => is_array($version) ? self::version($version) : $version, $result[$key]);
            }
        }
        foreach (['latest', 'current_version_log'] as $key) {
            if (isset($result[$key]) && is_array($result[$key])) $result[$key] = self::version($result[$key]);
        }
        return self::version($result);
    }

    private static function version(array $version): array
    {
        if (!empty($version['deny_message'])) $version['deny_message'] = self::logged((string)$version['deny_message'], (string)($version['deny_reason'] ?? ''));
        return $version;
    }

    private static function logged(string $message, string $errorCode = ''): string
    {
        $description = self::format($message, $errorCode);
        if ($description !== $message) \think\facade\Log::error('[系统更新] ' . $message);
        return $description;
    }
}
