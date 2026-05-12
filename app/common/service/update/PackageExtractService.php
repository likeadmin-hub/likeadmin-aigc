<?php

namespace app\common\service\update;

use PharData;
use RuntimeException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use FilesystemIterator;
use Throwable;
use ZipArchive;

class PackageExtractService
{
    private array $fallbackTried = [];
    private array $driverErrors = [];

    public static function environment(string $targetDir = ''): array
    {
        $disabled = array_filter(array_map('trim', explode(',', (string)ini_get('disable_functions'))));
        $root = root_path();
        $runtimeDir = runtime_path() . 'update_temp';
        $workspaceDir = runtime_path() . 'update_workspace';
        $versionDir = self::versionDir();
        $targetDir = $targetDir ?: $root;
        $diskFree = @disk_free_space($root) ?: 0;

        return [
            'zip_archive' => class_exists(ZipArchive::class),
            'phar_data' => class_exists(PharData::class),
            'command' => [
                'exec' => self::functionEnabled('exec'),
                'shell_exec' => function_exists('shell_exec') && !in_array('shell_exec', $disabled, true),
                'proc_open' => function_exists('proc_open') && !in_array('proc_open', $disabled, true),
                'unzip' => self::commandAvailable('unzip -v'),
                '7z' => self::commandAvailable('7z'),
                'tar' => self::commandAvailable('tar --version'),
            ],
            'paths' => [
                'runtime_update_temp' => [
                    'path' => $runtimeDir,
                    'writable' => self::ensureWritableDirectory($runtimeDir),
                ],
                'runtime_update_workspace' => [
                    'path' => $workspaceDir,
                    'writable' => self::ensureWritableDirectory($workspaceDir),
                ],
                'version_state' => [
                    'path' => $versionDir,
                    'writable' => self::ensureWritableDirectory($versionDir),
                ],
                'target' => [
                    'path' => $targetDir,
                    'writable' => @is_dir($targetDir) && @is_writable($targetDir),
                ],
            ],
            'open_basedir' => ini_get('open_basedir') ?: '',
            'disabled_functions' => array_values($disabled),
            'disk_free' => $diskFree,
        ];
    }

    public function preflight(string $packagePath, string $targetDir = '', int $packageSize = 0): array
    {
        $env = self::environment($targetDir);
        $errors = [];
        if (!is_file($packagePath)) {
            $errors[] = '更新包文件不存在';
        }
        foreach ($env['paths'] as $item) {
            if (!$item['writable']) {
                $errors[] = '目录不可写: ' . $item['path'];
            }
        }
        $size = $packageSize > 0 ? $packageSize : (is_file($packagePath) ? (int)filesize($packagePath) : 0);
        if ($size > 0 && $env['disk_free'] > 0 && $env['disk_free'] < $size * 3) {
            $errors[] = '磁盘剩余空间不足，至少需要更新包体积的3倍';
        }
        foreach ([$env['paths']['runtime_update_temp']['path'], $env['paths']['runtime_update_workspace']['path'], $env['paths']['version_state']['path'], $targetDir ?: root_path()] as $dir) {
            if (!self::pathAllowedByOpenBasedir($dir, (string)$env['open_basedir'])) {
                $errors[] = 'open_basedir 限制了更新目录: ' . $dir;
            }
        }
        if (!$env['zip_archive'] && !$env['command']['unzip'] && !$env['command']['7z'] && !$env['phar_data'] && !$env['command']['tar']) {
            $errors[] = '没有可用解压能力，请开启 PHP zip/PharData 或安装 unzip/7z/tar';
        }

        return [
            'passed' => empty($errors),
            'errors' => $errors,
            'environment' => $env,
        ];
    }

    public function extract(string $packagePath, string $format = 'zip'): array
    {
        $this->fallbackTried = [];
        $this->driverErrors = [];
        if (!is_file($packagePath)) {
            throw new RuntimeException('更新包文件不存在');
        }
        $dest = runtime_path() . 'update_temp/' . date('YmdHis') . '_' . bin2hex(random_bytes(4));
        self::ensureWritableDirectory($dest);
        $format = strtolower($format ?: pathinfo($packagePath, PATHINFO_EXTENSION));
        try {
            if ($format === 'zip') {
                $this->extractZip($packagePath, $dest);
            } elseif (in_array($format, ['tar.gz', 'tgz', 'tar'], true)) {
                $this->extractTar($packagePath, $dest);
            } else {
                throw new RuntimeException('不支持的更新包格式: ' . $format);
            }
            $this->assertSafeTree($dest);
            return [
                'extract_status' => 'success',
                'path' => $dest,
                'driver' => end($this->fallbackTried) ?: '',
                'fallback_tried' => $this->fallbackTried,
                'driver_errors' => $this->driverErrors,
            ];
        } catch (Throwable $e) {
            return [
                'extract_status' => 'failed',
                'path' => $dest,
                'driver' => end($this->fallbackTried) ?: '',
                'fallback_tried' => $this->fallbackTried,
                'driver_errors' => $this->driverErrors,
                'error' => $e->getMessage(),
                'suggestion' => '请开启 PHP zip 扩展，或安装 unzip/7z，或使用 tar.gz 备用包',
            ];
        }
    }

    public function verifySignatureManifest(string $extractDir): array
    {
        $signaturePath = $extractDir . '/signature.json';
        if (!is_file($signaturePath)) {
            throw new RuntimeException('更新包缺少 signature.json');
        }
        $signature = json_decode((string)file_get_contents($signaturePath), true);
        if (!is_array($signature)) {
            throw new RuntimeException('signature.json 格式错误');
        }
        foreach (($signature['sha256'] ?? []) as $relative => $hash) {
            $path = $extractDir . '/' . ltrim((string)$relative, '/');
            $this->assertSafeRelativePath((string)$relative);
            if (!is_file($path)) {
                throw new RuntimeException('签名文件声明的文件不存在: ' . $relative);
            }
            if (hash_file('sha256', $path) !== $hash) {
                throw new RuntimeException('文件校验失败: ' . $relative);
            }
        }
        return $signature;
    }

    public static function assertRequiredFiles(string $extractDir, array $files): void
    {
        foreach ($files as $file) {
            if (!file_exists($extractDir . '/' . $file)) {
                throw new RuntimeException('更新包缺少必要文件: ' . $file);
            }
        }
    }

    private function extractZip(string $packagePath, string $dest): void
    {
        if (class_exists(ZipArchive::class)) {
            $this->fallbackTried[] = 'ZipArchive';
            $zip = new ZipArchive();
            if ($zip->open($packagePath) === true) {
                $this->assertSafeZipEntries($zip);
                if ($zip->extractTo($dest)) {
                    $zip->close();
                    return;
                }
                $zip->close();
            }
        }
        if (self::commandAvailable('unzip -v')) {
            $this->fallbackTried[] = 'unzip';
            try {
                $this->assertSafeCommandEntries($this->listZipEntriesByUnzip($packagePath));
                $this->runCommand('unzip -oq ' . escapeshellarg($packagePath) . ' -d ' . escapeshellarg($dest));
                return;
            } catch (Throwable $e) {
                $this->handleDriverFailure('unzip', $e);
            }
        }
        if (self::commandAvailable('7z')) {
            $this->fallbackTried[] = '7z';
            try {
                $this->assertSafeCommandEntries($this->listEntriesBy7z($packagePath));
                $this->runCommand('7z x -y ' . escapeshellarg($packagePath) . ' -o' . escapeshellarg($dest));
                return;
            } catch (Throwable $e) {
                $this->handleDriverFailure('7z', $e);
            }
        }
        throw new RuntimeException('zip 解压能力不可用');
    }

    private function extractTar(string $packagePath, string $dest): void
    {
        if (class_exists(PharData::class)) {
            $this->fallbackTried[] = 'PharData';
            try {
                $archive = new PharData($packagePath);
                $archive->extractTo($dest, null, true);
                return;
            } catch (Throwable $e) {
                if (!self::commandAvailable('tar --version')) {
                    throw $e;
                }
            }
        }
        if (self::commandAvailable('tar --version')) {
            $this->fallbackTried[] = 'tar';
            try {
                $this->assertSafeCommandEntries($this->listTarEntries($packagePath));
                $this->runCommand('tar -xf ' . escapeshellarg($packagePath) . ' -C ' . escapeshellarg($dest));
                return;
            } catch (Throwable $e) {
                $this->handleDriverFailure('tar', $e);
            }
        }
        throw new RuntimeException('tar.gz 解压能力不可用');
    }

    private function assertSafeZipEntries(ZipArchive $zip): void
    {
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            $name = (string)($stat['name'] ?? '');
            $this->assertSafeRelativePath($name);
        }
    }

    private function assertSafeCommandEntries(array $entries): void
    {
        foreach ($entries as $name) {
            $name = trim($name);
            if ($name === '') {
                continue;
            }
            $this->assertSafeRelativePath($name);
        }
    }

    private function handleDriverFailure(string $driver, Throwable $e): void
    {
        if (str_contains($e->getMessage(), '更新包包含非法路径')) {
            throw $e;
        }
        $this->driverErrors[$driver] = $e->getMessage();
    }

    private function listZipEntriesByUnzip(string $packagePath): array
    {
        $this->runCommandWithOutput('unzip -Z1 ' . escapeshellarg($packagePath), $output);
        return $output;
    }

    private function listEntriesBy7z(string $packagePath): array
    {
        $this->runCommandWithOutput('7z l -slt ' . escapeshellarg($packagePath), $output);
        $entries = [];
        foreach ($output as $line) {
            if (str_starts_with($line, 'Path = ')) {
                $path = trim(substr($line, 7));
                if ($path !== $packagePath) {
                    $entries[] = $path;
                }
            }
        }
        return $entries;
    }

    private function listTarEntries(string $packagePath): array
    {
        $this->runCommandWithOutput('tar -tf ' . escapeshellarg($packagePath), $output);
        return $output;
    }

    private function assertSafeTree(string $dir): void
    {
        $base = realpath($dir);
        if (!$base) {
            throw new RuntimeException('解压目录不存在');
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($iterator as $file) {
            $path = $file->getPathname();
            $relative = substr($path, strlen($dir) + 1);
            $this->assertSafeRelativePath($relative);
            if (is_link($path)) {
                throw new RuntimeException('更新包不允许包含符号链接: ' . $relative);
            }
            $real = realpath($path);
            if ($real && !str_starts_with($real, $base)) {
                throw new RuntimeException('更新包文件路径越界: ' . $relative);
            }
        }
    }

    private function assertSafeRelativePath(string $path): void
    {
        $path = str_replace('\\', '/', $path);
        if ($path === '' || str_starts_with($path, '/') || preg_match('/^[a-zA-Z]:\//', $path) || str_contains('/' . $path . '/', '/../')) {
            throw new RuntimeException('更新包包含非法路径: ' . $path);
        }
    }

    private static function commandAvailable(string $command): bool
    {
        if (!self::functionEnabled('exec')) {
            return false;
        }
        @exec($command . ' 2>&1', $output, $code);
        return $code === 0 || !empty($output);
    }

    private function runCommand(string $command): void
    {
        $this->runCommandWithOutput($command, $output);
    }

    private function runCommandWithOutput(string $command, ?array &$output = null): void
    {
        $output = [];
        @exec($command . ' 2>&1', $output, $code);
        if ($code !== 0) {
            throw new RuntimeException(implode("\n", $output) ?: '命令执行失败');
        }
    }

    private static function functionEnabled(string $function): bool
    {
        $disabled = array_filter(array_map('trim', explode(',', (string)ini_get('disable_functions'))));
        return function_exists($function) && !in_array($function, $disabled, true);
    }

    private static function pathAllowedByOpenBasedir(string $path, string $openBasedir): bool
    {
        if ($openBasedir === '') {
            return true;
        }
        $real = @realpath($path) ?: $path;
        foreach (explode(PATH_SEPARATOR, $openBasedir) as $allowed) {
            $allowed = trim($allowed);
            if ($allowed === '') {
                continue;
            }
            $allowedReal = @realpath($allowed) ?: $allowed;
            if (str_starts_with($real, rtrim($allowedReal, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR) || $real === $allowedReal) {
                return true;
            }
        }
        return false;
    }

    private static function ensureWritableDirectory(string $dir): bool
    {
        if (!@is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }
        return @is_dir($dir) && @is_writable($dir);
    }

    public static function versionDir(): string
    {
        return rtrim(root_path(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'upgrade';
    }
}
