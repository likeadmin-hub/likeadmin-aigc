#!/usr/bin/env python3
"""Creates a commit-pinned, loopback-only OEM site and independent database. No production resources."""
import json,pathlib,subprocess,uuid,os,shutil,sys
source=pathlib.Path(__file__).resolve().parents[1]; web_source=source.parent/'web'; key=uuid.uuid4().hex[:8]
base=source/'runtime/oem-acceptance'/('20261014-'+key); base.mkdir(parents=True)
database='oem_e2e_'+key; container='likeadmin-oem-e2e-'+key; port=int(os.environ.get('OEM_ACCEPTANCE_PORT','19871'))
def run(args,**kwargs):
    return subprocess.run(args,check=True,**kwargs)
def git(repo,*args):
    return subprocess.check_output(['git','-C',str(repo),*args],text=True).strip()
for repo in [source,web_source]:
    run(['git','-C',str(repo),'merge-base','--is-ancestor','feature/oem-self-service-v2','develop'])
server_sha=git(source,'rev-parse','feature/oem-self-service-v2'); web_sha=git(web_source,'rev-parse','feature/oem-self-service-v2')
server=base/'server'; web=base/'web'
run(['git','clone','--shared','--branch','feature/oem-self-service-v2',str(source),str(server)],stdout=subprocess.DEVNULL)
run(['git','clone','--shared','--branch','feature/oem-self-service-v2',str(web_source),str(web)],stdout=subprocess.DEVNULL)
for app in ['pc','tenant','platform']:
    (web/app/'node_modules').symlink_to(web_source/app/'node_modules',target_is_directory=True)
(web/'node_modules').symlink_to(web_source/'pc/node_modules',target_is_directory=True)
(server/'runtime').mkdir(exist_ok=True); (server/'config/install.lock').write_text('OEM disposable acceptance\n')
run(['docker','exec','likeadmin-oem-db','mysql','-uroot','-e','CREATE DATABASE '+database+' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'])
with (server/'public/install/db/like.sql').open('rb') as install:
    run(['docker','exec','-i','likeadmin-oem-db','mysql','-uroot','--default-character-set=utf8mb4',database],stdin=install,stdout=subprocess.DEVNULL)
env=['-e','OEM_DISPOSABLE=1','-e','PHP_ENV_NAME=oem-disposable','-e','PHP_DATABASE_HOSTNAME=oem-mysql','-e','PHP_DATABASE_DATABASE='+database,'-e','PHP_DATABASE_USERNAME=root','-e','PHP_DATABASE_PASSWORD=','-e','PHP_CACHE_DRIVER=file','-e','PHP_PROJECT_HTTP_HOST=127.0.0.1','-e','PHP_APP_DEBUG=0']
run(['docker','run','--rm','--network','likeadmin-oem-dev','-v',str(server)+':/repo','-w','/repo',*env,'likeadmin-install-worker-test:local','php','tests/Support/OemAcceptance/seed.php'])
run(['docker','run','-d','--name',container,'--network','likeadmin-oem-dev','-p','127.0.0.1:'+str(port)+':8080','-v',str(server)+':/repo','-w','/repo',*env,'likeadmin-install-worker-test:local','php','-S','0.0.0.0:8080','-t','public','tests/Support/OemAcceptance/router.php'],stdout=subprocess.DEVNULL)
info={'base':str(base),'server':str(server),'web':str(web),'database':database,'container':container,'port':port,'url':'http://127.0.0.1:'+str(port),'server_sha':server_sha,'web_sha':web_sha}
(base/'context.json').write_text(json.dumps(info,indent=2)); print(json.dumps(info,indent=2))
