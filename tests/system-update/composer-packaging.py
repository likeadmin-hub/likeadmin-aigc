#!/usr/bin/env python3
import importlib.util
import json
import pathlib
import tempfile
import unittest
import os
import subprocess
import zipfile

tool = pathlib.Path(os.environ.get('AIGC_PACKAGING_TOOL', str(pathlib.Path.home() / '.codex/skills/likeadmin-aigc-saas-update-packaging/scripts/package_update.py')))
spec = importlib.util.spec_from_file_location('packaging_tool', tool)
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)

class ComposerPackagingTest(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.root = pathlib.Path(self.tmp.name)
        self.payload = self.root / 'composer-runtime'
        (self.payload / 'vendor/composer').mkdir(parents=True)
        for p in ['composer.json','composer.lock','vendor/autoload.php','vendor/composer/autoload_real.php','vendor/composer/installed.json','vendor/composer/installed.php','vendor/services.php']:
            (self.payload / p).write_text('{}')
        (self.payload / 'composer.lock').write_text(json.dumps({'packages':[{'name':'example/runtime','version':'1.0.0'}]}))
        (self.payload / 'vendor/composer/installed.json').write_text(json.dumps({'packages':[{'name':'example/runtime','version':'1.0.0'}]}))
        self.manifest={'package_mode':'incremental','base_version':'1.0.391','target_version':'1.0.392','composer_runtime':{
            'schema':1,'payload_dir':'composer-runtime','min_updater_version':'1.0.391','min_php':'8.2.0',
            'required_extensions':['json'],'probe_classes':['Example\\Runtime']}}
    def tearDown(self): self.tmp.cleanup()
    def check(self): module.assert_incremental_composer_runtime_absent(self.root,self.manifest)
    def test_complete_atomic_payload_allowed(self): self.check()
    def test_undeclared_payload_rejected(self):
        self.manifest.pop('composer_runtime')
        with self.assertRaises(SystemExit): self.check()
    def test_old_updater_rejected(self):
        self.manifest['base_version']='1.0.390'
        with self.assertRaises(SystemExit): self.check()
    def test_partial_payload_rejected(self):
        (self.payload/'vendor/composer/autoload_real.php').unlink()
        with self.assertRaises(SystemExit): self.check()
    def test_mismatched_installed_dependency_rejected(self):
        (self.payload/'vendor/composer/installed.json').write_text('{"packages":[]}')
        with self.assertRaises(SystemExit): self.check()
    def test_dependency_files_still_forbidden_under_files(self):
        (self.root/'files/vendor').mkdir(parents=True)
        with self.assertRaises(SystemExit): self.check()


class UpdaterBridgePackagingTest(unittest.TestCase):
    def setUp(self):
        self.tmp=tempfile.TemporaryDirectory();self.root=pathlib.Path(self.tmp.name);self.repo=self.root/'repo';self.stage=self.root/'stage'
        self.manifest={'version':'1.0.391','package_mode':'incremental','base_version':'1.0.390','target_version':'1.0.391','updater_bridge':True,'full_replace_dirs':[],'delete_files':[],'sql_order':[]}
        self.allowed=['runtime-loader.php','think','public/index.php','scripts/composer-runtime-runner.php','scripts/verify-composer-runtime.php','app/common/service/update/ComposerRuntimeUpdateService.php','app/common/service/update/SystemPackageUpdateService.php','config/project.php']+['app/common/command/'+name+'.php' for name in ['AiTaskWorker','ShortDramaEpisodeWorker','ShortDramaPlanningWorker','ShortDramaCanvasAgentWorker','Crontab']]
        for rel in self.allowed:self.write(self.stage/'files'/rel,'<?php // fixture')
        for directory in ['sql/data','sql/structure','menus','rollback']:(self.stage/directory).mkdir(parents=True)
        for code in module.SYSTEM_BRIDGE_APP_CODES:
            for rel in module.SYSTEM_BRIDGE_APP_PATHS:
                target=self.repo/'server/app/apps'/code/rel
                if rel.endswith('.json'):self.write(target,'{}')
                else:target.mkdir(parents=True)
    def write(self,path,value):path.parent.mkdir(parents=True,exist_ok=True);path.write_text(value)
    def tearDown(self):self.tmp.cleanup()
    def test_pure_updater_bridge_skips_builtin_app_injection(self):
        module.ensure_system_bridge_apps(self.repo,self.stage,self.manifest)
        self.assertFalse((self.stage/'files/app/apps').exists(),'new app metadata/migrations injected into updater bridge')
    def test_functional_incremental_still_injects_all_six_apps(self):
        self.manifest.pop('updater_bridge');module.ensure_system_bridge_apps(self.repo,self.stage,self.manifest)
        for code in module.SYSTEM_BRIDGE_APP_CODES:self.assertTrue((self.stage/'files/app/apps'/code/'manifest.json').is_file())
    def test_valid_bridge_boundary(self):module.assert_updater_bridge(self.stage,self.manifest)
    def test_official_bridge_archive_and_repository_verifier(self):
        self.manifest['version']='1.0.390'
        self.write(self.stage/'update.json',json.dumps(self.manifest))
        result=subprocess.run(['python3',str(tool),'system','--repo',str(self.repo),'--source-dir',str(self.stage),'--out-dir',str(self.root/'output'),'--updater-bridge'],capture_output=True,text=True)
        self.assertEqual(result.returncode,0,result.stderr)
        archive=self.root/'output/system_1.0.391.zip'
        with zipfile.ZipFile(archive) as z:
            names=z.namelist();manifest=json.loads(z.read('update.json'))
            self.assertTrue(manifest['updater_bridge']);self.assertEqual(manifest['sql_order'],[])
            self.assertFalse(any(name.startswith('files/app/apps/') or name.startswith('composer-runtime/') or name.endswith('.sql') for name in names))
        verifier=pathlib.Path(__file__).resolve().parents[2]/'scripts/verify-system-update-package.mjs'
        verified=subprocess.run(['node',str(verifier),str(archive)],capture_output=True,text=True)
        self.assertEqual(verified.returncode,0,verified.stderr)
        # A hand-edited staging bridge cannot bypass the archive verifier.
        module.write_signature(self.stage)
        for kind in ['business','sql','wrong-endpoint']:
            extra=None
            if kind=='business':extra=self.stage/'files/app/unreviewed.php';self.write(extra,'<?php')
            elif kind=='sql':extra=self.stage/'sql/data/hidden.sql';self.write(extra,'SELECT 1;')
            else:
                modified=json.loads((self.stage/'update.json').read_text());modified['base_version']='1.0.389';self.write(self.stage/'update.json',json.dumps(modified))
            try:
                rejected=subprocess.run(['node',str(verifier),str(self.stage)],capture_output=True,text=True)
                self.assertNotEqual(rejected.returncode,0)
                self.assertIn('Invalid updater_bridge',rejected.stderr)
            finally:
                if extra:extra.unlink()
    def test_bridge_rejects_wrong_chain_dependencies_sql_and_non_updater_files(self):
        for mutation in ['version','dependencies','sql','business','delete','missing','type']:
            with self.subTest(mutation=mutation):
                manifest=dict(self.manifest);extra=None;removed=None
                if mutation=='version':manifest['base_version']='1.0.389'
                elif mutation=='dependencies':manifest['composer_runtime']={}
                elif mutation=='sql':extra=self.stage/'sql/data/unsafe.sql';self.write(extra,'INSERT INTO billing VALUES (1);')
                elif mutation=='business':extra=self.stage/'files/app/new-business.php';self.write(extra,'<?php')
                elif mutation=='delete':manifest['delete_files']=['app/existing.php']
                elif mutation=='missing':removed=self.stage/'files/think';removed.unlink()
                elif mutation=='type':manifest['updater_bridge']='true'
                try:
                    with self.assertRaises(SystemExit):module.assert_updater_bridge(self.stage,manifest)
                finally:
                    if extra:extra.unlink()
                    if removed:self.write(removed,'<?php // fixture')

if __name__=='__main__': unittest.main()
