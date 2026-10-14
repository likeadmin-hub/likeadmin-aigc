#!/usr/bin/env python3
import pathlib,subprocess,sys,json,uuid,tarfile,io
root=pathlib.Path(__file__).resolve().parents[1];info=json.loads(pathlib.Path(sys.argv[1]).read_text());db='oem_upgrade_'+uuid.uuid4().hex[:8];site=pathlib.Path(info['base'])/('upgrade-site-'+db.removeprefix('oem_upgrade_'));site.mkdir()
with tarfile.open(fileobj=io.BytesIO(subprocess.check_output(['git','-C',str(root),'archive','364c5c42']))) as archive:
 assert all(not m.issym() and not m.islnk() and not m.name.startswith('/') and '..' not in pathlib.PurePosixPath(m.name).parts for m in archive.getmembers());archive.extractall(site)
(site/'config/install.lock').write_text('isolated upgrade');(site/'runtime').mkdir(exist_ok=True)
def run(args,**kw):return subprocess.run(args,check=True,**kw)
try:
 run(['docker','exec','likeadmin-oem-db','mysql','-uroot','-e','CREATE DATABASE '+db+' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'])
 with (site/'public/install/db/like.sql').open('rb') as f:run(['docker','exec','-i','likeadmin-oem-db','mysql','-uroot',db],stdin=f,stdout=subprocess.DEVNULL)
 env=['-e','OEM_DISPOSABLE=1','-e','PHP_DATABASE_HOSTNAME=oem-mysql','-e','PHP_DATABASE_DATABASE='+db,'-e','PHP_DATABASE_USERNAME=root','-e','PHP_DATABASE_PASSWORD=','-e','PHP_CACHE_DRIVER=file']
 for phase in ['apply','observe']:
  run(['docker','run','--rm','--network','likeadmin-oem-dev','-v',str(site)+':/site','-v',str(root)+':/repo:ro','-v',str(pathlib.Path(info.get('artifact_dir',str(pathlib.Path(info['base'])/'artifacts')))/'system_1.0.398.zip')+':/oem.zip:ro','-w','/site',*env,'likeadmin-install-worker-test:local','php','/repo/tests/oem-upgrade.integration.php',phase])
finally:run(['docker','exec','likeadmin-oem-db','mysql','-uroot','-e','DROP DATABASE '+db])
