#!/usr/bin/env python3
"""Provision fault injection runs in its own disposable database (DDL triggers cannot roll back)."""
import pathlib, subprocess, uuid
root=pathlib.Path(__file__).resolve().parents[1]
database='oem_fault_'+uuid.uuid4().hex[:10]
def run(*args,data=None):
    result=subprocess.run(args,input=data,text=True,capture_output=True)
    if result.returncode: raise RuntimeError(result.stderr or result.stdout)
    return result.stdout
try:
    run('docker','exec','likeadmin-oem-db','mysql','-uroot','-e','CREATE DATABASE '+database)
    run('docker','exec','-i','likeadmin-oem-db','mysql','-uroot',database,data=(root/'public/install/db/like.sql').read_text())
    args=['docker','run','--rm','--network','likeadmin-oem-dev','-v',str(root)+':/repo:ro','--tmpfs','/repo/runtime','--tmpfs','/repo/public/uploads','--tmpfs','/repo/.composer-runtime','-w','/repo',
        '-e','OEM_DISPOSABLE=1','-e','PHP_DATABASE_HOSTNAME=oem-mysql','-e','PHP_DATABASE_DATABASE='+database,'-e','PHP_DATABASE_USERNAME=root','-e','PHP_DATABASE_PASSWORD=',
        '-e','PHP_CACHE_DRIVER=file','-e','PHP_ENV_NAME=oem-disposable','likeadmin-install-worker-test:local','php']
    print(run(*args,'tests/oem-recovery.integration.php'))
    print(run(*args,'tests/oem-worker.integration.php'))
finally:
    subprocess.run(['docker','exec','likeadmin-oem-db','mysql','-uroot','-e','DROP DATABASE IF EXISTS '+database],capture_output=True)
