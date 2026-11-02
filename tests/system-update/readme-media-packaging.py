#!/usr/bin/env python3
"""Documentation videos must stay out of distribution archives."""
import importlib.util
import os
from pathlib import Path
import shutil
import subprocess
import tarfile
import tempfile
import unittest
import zipfile

REPO = Path(__file__).resolve().parents[2]
TOOL = Path(os.environ.get('AIGC_PACKAGING_TOOL', str(Path.home() / '.codex/skills/likeadmin-aigc-saas-update-packaging/scripts/package_update.py')))


class ReadmeMediaPackagingTest(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.root = Path(self.temp.name)
        self.source = self.root / 'source'
        self.doc_media = '.github/media/intro.mp4'
        self.preview = '.github/media/intro.gif'
        self.app_media = 'public/media/application.mp4'
        for name in [self.doc_media, self.preview, self.app_media, 'README.md']:
            path = self.source / 'server' / name
            path.parent.mkdir(parents=True, exist_ok=True)
            path.write_text(name)

    def tearDown(self):
        self.temp.cleanup()

    def test_full_install_zip_tar_and_signature_exclude_only_presentation_media(self):
        spec = importlib.util.spec_from_file_location('packaging_tool', TOOL)
        module = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(module)
        work = self.root / 'install'
        module.copy_full_server_source(self.source, work)
        signature = module.write_signature(work)
        self.assertIn('server/' + self.app_media, signature['sha256'])
        for name in [self.doc_media, self.preview]:
            self.assertNotIn('server/' + name, signature['sha256'])
        zip_path, tar_path = self.root / 'install.zip', self.root / 'install.tar.gz'
        module.make_zip(work, zip_path)
        module.make_targz(work, tar_path)
        with zipfile.ZipFile(zip_path) as archive:
            zip_names = archive.namelist()
        with tarfile.open(tar_path) as archive:
            tar_names = archive.getnames()
        for names in [zip_names, tar_names]:
            self.assertIn('server/' + self.app_media, names)
            self.assertIn('server/README.md', names)
            self.assertFalse(any('.github/media' in name for name in names))

    def test_git_source_archive_keeps_readme_and_runtime_video(self):
        repo = self.source / 'server'
        shutil.copy2(REPO / '.gitattributes', repo / '.gitattributes')
        for args in [
            ['init', '-q'],
            ['add', self.doc_media, self.preview, self.app_media, 'README.md', '.gitattributes'],
            ['-c', 'user.name=Fixture', '-c', 'user.email=fixture@example.invalid', 'commit', '-qm', 'fixture'],
            ['archive', '--format=tar', '-o', str(self.root / 'source.tar'), 'HEAD'],
        ]:
            subprocess.run(['git', *args], cwd=repo, check=True, capture_output=True)
        with tarfile.open(self.root / 'source.tar') as archive:
            names = archive.getnames()
        self.assertIn('README.md', names)
        self.assertIn(self.app_media, names)
        self.assertFalse(any('.github/media' in name for name in names))


if __name__ == '__main__':
    unittest.main()
