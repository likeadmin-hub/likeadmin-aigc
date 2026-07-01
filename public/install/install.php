<?php
// error_reporting(0);
include "model.php";
include "YxEnv.php";

define('install', true);
define('INSTALL_ROOT', __DIR__);
define('TESTING_TABLE', 'config');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$step = isset($_GET['step']) ? (int)$_GET['step'] : 1;
if (!in_array($step, [1, 2, 3, 4, 5], true)) {
    $step = 1;
}

$installDir = "install";
$modelInstall = new installModel();

// Env设置
$yxEnv = new YxEnv();

// 检查是否有安装过
$envFilePath = $modelInstall->getAppRoot() . '/.env';
$appInstalled = $modelInstall->appIsInstalled();
$installCompletedInSession = !empty($_SESSION['install_completed']);
if ($step === 5 && !$appInstalled && !$installCompletedInSession) {
    header('Location: ?step=1');
    exit;
}
if ($appInstalled && in_array($step, [1, 2, 3, 4], true)) {
    die('可能已经安装过本系统了，请删除配置目录下面的install.lock文件再尝试');
}

// 加载Example文件
$yxEnv->load($modelInstall->getAppRoot() . '/.example.env');

$post = [
    'host' => $_POST['host'] ?? '127.0.0.1',
    'port' => $_POST['port'] ?? '3306',
    'user' => $_POST['user'] ?? 'root',
    'password' => $_POST['password'] ?? '',
    'name' => $_POST['name'] ?? 'likeadmin_saas',
    'admin_user' => $_POST['admin_user'] ?? '',
    'admin_password' => $_POST['admin_password'] ?? '',
    'admin_confirm_password' => $_POST['admin_confirm_password'] ?? '',
    'prefix' => $_POST['prefix'] ?? 'la_',
    'import_test_data' => $_POST['import_test_data'] ?? 'off',
    'clear_db' => $_POST['clear_db'] ?? 'off',
];

$message = '';
$projectVersion = '';
$projectConfigPath = $modelInstall->getAppRoot() . '/config/project.php';
if (is_file($projectConfigPath)) {
    $projectConfigContent = file_get_contents($projectConfigPath);
    if (preg_match("/'version'\\s*=>\\s*'([^']+)'/", $projectConfigContent, $matches)) {
        $projectVersion = $matches[1];
    }
}

// 检查数据库正确性
if ($step == 4) {
    $canNext = true;
    if (empty($post['prefix'])) {
        $canNext = false;
        $message = '数据表前缀不能为空';
    } elseif ($post['admin_user'] == '') {
        $canNext = false;
        $message = '请填写管理员用户名';
    } elseif (empty(trim($post['admin_password']))) {
        $canNext = false;
        $message = '管理员密码不能为空';
    } elseif ($post['admin_password'] != $post['admin_confirm_password']) {
        $canNext = false;
        $message = '两次密码不一致';
    } else {
        // 检查 数据库信息
        if ($canNext) {
            $result = $modelInstall->checkConfig($post['name'], $post);
            if ($result->result == 'fail') {
                $canNext = false;
                $message = $result->error;
            }
        }

        // 导入测试数据
        if ($canNext == true && $post['import_test_data'] == 'on') {
            if (!$modelInstall->importDemoData()) {
                $modelInstall->cleanupFailedInstall($post['name']);
                $canNext = false;
                $message = '导入测试数据错误';
            }
        }

        // 写配置文件
        if ($canNext) {
            if (!$yxEnv->putEnv($envFilePath, $post)) {
                $modelInstall->cleanupFailedInstall($post['name']);
                $canNext = false;
                $message = '写入.env配置文件失败，请检查网站根目录.env文件权限';
            } elseif (!$modelInstall->mkLockFile()) {
                $modelInstall->cleanupFailedInstall($post['name']);
                $canNext = false;
                $message = '创建安装锁文件失败，请检查config目录权限';
            }
        }

        // 恢复admin和index入口
        if ($canNext) {
            $modelInstall->restoreIndexLock();
            $_SESSION['install_completed'] = true;
            session_write_close();
        }
    }

    if (!$canNext) {
        unset($_SESSION['install_completed']);
        $step = 3;
    }
}

// 取得安装成功的表
$successTables = $modelInstall->getSuccessTable();

$nextStep = $step + 1;
include __DIR__ . "/template/main.php";
