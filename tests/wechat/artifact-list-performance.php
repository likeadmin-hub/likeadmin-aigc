<?php
declare(strict_types=1);
namespace tests\wechat\artifactPerformance {
    final class Records {
        public function order(string $order): self { return $this; }
        public function select(): self { return $this; }
        public function toArray(): array { return \app\common\model\wechat\WechatArtifact::$rows; }
    }
    final class Reads { public static array $files=[]; }
}
namespace app\common\model\wechat {
    final class WechatArtifact {
        public static array $rows=[];
        public static function withoutGlobalScope(): \tests\wechat\artifactPerformance\Records { return new \tests\wechat\artifactPerformance\Records(); }
    }
}
namespace app\common\service\wechat {
    function hash_file(string $algorithm,string $filename): string|false {
        \tests\wechat\artifactPerformance\Reads::$files[$filename]=(\tests\wechat\artifactPerformance\Reads::$files[$filename]??0)+1;
        return \hash_file($algorithm,$filename);
    }
}
namespace {
    require dirname(__DIR__,2).'/vendor/autoload.php';
    use app\common\service\wechat\OpenPlatformService as Service;
    use app\common\model\wechat\WechatArtifact as Registry;
    use tests\wechat\artifactPerformance\Reads;
    $fixtureRoot=sys_get_temp_dir().'/wechat-artifact-perf-'.bin2hex(random_bytes(6)).'/';
    function root_path(): string { return $GLOBALS['fixtureRoot']; }
    function check(bool $ok,string $message): void { if(!$ok)throw new RuntimeException($message); }
    function artifact(string $dir,string $version): void {
        $root=root_path().'public/'.$dir;mkdir($root,0777,true);
        file_put_contents($root.'/app.json','{"pages":[]}');file_put_contents($root.'/project.config.json','{"appid":"fixture"}');
        $files=['app.json'=>hash_file('sha256',$root.'/app.json'),'project.config.json'=>hash_file('sha256',$root.'/project.config.json')];
        file_put_contents($root.'/.artifact.meta.json',json_encode(['version'=>$version,'source_sha'=>'fixture-sha','file_count'=>2,'files'=>$files,'sha256'=>hash('sha256',json_encode($files,JSON_UNESCAPED_SLASHES)),'built_at'=>'2026-11-09T00:00:00Z']));
    }
    try {
        artifact('mp-weixin','1.0.500');artifact('mp-weixin.pre-release-1.0.449','1.0.449');
        for($n=450;$n<=500;$n++)Registry::$rows[]=['id'=>$n,'version'=>'1.0.'.$n,'artifact_dir'=>'mp-weixin','promoted'=>1];
        Reads::$files=[];$rows=Service::artifacts();
        check(count($rows)===52,'Listing lost historical or discovered versions');
        check(max(Reads::$files)===1,'Each physical artifact file must be hashed only once per listing');
        $byVersion=array_column($rows,null,'version');
        check($byVersion['1.0.500']['verify_status']===1&&$byVersion['1.0.500']['promoted']===1,'Formal artifact must remain verified');
        check($byVersion['1.0.450']['verify_status']===0&&$byVersion['1.0.450']['verify_message']==='版本元数据不匹配','Historical metadata mismatch must remain rejected');
        check($byVersion['1.0.449']['verify_status']===1,'Unregistered versioned directory must still be discovered and checked');
        check($byVersion['1.0.499']['verify_status']===0,'Registered row must not silently change to an unrelated directory');
        check(json_decode($byVersion['1.0.500']['sha256_manifest'],true)['app.json']===hash_file('sha256',root_path().'public/mp-weixin/app.json'),'Full checksum detail must remain available');
        $summary=Service::artifacts(false);
        check(count($summary)===52&&!array_key_exists('sha256_manifest',$summary[0]),'Summary requests must omit bulk file manifests');
        $detail=Service::artifacts(true,'1.0.500');
        check(count($detail)===1&&$detail[0]['version']==='1.0.500'&&isset($detail[0]['sha256_manifest']),'Details must return only the selected version and its full manifest');
        try{Service::artifacts(true,'../mp-weixin');throw new LogicException('Invalid version was accepted');}catch(InvalidArgumentException $e){}
        file_put_contents(root_path().'public/mp-weixin/app.json','{"pages":[1]}');
        Reads::$files=[];$changed=array_column(Service::artifacts(),null,'version');
        check($changed['1.0.500']['verify_status']===0,'A later listing must detect modified files without stale cross-request cache');
        unlink(root_path().'public/mp-weixin/.artifact.meta.json');
        $missing=array_column(Service::artifacts(),null,'version');
        check($missing['1.0.500']['verify_message']==='缺少产物元数据','Missing metadata must not be fabricated or repaired');
        echo "PASS artifact list hashes each directory once, retains historical errors and full manifests, and detects subsequent corruption\n";
    } finally {
        if(is_dir($fixtureRoot)) {
            $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($fixtureRoot,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
            foreach($it as $entry)$entry->isDir()?rmdir($entry->getPathname()):unlink($entry->getPathname());rmdir($fixtureRoot);
        }
    }
}
