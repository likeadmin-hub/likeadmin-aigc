#!/usr/bin/env python3
"""Validate signed candidate bytes, three-end assets, migration parity and excluded fixtures."""
import hashlib,json,pathlib,re,sys,zipfile,subprocess
info=json.loads(pathlib.Path(sys.argv[1]).read_text());directory=pathlib.Path(info['artifact_dir']);package=directory/'system_1.0.398.zip';metadata=json.loads((directory/'system_1.0.398.metadata.json').read_text())
def digest(path):
 h=hashlib.sha256()
 with path.open('rb') as f:
  for data in iter(lambda:f.read(1048576),b''):h.update(data)
 return h.hexdigest()
assert digest(package)==metadata['sha256'] and package.stat().st_size==metadata['size']
with zipfile.ZipFile(package) as z:
 names=z.namelist();assert len(names)==len(set(names));files={x for x in names if not x.endswith('/')};sig=json.loads(z.read('signature.json'))['sha256'];assert set(sig)==files-{'signature.json'}
 for name,expected in sig.items():assert hashlib.sha256(z.read(name)).hexdigest()==expected,name
 for name in files:
  assert not name.startswith('/') and '..' not in pathlib.PurePosixPath(name).parts
  assert not re.search(r'^files/(tests|docs|runtime|public/uploads|public/storage|public/qrcode)/|^files/\.env|^files/config/install.lock$|node_modules/|\.DS_Store$',name),name
 manifest=json.loads(z.read('update.json'));assert manifest['version']=='1.0.398' and manifest['base_version']=='1.0.397';assert {'public/pc','public/_nuxt','public/admin','public/platform'}<=set(manifest['full_replace_dirs'])
 for relative in ['app/common/command/BrandWorker.php','app/common/command/BrandMaintain.php','app/common/service/brand/BrandPaymentService.php','app/common/service/brand/BrandRefundService.php','scripts/start-brand-worker.sh','public/install/db/like.sql']:assert 'files/'+relative in files,relative
 for relative in manifest['sql_order']:
  assert z.read(relative)==(pathlib.Path(info['server'])/'upgrade'/pathlib.Path(relative).name).read_bytes(),relative
 assert len([x for x in manifest['sql_order'] if '_oem_' in x])==5
 for app in ['pc','admin','platform']:
  html=z.read('files/public/'+app+'/index.html').decode()
  for resource in re.findall(r'(?:src|href)=["\']([^"\']+\.(?:js|css))(?:\?[^"\']*)?["\']',html):
   if resource.startswith(('http:','https:','data:')):continue
   target='files/public/'+resource.lstrip('/') if resource.startswith('/') else 'files/public/'+app+'/'+resource
   if target not in files:
    # Incremental packages retain unchanged root resources outside full-replace directories.
    relative=target.removeprefix('files/');assert not any(relative==d or relative.startswith(d+'/') for d in manifest['full_replace_dirs']),(app,resource)
    previous=subprocess.check_output(['git','-C',info['server'],'show','364c5c42:'+relative])
    assert previous==(pathlib.Path(info['server'])/relative).read_bytes(),('retained root asset changed',resource)
 pcs={n[len('files/public/pc/_nuxt/'):]:sig[n] for n in sig if n.startswith('files/public/pc/_nuxt/')};roots={n[len('files/public/_nuxt/'):]:sig[n] for n in sig if n.startswith('files/public/_nuxt/')};assert roots and all(roots.get(n)==v for n,v in pcs.items()),'PC Nuxt mirrors differ from canonical root outputs'
print(json.dumps({'canonical_nuxt':'public/_nuxt (PC static builder omits duplicate /pc/_nuxt)', 'result':'PASS','sha256':metadata['sha256'],'files_verified':len(sig),'full_replace_dirs':manifest['full_replace_dirs'],'sql_order':manifest['sql_order'],'no_test_gateway_or_protected_data':True},indent=2))
