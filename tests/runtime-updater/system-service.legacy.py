#!/usr/bin/env python3
"""Genuine 390 old service -> single signed 391 archive, with private MySQL and observer processes."""
import argparse, hashlib, pathlib, subprocess, time, uuid
root=pathlib.Path(__file__).resolve().parents[2]
p=argparse.ArgumentParser();p.add_argument('--archive',type=pathlib.Path,required=True);p.add_argument('--baseline',type=pathlib.Path,default=root.parent/'fixtures/legacy-390/server');p.add_argument('--app-packages',action='store_true');p.add_argument('--cases',nargs='+',choices=['success','php80','dependency-failure','metadata-failure','cached-hook','committed-reply-failure','paused-complete-kill'],default=['success','php80','dependency-failure','metadata-failure','cached-hook','committed-reply-failure','paused-complete-kill']);args=p.parse_args()
assert args.archive.is_file() and args.baseline.is_dir()
assert hashlib.sha256((args.baseline/'app/common/service/update/SystemPackageUpdateService.php').read_bytes()).hexdigest()=='ab776660f25e9cfaf8a741d25e5accab3fb6e9df14a19c300b3f30993e45250f', 'Baseline updater must be genuine 390 source from Git 2666cce'
print('Genuine390 service SHA256 verified; official archive SHA256='+hashlib.sha256(args.archive.read_bytes()).hexdigest(),flush=True)
name='aigc-legacy-fixture-'+uuid.uuid4().hex[:10];network=name+'-network';volumes=[];containers=[]
def run(*cmd,data=None,quiet=False):
    r=subprocess.run(cmd,input=data,text=True,capture_output=True)
    if r.stdout and not quiet: print(r.stdout,end='' if r.stdout.endswith('\n') else '\n',flush=True)
    if r.returncode: raise RuntimeError(r.stderr.strip() or 'Command failed: '+' '.join(cmd))
    return r.stdout
def sql(db,content):return run('docker','exec','-i',name,'mysql','-h127.0.0.1','-uroot','-N',db,data=content,quiet=True)
try:
    run('docker','network','create',network);run('docker','run','-d','--name',name,'--network',network,'--network-alias','mysql','-e','MYSQL_ALLOW_EMPTY_PASSWORD=yes','mysql:8.0','--character-set-server=utf8mb4','--collation-server=utf8mb4_unicode_ci')
    for _ in range(60):
        if subprocess.run(['docker','exec',name,'mysqladmin','-h127.0.0.1','ping','--silent'],capture_output=True).returncode==0:break
        time.sleep(1)
    else:raise RuntimeError('MySQL startup timeout')
    for case in args.cases:
        db='fixture_'+case.replace('-','_');sql('mysql','CREATE DATABASE '+db+';');sql(db,(args.baseline/'public/install/db/like.sql').read_text())
        volume=name+'-'+case;volumes.append(volume);run('docker','volume','create',volume)
        container=name+'-php-'+case;containers.append(container)
        mounts=['--network',network,'--hostname','system-service-fixture','-v',str(root)+':/repo:ro','-v',str(args.baseline.resolve())+':/baseline:ro','-v',str(args.archive.resolve())+':/official.zip:ro','-v',volume+':/fixture','-w','/repo','-e','SYSTEM_SERVICE_DISPOSABLE=1','-e','PHP_DATABASE_DATABASE='+db,'-e','PHP_CACHE_DRIVER=file']
        run('docker','run','-d','--name',container,*mounts,'likeadmin-install-worker-test:local','sleep','infinity')
        def phase(mode,extra=None):
            command=['docker','exec',container,'php','tests/runtime-updater/system-service.legacy.php',mode,case]
            if extra:command.append(extra)
            return run(*command)
        assert 'PASS prepare' in phase('prepare')
        if case=='php80':run('docker','run','--rm',*mounts,'likeadmin-system-updater-php80-test:local','php','tests/runtime-updater/system-service.legacy.php','apply',case)
        elif case=='paused-complete-kill':
            keeper_code="$p=new PDO('mysql:host=mysql;dbname='.getenv('PHP_DATABASE_DATABASE'),'root','');$p->query(\"SELECT GET_LOCK('fixture_complete_pause',30)\");echo 'LOCK_HELD'.PHP_EOL;fflush(STDOUT);fgets(STDIN);$p->query(\"SELECT RELEASE_LOCK('fixture_complete_pause')\");"
            keeper=subprocess.Popen(['docker','exec','-i',container,'php','-r',keeper_code],stdin=subprocess.PIPE,stdout=subprocess.PIPE,stderr=subprocess.PIPE,text=True)
            assert keeper.stdout.readline().strip()=='LOCK_HELD'
            apply_process=subprocess.Popen(['docker','exec',container,'php','tests/runtime-updater/system-service.legacy.php','apply',case],stdout=subprocess.PIPE,stderr=subprocess.PIPE,text=True)
            try:
                for _ in range(150):
                    waiting=sql('mysql',"SELECT COUNT(*) FROM information_schema.PROCESSLIST WHERE STATE='User lock' AND DB='"+db+"';").strip()
                    if waiting=='1':break
                    if apply_process.poll() is not None:raise RuntimeError('Apply stopped before actual completion DML pause')
                    time.sleep(1)
                else:raise RuntimeError('Completion trigger did not block the real metadata write')
                migrations_before=sql(db,'SELECT * FROM la_app_migration ORDER BY id;')
                pid_code="$ids=[];foreach(glob('/proc/[0-9]*/cmdline') as $f){$args=explode(chr(0),(string)@file_get_contents($f));$runner=false;foreach($args as $arg)if(strpos($arg,'/fixture/site/runtime/update_temp/')===0 && basename($arg)==='composer-runtime-runner.php')$runner=true;if(in_array('--bootstrap',$args,true) && $runner)$ids[]=basename(dirname($f));}if(count($ids)!==1){fwrite(STDERR,'Expected one private top bootstrap PID, found '.count($ids));exit(1);}echo $ids[0];"
                pid=run('docker','exec',container,'php','-r',pid_code,quiet=True).strip();assert pid.isdigit()
                run('docker','exec',container,'sh','-c','kill -9 "$1"','fixture-kill',pid)
                peek=subprocess.Popen(['docker','exec',container,'php','tests/runtime-updater/system-service.legacy.php','peek',case],stdout=subprocess.PIPE,stderr=subprocess.PIPE,text=True)
                time.sleep(1)
                if peek.poll() is not None:
                    out,err=peek.communicate();assert peek.returncode==0 and 'PASS concurrent fresh site entry' in out,err+out;print(out,end='',flush=True)
                keeper.stdin.write('\n');keeper.stdin.flush();keeper.stdin.close();assert keeper.wait(timeout=15)==0
                out,err=apply_process.communicate(timeout=180);print(out,end='',flush=True);assert apply_process.returncode==0,err
                if peek.poll() is None:
                    out,err=peek.communicate(timeout=60);assert peek.returncode==0 and 'PASS concurrent fresh site entry' in out,err+out;print(out,end='',flush=True)
                # A dead top-level child can legitimately return an uncertain HTTP response while
                # its leased metadata writer lives. Poll actual DB completion, never replay apply.
                for _ in range(120):
                    completed=sql(db,"SELECT COUNT(*) FROM la_update_package p JOIN la_update_task t ON t.package_id=p.id WHERE p.status='applied' AND t.action='apply' AND t.status='success';").strip()
                    if completed=='1':break
                    time.sleep(1)
                else:raise RuntimeError('Surviving metadata writer did not commit package/task success after parent death')
                assert sql(db,'SELECT * FROM la_app_migration ORDER BY id;')==migrations_before,'Parent-death recovery replayed SQL migrations'
                print('PASS surviving completion writer committed independently; SQL migration records unchanged; fresh observer will recover pointer/markers',flush=True)
            finally:
                if keeper.poll() is None:keeper.terminate()
        else:phase('apply')
        if case in ['success','committed-reply-failure','paused-complete-kill']: assert 'PASS fresh observer' in phase('observe')
        else: assert 'PASS failed observer' in phase('observe')
        if case=='metadata-failure':phase('retry');assert 'PASS same package retry' in phase('observe','after-retry')
        if case in ['success','committed-reply-failure','metadata-failure','paused-complete-kill']:
            cli=run('docker','exec',container,'php','/fixture/site/think','list','--raw',quiet=True)
            assert 'short-drama:' in cli and 'Exception' not in cli, 'Actual site CLI did not boot new commands'
            run('docker','exec','-d',container,'php','-S','127.0.0.1:9014','-t','/fixture/site/public','/fixture/site/public/index.php')
            http="for($i=0;$i<50;$i++){ $c=curl_init('http://127.0.0.1:9014/platformapi/config/getConfig');curl_setopt_array($c,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_HTTPHEADER=>['Host: system.fixture']]);$raw=curl_exec($c);$status=curl_getinfo($c,CURLINFO_HTTP_CODE);curl_close($c);if($raw!==false)break;usleep(100000);} $data=json_decode($raw,true);if($status!==200 || !is_array($data) || ($data['code']??null)!==1 || empty($data['data']['is_oem'])){fwrite(STDERR,'Actual site HTTP config failed: '.$raw);exit(1);}echo 'PASS actual site HTTP config entry with OEM brand'.PHP_EOL;"
            run('docker','exec',container,'php','-r',http)
            print('PASS actual site think CLI boots without starting workers',flush=True)
        if case=='success' and args.app_packages:
            result=run('docker','exec',container,'php','tests/runtime-updater/system-service.app-package.php','/fixture/site')
            assert 'PASS actual AppPackage APIs' in result
        print('PASS isolated genuine390 archive case: '+case,flush=True)
        run('docker','rm','-f','-v',container);containers.remove(container);run('docker','volume','rm',volume);volumes.remove(volume)
finally:
    for container in containers:subprocess.run(['docker','rm','-f','-v',container],capture_output=True)
    for volume in volumes:subprocess.run(['docker','volume','rm',volume],capture_output=True)
    subprocess.run(['docker','rm','-f','-v',name],capture_output=True);subprocess.run(['docker','network','rm',network],capture_output=True)
    print('Cleaned private fixture containers, volumes and network; no host ports or shared business database.',flush=True)
