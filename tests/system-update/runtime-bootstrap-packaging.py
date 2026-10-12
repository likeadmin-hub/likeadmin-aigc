#!/usr/bin/env python3
"""Official single-operation bootstrap archive, nested checksum and boundary regressions."""
import importlib.util, json, pathlib, shutil, subprocess, tempfile, unittest, zipfile
spec=importlib.util.spec_from_file_location('composer_tests',pathlib.Path(__file__).with_name('composer-packaging.py'))
source=importlib.util.module_from_spec(spec);spec.loader.exec_module(source)
module=source.module

class RuntimeBootstrapPackagingTest(unittest.TestCase):
    def setUp(self):
        self.tmp=tempfile.TemporaryDirectory();self.root=pathlib.Path(self.tmp.name);self.repo=self.root/'repo';self.stage=self.root/'stage'
        for directory in ['files','sql/data','sql/structure','menus','rollback']:(self.stage/directory).mkdir(parents=True)
        for code in module.SYSTEM_BRIDGE_APP_CODES:
            for rel in ['manifest.json','api_schema.json','menus/platform.json','menus/tenant.json','permissions/tenant.json']:self.write(self.repo/'server/app/apps'/code/rel,'{}')
            for rel in ['migrations','frontend']:(self.repo/'server/app/apps'/code/rel).mkdir(parents=True)
        self.inner=self.stage/'bootstrap-payload' 
        for directory in ['files','sql/data','sql/structure','menus','rollback']:(self.inner/directory).mkdir(parents=True)
        composer=source.ComposerPackagingTest();composer.setUp()
        shutil.copytree(composer.payload,self.inner/'composer-runtime');composer.tearDown()
        self.inner_manifest={'version':'1.0.390','package_mode':'incremental','base_version':'1.0.390','target_version':'1.0.391','included_versions':['1.0.390','1.0.391'],'full_replace_dirs':[],'delete_files':[],'sql_order':[], 'composer_runtime':{'schema':1,'payload_dir':'composer-runtime','min_updater_version':'1.0.391','min_php':'8.2.0','required_extensions':['json'],'probe_classes':['Example\\Runtime']}}
        self.write(self.inner/'update.json',json.dumps(self.inner_manifest))
        self.write(self.inner/'files/public/mp-weixin/.artifact.meta.json','{"version":"1.0.391"}')
        self.write(self.stage/'files/app/common/service/database/PcWechatMigration.php','<?php // compatible hook fixture')
        self.gates={'sql/structure/000_runtime_bootstrap.sql':'-- likeadmin:migrate-pc-wechat-bindings-v1\n-- likeadmin:system-runtime-bootstrap-v1\nSELECT 1;\n','sql/structure/001_runtime_bootstrap_guard.sql':'CALL la_system_runtime_bootstrap_not_completed_391();\n'}
        for relative,contents in self.gates.items():self.write(self.stage/relative,contents)
        self.manifest={'version':'1.0.390','package_mode':'incremental','base_version':'1.0.390','target_version':'1.0.391','included_versions':['1.0.390','1.0.391'],'full_replace_dirs':[],'delete_files':[],'sql_order':list(self.gates),'runtime_bootstrap':{'schema':1,'base_version':'1.0.390','target_version':'1.0.391','payload_dir':'bootstrap-payload','min_php':'8.2.0'}}
        self.write(self.stage/'update.json',json.dumps(self.manifest))
    def write(self,path,value):path.parent.mkdir(parents=True,exist_ok=True);path.write_text(value)
    def tearDown(self):self.tmp.cleanup()
    def package(self):
        result=subprocess.run(['python3',str(source.tool),'system','--repo',str(self.repo),'--source-dir',str(self.stage),'--out-dir',str(self.root/'output')],capture_output=True,text=True)
        self.assertEqual(result.returncode,0,result.stderr);return self.root/'output/system_1.0.391.zip'
    def test_official_archive_nested_signatures_metadata_and_repository_verifier(self):
        archive=self.package()
        with zipfile.ZipFile(archive) as z:
            names=z.namelist();outer=json.loads(z.read('update.json'));inner=json.loads(z.read('bootstrap-payload/update.json'))
            self.assertEqual(outer['version'],'1.0.391');self.assertEqual(inner['version'],'1.0.391')
            self.assertEqual([p for p in names if p.startswith('files/') and not p.endswith('/')],['files/app/common/service/database/PcWechatMigration.php'])
            self.assertIn('bootstrap-payload/files/public/mp-weixin/.artifact.meta.json',names)
            self.assertIn('bootstrap-payload/signature.json',json.loads(z.read('signature.json'))['sha256'])
            for code in module.SYSTEM_BRIDGE_APP_CODES:self.assertIn('bootstrap-payload/files/app/apps/'+code+'/manifest.json',names)
        verifier=pathlib.Path(__file__).resolve().parents[2]/'scripts/verify-system-update-package.mjs'
        result=subprocess.run(['node',str(verifier),str(archive)],capture_output=True,text=True)
        self.assertEqual(result.returncode,0,result.stderr)
    def test_outer_business_sql_entrypoint_and_changed_fail_gate_rejected(self):
        for mutation in ['sql','entrypoint','guard','dependencies','target']:
            manifest=json.loads(json.dumps(self.manifest));extra=None
            if mutation=='sql':extra=self.stage/'sql/data/business.sql';self.write(extra,'UPDATE billing SET amount=0;')
            elif mutation=='entrypoint':extra=self.stage/'files/think';self.write(extra,'<?php')
            elif mutation=='guard':self.write(self.stage/'sql/structure/001_runtime_bootstrap_guard.sql','SELECT 1;')
            elif mutation=='dependencies':manifest['composer_runtime']={}
            else:manifest['target_version']='1.0.392'
            try:
                with self.assertRaises(SystemExit):module.prepare_runtime_bootstrap(self.repo,self.stage,manifest,'1.0.391')
            finally:
                if extra:extra.unlink()
                for relative,contents in self.gates.items():self.write(self.stage/relative,contents)
    def test_direct_old_updater_dependency_payload_still_rejected(self):
        with self.assertRaises(SystemExit):module.assert_incremental_composer_runtime_absent(self.inner,self.inner_manifest)

if __name__=='__main__':unittest.main()
