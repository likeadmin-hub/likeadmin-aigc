const { test } = require('node:test')
const assert = require('node:assert/strict')
const fs = require('node:fs')
const os = require('node:os')
const path = require('node:path')
const { spawnSync } = require('node:child_process')

const root = path.resolve(__dirname, '..')
const service = fs.readFileSync(path.join(root, 'app/common/service/wechat/OpenPlatformService.php'), 'utf8')
const controller = fs.readFileSync(path.join(root, 'app/platformapi/controller/OpenPlatformController.php'), 'utf8')
function php(code) {
  const r = spawnSync('docker', ['exec', '-i', 'bt', '/www/server/php/82/bin/php'], { input: code, encoding: 'utf8', timeout: 10000 })
  assert.equal(r.status, 0, r.stderr || r.stdout)
  return r.stdout
}
function generatedRun(mode) {
  const work = fs.mkdtempSync(path.join(os.tmpdir(), 'wechat-ci-safety-'))
  const marker = work + '.upload-called'
  const modules = path.join(work, 'node_modules/miniprogram-ci')
  fs.mkdirSync(path.join(modules, 'config'), { recursive: true })
  fs.writeFileSync(path.join(modules, 'config/config.js'), 'exports.DefaultProjectAttr = { fallback: true };')
  fs.writeFileSync(path.join(modules, 'index.js'), `
    const fs = require('fs');
    exports.Project = class { async attr() {
      if (process.env.CI_TEST_MODE === 'auth-fail') {
        console.error('get project attr fail errCode: -1, errMsg: access_token missing');
        return require('./config/config').DefaultProjectAttr;
      }
      return { verified: true };
    }};
    exports.upload = async options => {
      fs.writeFileSync(process.env.CI_TEST_MARKER, 'called');
      if (process.env.CI_TEST_MODE === 'success' && options.threads !== 1) throw new Error('unsafe compiler concurrency');
      if (process.env.CI_TEST_MODE === 'upload-fail') {
        setInterval(() => {}, 1000);
        throw Object.assign(new Error('provider rejected upload'), {errCode: 41001});
      }
      return { subPackageInfo: [] };
    };
  `)
  const args = [work, path.join(work, 'project'), path.join(work, 'private.key'), 'wx0000000000000000', '1.0.412', 'safety test']
  const encoded = Buffer.from(JSON.stringify(args)).toString('base64')
  const script = php(service + `\n$m = new \\ReflectionMethod(\\app\\common\\service\\wechat\\OpenPlatformService::class, 'manualUploadScript'); $m->setAccessible(true); echo $m->invokeArgs(null,json_decode(base64_decode('${encoded}'),true));`)
  const scriptPath = path.join(work, 'upload.mjs')
  fs.writeFileSync(scriptPath, script)
  const r = spawnSync(process.execPath, [scriptPath], {
    encoding: 'utf8', timeout: 4000,
    env: { ...process.env, CI_TEST_MODE: mode, CI_TEST_MARKER: marker }
  })
  const called = fs.existsSync(marker)
  const cleaned = !fs.existsSync(work)
  fs.rmSync(work, { recursive: true, force: true }); fs.rmSync(marker, { force: true })
  return { ...r, called, cleaned }
}

test('rejected CI authentication prevents compilation and removes temporary credentials', () => {
  const r = generatedRun('auth-fail')
  assert.equal(r.called, false, 'authentication failure must not start the compiler')
  assert.equal(r.status, 1, r.stderr)
  assert.equal(r.cleaned, true)
  assert.match(r.stderr, /WECHAT_UPLOAD_ERROR:/)
})
test('valid upload uses one compiler worker and emits provider confirmation', () => {
  const r = generatedRun('success')
  assert.equal(r.status, 0, r.stderr)
  assert.equal(r.called, true)
  assert.match(r.stdout, /微信小程序代码上传成功/)
  assert.equal(r.cleaned, true)
})
test('provider rejection exits promptly even when the SDK leaves handles open', () => {
  const r = generatedRun('upload-fail')
  assert.equal(r.status, 1, r.stderr)
  assert.match(r.stderr, /provider rejected upload/)
  assert.equal(r.cleaned, true)
})

function controllerResult(result) {
  const encoded = Buffer.from(JSON.stringify(result)).toString('base64')
  const stubs = `<?php
  namespace app\\common\\service\\wechat { class OpenPlatformService {
    static function runIdempotent($a,$b,$c,$callback){return $callback();}
    static function uploadDraftForArtifact($id,$data){return json_decode(base64_decode('${encoded}'),true);}
  }}
  namespace app\\platformapi\\controller {
    class BaseAdminController { public $request; function __construct(){ $this->request=new FakeRequest(); }
      protected function data($r){return ['ok'=>true,'data'=>$r];}
      protected function fail($m,$d=[]){return ['ok'=>false,'message'=>$m];}
    }
    class FakeRequest { function header($a,$b){return $b;} function post(){return ['artifact_id'=>1];} }
  }
  namespace { eval(base64_decode('${Buffer.from(controller).toString('base64')}')); echo json_encode((new \\app\\platformapi\\controller\\OpenPlatformController())->uploadDraft()); }
  `
  return JSON.parse(php(stubs.replace("eval(base64_decode(", "eval('?>'.base64_decode(")))
}
test('failed upload is returned to the browser as an error', () => {
  const r = controllerResult({upload_status:'failed',error_message:'access_token missing'})
  assert.equal(r.ok, false)
  assert.match(r.message, /access_token missing/)
})
test('confirmed successful draft remains a successful response', () => {
  const r = controllerResult({upload_status:'success',draft_id:0})
  assert.equal(r.ok, true)
  assert.equal(r.data.draft_id, 0)
})

test('accepted upload without a verified WeChat draft is not reported as successful', () => {
  const r = controllerResult({upload_status:'uploaded_unconfirmed',error_message:'refresh draft list'})
  assert.equal(r.ok, false)
  assert.match(r.message, /refresh draft list/)
})

test('exited uploader cannot leave descendant processes holding output pipes open', () => {
  const code = `<?php
    function root_path(){return sys_get_temp_dir().'/wechat-test-nonexistent/';} function config($key,$default=null) { return $key === 'wechat_ci.process_group' ? true : $default; }
    eval('?>'.base64_decode('${Buffer.from(service).toString('base64')}'));
    $dir=sys_get_temp_dir().'/wechat-tree-'.bin2hex(random_bytes(6));
    mkdir($dir);mkdir($dir.'/node_modules');mkdir($dir.'/node_modules/miniprogram-ci');
    file_put_contents($dir.'/node',"#!/bin/sh\\nexec setsid sh -c '(sleep 30)& echo provider-rejected >&2; exit 1'\\n");
    chmod($dir.'/node',0700);
    putenv('WECHAT_UPLOAD_NODE='.$dir.'/node');putenv('NODE_PATH='.$dir.'/node_modules');
    pcntl_async_signals(true);pcntl_signal(SIGALRM,function(){exit(82);});pcntl_alarm(3);
    $m=new ReflectionMethod(app\\common\\service\\wechat\\OpenPlatformService::class,'executeManualUpload');$m->setAccessible(true);
    $result=$m->invoke(null,$dir,$dir.'/unused.mjs','npm');
    pcntl_alarm(0);echo json_encode(['success'=>$result['success'],'output'=>$result['output']]);
    unlink($dir.'/node');rmdir($dir.'/node_modules/miniprogram-ci');rmdir($dir.'/node_modules');rmdir($dir);
  `
  const r = JSON.parse(php(code))
  assert.equal(r.success, false)
  assert.match(r.output, /provider-rejected/)
})
