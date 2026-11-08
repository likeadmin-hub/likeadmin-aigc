#!/usr/bin/env python3
"""Install the locked PHPUnit PHAR without replacing application dependencies."""
import argparse
import hashlib
import json
import os
from pathlib import Path
import tempfile
import urllib.request

VERSION = "9.6.36"
SHA256 = "d9552a130747f02f9d7fc2427b143189c638e273c502c8faa88ab6b04c5f2662"
URL = "https://phar.phpunit.de/phpunit-" + VERSION + ".phar"


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--source", type=Path, help="Use an already downloaded PHAR with the same pinned checksum")
    args = parser.parse_args()
    root = Path(__file__).resolve().parents[1]
    lock = json.loads((root / "composer.lock").read_text())
    locked = next(p["version"].lstrip("v") for p in lock["packages-dev"] if p["name"] == "phpunit/phpunit")
    if locked != VERSION:
        raise SystemExit("Update the test-tool version and checksum to match composer.lock first")
    cache = root / ".composer-runtime" / "test-tools"
    target = cache / ("phpunit-" + VERSION + ".phar")
    binary = root / "vendor" / "bin" / "phpunit"
    link = os.path.relpath(target, binary.parent)
    if os.path.lexists(binary) and not (binary.is_symlink() and os.readlink(binary) == link):
        raise SystemExit("An existing vendor/bin/phpunit was preserved; use it or inspect it before replacement")
    cache.mkdir(parents=True, exist_ok=True)
    if target.exists() and hashlib.sha256(target.read_bytes()).hexdigest() != SHA256:
        raise SystemExit("Existing cached PHPUnit failed its checksum; preserved for inspection")
    if not target.exists():
        data = args.source.read_bytes() if args.source else urllib.request.urlopen(URL, timeout=60).read()
        if hashlib.sha256(data).hexdigest() != SHA256:
            raise SystemExit("PHPUnit checksum mismatch; no executable installed")
        with tempfile.NamedTemporaryFile(dir=cache, delete=False) as temporary:
            temporary.write(data)
            staged = Path(temporary.name)
        try:
            staged.chmod(0o755)
            os.replace(staged, target)
        finally:
            staged.unlink(missing_ok=True)
    binary.parent.mkdir(parents=True, exist_ok=True)
    if not os.path.lexists(binary):
        binary.symlink_to(link)
    print("Installed PHPUnit " + VERSION + " at " + str(binary))
    print("SHA256 " + SHA256)


if __name__ == "__main__":
    main()
