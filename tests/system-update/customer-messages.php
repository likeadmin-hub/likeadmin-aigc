<?php
// The real HTTP boundary must not expose internal paths, process names or driver errors.
namespace think\response { class Json { public array $data; public function __construct(array $data) { $this->data=$data; } } }
namespace think\facade { class Log { public static array $errors=[]; public static function error($message): void { self::$errors[]=$message; } } }
namespace app\platformapi\controller {
    class BaseAdminController {
        public $request;
        protected function fail($message='', $data=[], $code=0, $show=1): \think\response\Json { return new \think\response\Json(['msg'=>$message, 'data'=>$data]); }
        protected function success($message='', $data=[], $code=1, $show=0): \think\response\Json { return new \think\response\Json(['msg'=>$message, 'data'=>$data]); }
    }
}
namespace app\common\service\update {
    // Replace only the DB-backed operation; exercise the real HTTP presentation.
    class SystemPackageUpdateService {
        public static array $result=[];
        public function preflight(int $id): array { if($id!==42)throw new \RuntimeException('wrong package'); return self::$result; }
        public function overview(): array { return self::$result; }
    }
}
namespace {
    $root=dirname(__DIR__,2);
    require $root.'/app/common/service/update/UpdateProtocolException.php';
    spl_autoload_register(static function($class) use ($root) {
        if(str_starts_with($class,'app\\')) { $file=$root.'/'.str_replace('\\','/',$class).'.php'; if(is_file($file))require $file; }
    });
    require $root.'/app/platformapi/controller/upgrade/UpgradeController.php';
    function check(bool $condition,string $message): void { if(!$condition)throw new \RuntimeException($message); }
    $controller=new \app\platformapi\controller\upgrade\UpgradeController();
    $failure=new \ReflectionMethod($controller,'updateFailure');
    $failure->setAccessible(true);
    $raw='本项目启动脚本仍运行，请停止本项目 Supervisor 和 cron 后重试；本次更新涉及: 任务运行文件: runtime-loader.php；任务运行文件: app/common/service/update/RuntimeRetentionService.php';
    $error=new \RuntimeException($raw);
    $response=$failure->invoke($controller,$error)->data;
    check(str_contains($response['msg'],'进程守护') && str_contains($response['msg'],'计划任务'), 'HTTP failure must explain how to stop the project processes in Chinese');
    check(!str_contains($response['msg'],'Supervisor') && !str_contains($response['msg'],'runtime-loader.php'), 'HTTP failure must not display internal process names or file paths');
    check($error->getMessage()===$raw, 'presentation must preserve the original diagnostic exception');
    check(str_contains(implode("\n",\think\facade\Log::$errors),$raw), 'the complete diagnostic must remain in the server log');
    check($response['data']['error_code']==='SYSTEM_UPDATE_FAILED', 'presentation must preserve the API error code');
    $cases=[
        ['本项目任务或 cron 进程仍运行，请等待当前任务结束并停止本项目 Supervisor 和 cron 后重试；本次更新涉及: 数据库迁移: sql/structure/task.sql', '当前任务完成'],
        ['/proc 隐藏进程，无法核验本项目任务进程停机', '联系服务器管理员'],
        ['进程读取权限不足，无法确认本项目任务进程已排空', '联系服务器管理员'],
        ['无法解析任务进程的工作目录，升级已阻断', '联系服务器管理员'],
        ['目录不可写: /www/wwwroot/opc/runtime/update_temp', '写入权限'],
        ['open_basedir 限制了更新目录: /www/wwwroot/opc', '目录访问权限'],
        ['没有可用解压能力，请开启 PHP zip/PharData 或安装 unzip/7z/tar', '解压功能'],
        ['文件校验失败: app/common/service/update/RuntimeRetentionService.php', '重新下载'],
        ['SQLSTATE[HY000]: General error: 2006 MySQL server has gone away', '数据库连接'],
        ['ComposerRuntimeUpdateService: candidate probe failed /www/wwwroot/opc/runtime-loader.php', '联系技术支持'],
    ];
    foreach($cases as [$diagnostic,$action]) {
        $result=$failure->invoke($controller,new \RuntimeException($diagnostic))->data;
        check(str_contains($result['msg'],$action), 'customer action missing for '.$diagnostic);
        check(!preg_match('#/|Supervisor|cron|SQLSTATE|Composer|RuntimeRetention|PharData#',$result['msg']), 'technical detail leaked for '.$diagnostic);
        check(str_contains(implode("\n",\think\facade\Log::$errors),$diagnostic), 'diagnostic lost for '.$diagnostic);
    }
    $result=$failure->invoke($controller,new \app\common\service\update\UpdateProtocolException('SYSTEM_DOWNLOAD_REAUTHORIZE','下载凭证失效，需要重新获取'))->data;
    check($result['data']['error_code']==='SYSTEM_DOWNLOAD_REAUTHORIZE' && !str_contains($result['msg'],'SYSTEM_') && str_contains($result['msg'],'重新下载'), 'protocol codes must remain machine-readable without appearing in the customer message');
    $result=$failure->invoke($controller,new \RuntimeException('请先确认已完成站点文件和数据库备份'))->data;
    check($result['msg']==='请先确认已完成站点文件和数据库备份', 'already understandable instructions must remain specific');

    $rawResult=['passed'=>false,'errors'=>['目录不可写: /www/wwwroot/opc/runtime/update_temp'], 'extract'=>['error'=>'unzip: permission denied'], 'process_impact'=>['requires_stop'=>true,'reasons'=>['任务运行文件: runtime-loader.php','任务运行文件: app/common/command/Crontab.php','数据库迁移: sql/structure/task.sql','任务依赖: vendor/autoload.php']], 'manifest'=>['version'=>'1.0.397']];
    \app\common\service\update\SystemPackageUpdateService::$result=$rawResult;
    $controller->request=new class { public function post($key,$default=null) { return $key==='package_id'?42:$default; } };
    $result=$controller->preflightPackage()->data['data'];
    check($result['passed']===false && $result['process_impact']['requires_stop']===true && $result['manifest']===$rawResult['manifest'], 'presentation must preserve preflight safety and package identity');
    check(str_contains($result['errors'][0],'写入权限') && !str_contains(json_encode($result['process_impact'],JSON_UNESCAPED_UNICODE),'.php'), 'preflight error and impact must be understandable without source paths');
    check(count($result['process_impact']['reasons'])===3, 'repeated file details must become distinct customer impact categories');
    check(\app\common\service\update\SystemPackageUpdateService::$result===$rawResult, 'stored diagnostics must not be replaced by presentation data');
    check(!str_contains($result['extract']['error'],'unzip'), 'nested extraction error must use the same presentation policy');
    \app\common\service\update\SystemPackageUpdateService::$result=['current_version'=>'1.0.396','error'=>'更新源连接失败，请稍后重试 (LICENSE_NETWORK_UNAVAILABLE)','error_code'=>'LICENSE_NETWORK_UNAVAILABLE'];
    $result=$controller->overview()->data['data'];
    check($result['current_version']==='1.0.396' && $result['error_code']==='LICENSE_NETWORK_UNAVAILABLE' && !str_contains($result['error'],'LICENSE_'), 'overview must not bypass customer error formatting');
    echo "customer update messages: HTTP errors, preflight and overview passed\n";
}
