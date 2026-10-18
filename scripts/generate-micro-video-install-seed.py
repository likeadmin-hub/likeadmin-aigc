#!/usr/bin/env python3
"""Generate default micro-video metadata for fresh installs and upgrades.

Preserve existing settings, wallets, tasks and tenant-owned menus.
"""
import importlib.util
import json
from pathlib import Path
import sys

ROOT = Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location('install_seed', ROOT / 'scripts/generate-open-platform-install-seed.py')
helper = importlib.util.module_from_spec(spec)
spec.loader.exec_module(helper)
APP = ROOT / 'app/apps/aigc_micro_video'
BEGIN = '-- BEGIN micro video builtin bootstrap (generated from app manifest)'
END = '-- END micro video builtin bootstrap'
UPGRADE = '20261018_micro_video_builtin.sql'


def generate():
    return helper.generate(APP, BEGIN, END)


def main():
    seed = generate()
    install = ROOT / 'public/install/db/like.sql'
    original = install.read_text()
    if BEGIN in original:
        start = original.index(BEGIN)
        finish = original.index(END, start) + len(END)
        updated = original[:start] + seed.rstrip('\n') + original[finish:]
    else:
        updated = original.rstrip() + '\n\n' + seed
    files = {install: updated, ROOT / 'upgrade' / UPGRADE: seed, ROOT / 'public/upgrade' / UPGRADE: seed}
    if '--check' in sys.argv:
        mismatches = [str(path.relative_to(ROOT)) for path, content in files.items()
                      if not path.exists() or path.read_text() != content]
        if mismatches:
            raise SystemExit('Micro video builtin registry seed drift: ' + ', '.join(mismatches))
        print('PASS Micro video builtin manifest/API registry parity across install and upgrade paths')
    else:
        for path, content in files.items():
            path.write_text(content)
        print('Updated Micro video builtin install and upgrade registry metadata')


if __name__ == '__main__':
    main()
