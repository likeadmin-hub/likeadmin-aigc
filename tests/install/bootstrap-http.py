#!/usr/bin/env python3
"""Real PHP 8.0/8.2 HTTP entry and installer checks, no customer DB or files."""
import io, json, pathlib, subprocess, tarfile, time, uuid
root=pathlib.Path(__file__).resolve().parents[2]
name='aigc-install-entry-'+uuid.uuid4().hex[:10]
files=['app/common/service/OemBrandService.php','public/index.php','runtime-loader.php','direct-runtime-loader.php','app/common/service/update/DirectSiteUpdateService.php','public/install/model.php','public/install/install.php','public/install/YxEnv.php','public/install/template/main.php','.example.env','config/project.php']
def run(*args,data=None,check=True):
 r=subprocess.run(args,input=data,capture_output=True)
 if check and r.returncode:raise RuntimeError(r.stderr.decode()+r.stdout.decode())
 return r
# Use the same full tracked dependencies as the downloaded server repository.
# Local mount paths are only test inputs; the fixture owns all mutable state.
run('docker','exec','bt','mkdir','-p','/tmp/'+name+'/site')
site='/tmp/'+name+'/site'
payload=io.BytesIO()
with tarfile.open(fileobj=payload,mode='w') as archive:
 for p in files: archive.add(root/p,arcname=p)
run('docker','exec','-i','bt','tar','-xf','-','-C',site,data=payload.getvalue())
run('docker','exec','bt','ln','-s','/www/wwwroot/likeadmin_aigc_saas/server/vendor',site+'/vendor')
failures=[]
try:
 for version,port in [('80',18880),('82',18882)]:
  php='/www/server/php/'+version+'/bin/php'
  run('docker','exec','bt','sh','-c','mkdir -p "$1/runtime" "$1/config"; chmod -R a+rwX "$1"','sh',site)
  proc=subprocess.Popen(['docker','exec','bt',php,'-S','127.0.0.1:'+str(port),'-t',site+'/public'],stdout=subprocess.PIPE,stderr=subprocess.PIPE)
  def request(path,method='GET',body=''):
   code='$c=stream_context_create(["http"=>["ignore_errors"=>true,"follow_location"=>0,"method"=>$argv[2],"header"=>"Content-Type: application/x-www-form-urlencoded","content"=>$argv[3]]]);$b=file_get_contents($argv[1],false,$c);echo json_encode(["headers"=>$http_response_header,"body"=>$b],JSON_UNESCAPED_UNICODE);'
   return json.loads(run('docker','exec','bt',php,'-r',code,'http://127.0.0.1:'+str(port)+path,method,body).stdout)
  try:
   for attempt in range(30):
    try: fresh=request('/index.php');break
    except Exception: time.sleep(.1)
   else: raise RuntimeError('HTTP server did not start')
   print('PHP '+version+' fresh entry:',json.dumps(fresh,ensure_ascii=False))
   if not any(h.lower()=='location:/install/install.php' or h.lower()=='location: /install/install.php' for h in fresh['headers']): failures.append('PHP '+version+' fresh root must reach installer before loading runtime')
   run('docker','exec','bt','sh','-c','test ! -f "$1/runtime/site-update.lock"','sh',site)
   if version=='82':
    run('docker','exec','-i','bt','sh','-c','cat > "$1/runtime/direct-update-active.json"','sh',site,data=b'{"digest":"invalid"}')
    pending=request('/index.php')
    if any(h.lower().startswith('location:') for h in pending['headers']) or '应用运行时暂不可用' not in pending['body']:failures.append('Unfinished update must not be bypassed by installer redirect')
    run('docker','exec','bt','rm',site+'/runtime/direct-update-active.json')
   check=json.loads(run('docker','exec','bt',php,'-r','require $argv[1];echo json_encode((new installModel())->checkPHP());',site+'/public/install/model.php').stdout)
   if check!=('fail' if version=='80' else 'ok'):failures.append('PHP '+version+' installer environment gate incorrect: '+check)
   if version=='80':
    response=request('/install/install.php?step=2')
    if 'PHP 8.2' not in response['body'] or '建议使用PHP8.0.8' in response['body']:failures.append('Installer must explain actual PHP 8.2 requirement')
    env_before=run('docker','exec','bt',php,'-r','echo is_file($argv[1])?hash_file("sha256",$argv[1]):"absent";',site+'/.env').stdout
    response=request('/install/install.php?step=4','POST','admin_user=test&admin_password=test&admin_confirm_password=test&name=must_not_create')
    if '当前环境不满足安装要求' not in response['body']:failures.append('Direct install submission must reject PHP 8.0 before DB work')
    env_after=run('docker','exec','bt',php,'-r','echo is_file($argv[1])?hash_file("sha256",$argv[1]):"absent";',site+'/.env').stdout
    if env_before!=env_after:failures.append('Unsupported PHP POST changed environment configuration')
    run('docker','exec','bt','sh','-c','test ! -f "$1/config/install.lock"','sh',site)
    run('docker','exec','bt','touch',site+'/config/install.lock')
    installed=request('/index.php')
    if 'PHP 8.2' not in installed['body']:failures.append('Installed PHP 8.0 entry must show actionable version requirement')
    run('docker','exec','bt','rm',site+'/config/install.lock')
  finally:
   run('docker','exec','bt','sh','-c','ps -eo pid,args | awk -v p="$1" \'index($0,p) && !/awk/ {print $1}\' | xargs -r kill','sh',php+' -S 127.0.0.1:'+str(port),check=False)
   proc.communicate(timeout=10)
 if failures:raise AssertionError('\n'.join(failures))
 print('PASS real PHP8.0/PHP8.2 fresh-install redirect, environment rejection, direct POST guard and installed error guidance')
finally:
 run('docker','exec','bt','rm','-rf','/tmp/'+name)
