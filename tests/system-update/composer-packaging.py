#!/usr/bin/env python3
import importlib.util
import json
import pathlib
import tempfile
import unittest
import os

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

if __name__=='__main__': unittest.main()
