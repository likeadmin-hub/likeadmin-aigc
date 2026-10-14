import importlib.util
import json
import pathlib
import tempfile
import unittest

script = pathlib.Path(__file__).resolve().parents[2] / 'scripts/prepare-pixlr-ad-media.py'
spec = importlib.util.spec_from_file_location('ads_preparer', script)
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)


class RuntimeCatalogTest(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory()
        self.site = pathlib.Path(self.temporary.name)
        self.relative = pathlib.Path('app/apps/aigc_video/data/pixlr-ads.json')
        self.identity = 'release-' + 'a' * 24
        self.generation = self.site / '.composer-runtime/generations' / self.identity

    def tearDown(self):
        self.temporary.cleanup()

    def write_catalog(self, root, value):
        target = root / self.relative
        target.parent.mkdir(parents=True, exist_ok=True)
        target.write_text(json.dumps({'items': [value]}))
        return target

    def select_generation(self):
        self.generation.mkdir(parents=True)
        (self.generation / 'generation.json').write_text('{"schema":1}')
        (self.site / '.composer-runtime/current.json').write_text(json.dumps({'schema': 1, 'generation': self.identity}))

    def test_fresh_install_uses_canonical_catalog(self):
        self.assertEqual(self.write_catalog(self.site, 'fresh'), module.active_catalog(self.site))

    def test_upgraded_install_uses_new_generation_with_no_canonical_catalog(self):
        self.select_generation()
        self.assertEqual(self.write_catalog(self.generation, 'new'), module.active_catalog(self.site))

    def test_old_canonical_catalog_cannot_override_active_generation(self):
        self.write_catalog(self.site, 'old')
        self.select_generation()
        self.assertEqual(self.write_catalog(self.generation, 'new'), module.active_catalog(self.site))

    def test_missing_selected_catalog_does_not_fall_back(self):
        self.write_catalog(self.site, 'old')
        self.select_generation()
        with self.assertRaises(RuntimeError): module.active_catalog(self.site)

    def test_pending_update_blocks_preparation(self):
        self.select_generation()
        (self.site / '.composer-runtime/active.json').write_text('{}')
        with self.assertRaises(RuntimeError): module.active_catalog(self.site)

    def test_pointer_traversal_is_rejected(self):
        self.select_generation()
        (self.site / '.composer-runtime/current.json').write_text('{"schema":1,"generation":"../../outside"}')
        with self.assertRaises(RuntimeError): module.active_catalog(self.site)

    def test_catalog_link_outside_generation_is_rejected(self):
        target = self.write_catalog(self.site, 'outside')
        self.select_generation()
        linked = self.generation / self.relative
        linked.parent.mkdir(parents=True)
        linked.symlink_to(target)
        with self.assertRaises(RuntimeError): module.active_catalog(self.site)

    def test_php_importer_uses_official_pinned_runtime(self):
        text = (script.parent / 'import-pixlr-ad-templates.php').read_text()
        self.assertIn("'/runtime-loader.php'", text)
        self.assertIn('new \\AigcRuntimeApp($siteRoot)', text)
        self.assertIn("$GLOBALS['AIGC_RUNTIME_GENERATION']", text)
        self.assertNotIn("'/vendor/autoload.php'", text)


if __name__ == '__main__': unittest.main()
