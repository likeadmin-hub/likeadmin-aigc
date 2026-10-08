#!/usr/bin/env python3
"""Exercise the shipped rewrite template with a real, isolated Nginx process.

Run: python3 tests/routing/platform_refresh_nginx.py --nginx /path/to/nginx
No PHP, database, existing vhost, or production reload is involved.
"""
import argparse
import pathlib
import socket
import subprocess
import tempfile
import time
import urllib.error
import urllib.request


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--nginx", default="nginx")
    parser.add_argument("--template", type=pathlib.Path)
    args = parser.parse_args()
    template = (args.template or pathlib.Path(__file__).resolve().parents[2] / "public/nginx.htaccess").resolve()
    with tempfile.TemporaryDirectory(prefix="platform-refresh-") as directory:
        root = pathlib.Path(directory)
        root.chmod(0o755)  # Nginx workers drop privileges when started as root.
        (root / "logs").mkdir()
        (root / "platform/assets").mkdir(parents=True)
        (root / "platform/index.html").write_text("platform-entry", encoding="utf-8")
        (root / "platform/assets/app.js").write_text("platform-script", encoding="utf-8")
        with socket.socket() as listener:
            listener.bind(("127.0.0.1", 0))
            port = listener.getsockname()[1]
        config = root / "nginx.conf"
        config.write_text(f'''
worker_processes 1;
pid "{root}/nginx.pid";
error_log "{root}/error.log";
events {{ worker_connections 32; }}
http {{
    access_log off;
    server {{
        listen 127.0.0.1:{port};
        root "{root}";
        index index.html;
        # A common vhost file protection must not capture SPA page names.
        location ~* (LICENSE|README\\.md)$ {{ return 404; }}
        error_page 404 /404.html;
        location = /404.html {{ internal; return 200 "tenant-error-page"; }}
        location = /index.php {{ return 200 "dynamic-entry"; }}
        include "{template}";
    }}
}}
''', encoding="utf-8")
        subprocess.run([args.nginx, "-t", "-p", directory, "-c", str(config)], check=True)
        process = subprocess.Popen([args.nginx, "-p", directory, "-c", str(config), "-g", "daemon off;"])
        try:
            for _ in range(100):
                if process.poll() is not None:
                    raise RuntimeError((root / "error.log").read_text())
                try:
                    with socket.create_connection(("127.0.0.1", port), timeout=0.1):
                        break
                except OSError:
                    time.sleep(0.02)
            paths = {
                "/platform": (200, "platform-entry"),
                "/platform/": (200, "platform-entry"),
                "/platform/login": (200, "platform-entry"),
                "/platform/workbench": (200, "platform-entry"),
                "/platform/tenant/tenant": (200, "platform-entry"),
                "/platform/setting/system/setting": (200, "platform-entry"),
                "/platform/apps/aigc_canvas/config": (200, "platform-entry"),
                "/platform/permission/role/edit?id=1": (200, "platform-entry"),
                "/platform/system-service/license?": (200, "platform-entry"),
                "/platform/system-service/license?redirect=1": (200, "platform-entry"),
                "/platform/system-service/license/": (200, "platform-entry"),
                "/platform/foo/LICENSE": (200, "platform-entry"),
                "/platform/foo/README.md": (200, "platform-entry"),
                "/platform/assets/app.js": (200, "platform-script"),
                "/platform-other/workbench": (200, "dynamic-entry"),
                "/platformapi/config/getConfig": (200, "dynamic-entry"),
                "/tenantapi/config/getConfig": (200, "dynamic-entry"),
                "/admin/system-service/license-test": (200, "dynamic-entry"),
                "/pricing?tenant_id=42": (200, "dynamic-entry"),
                "/LICENSE": (404, "tenant-error-page"),
            }
            for path, expected in paths.items():
                try:
                    response = urllib.request.urlopen(f"http://127.0.0.1:{port}{path}", timeout=3)
                except urllib.error.HTTPError as error:
                    response = error
                with response:
                    actual = response.status, response.read().decode()
                assert actual == expected, f"{path}: expected {expected}, got {actual}"
            print(f"PASS: {len(paths)} real Nginx platform/static/API/tenant route checks")
        finally:
            process.terminate()
            process.wait(timeout=5)


if __name__ == "__main__":
    main()
