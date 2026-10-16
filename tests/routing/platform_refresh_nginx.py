#!/usr/bin/env python3
"""Exercise platform and tenant-admin refresh with an isolated Nginx process.

Run: python3 tests/routing/platform_refresh_nginx.py --nginx /path/to/nginx
No PHP, database, existing vhost, or production reload is involved.
"""
import argparse
import http.server
import pathlib
import re
import socket
import subprocess
import tempfile
import threading
import time
import urllib.error
import urllib.request
import urllib.parse


class EntryGateway(http.server.BaseHTTPRequestHandler):
    """Record the dynamic boundary; PHP host policy has its own behavioral tests."""

    def do_GET(self):
        query = urllib.parse.parse_qs(urllib.parse.urlsplit(self.path).query)
        path = query.get('s', [re.sub(r'^/index\.php', '', urllib.parse.urlsplit(self.path).path)])[0]
        assert path.startswith('/'), f'Lost original route: {self.path}'
        is_platform = self.headers['Host'] == 'platform.test'
        if path == '/' and is_platform and not query.get('tenant_id'):
            self.send_response(302)
            self.send_header('Location', '/platform/')
            self.end_headers()
            return
        if path == '/platform' or path.startswith('/platform/'):
            code, body = (200, 'platform-entry') if is_platform else (404, 'platform-denied')
        elif path.startswith('/platformapi/') and not is_platform:
            code, body = 403, 'platform-api-denied'
        else:
            code, body = 200, 'dynamic-entry'
        self.send_response(code)
        self.end_headers()
        self.wfile.write(body.encode())

    def log_message(self, *args):
        pass


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *args, **kwargs):
        return None


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--nginx", default="nginx")
    parser.add_argument("--template", type=pathlib.Path)
    args = parser.parse_args()
    template = (args.template or pathlib.Path(__file__).resolve().parents[2] / "public/nginx.htaccess").resolve()
    docker_template = template.name == 'default.conf'
    with tempfile.TemporaryDirectory(prefix="platform-refresh-") as directory:
        root = pathlib.Path(directory)
        root.chmod(0o755)  # Nginx workers drop privileges when started as root.
        (root / "logs").mkdir()
        (root / "platform/assets").mkdir(parents=True)
        (root / "platform/index.html").write_text("platform-entry", encoding="utf-8")
        (root / "platform/assets/app.js").write_text("platform-script", encoding="utf-8")
        (root / "platform/favicon.ico").write_text("platform-icon", encoding="utf-8")
        (root / "platform/.env").write_text("must-not-be-served", encoding="utf-8")
        (root / "platform/hidden.php").write_text("must-not-be-served", encoding="utf-8")
        (root / "index.html").write_text("root-must-pass-host-policy", encoding="utf-8")
        (root / "admin/assets").mkdir(parents=True)
        (root / "admin/index.html").write_text("admin-entry", encoding="utf-8")
        (root / "admin/assets/app.js").write_text("admin-script", encoding="utf-8")
        (root / "admin/assets/app.css").write_text("admin-style", encoding="utf-8")
        (root / "admin/.env").write_text("must-not-be-served", encoding="utf-8")
        (root / "admin/hidden.php").write_text("must-not-be-served", encoding="utf-8")
        with socket.socket() as listener:
            listener.bind(("127.0.0.1", 0))
            port = listener.getsockname()[1]
        config = root / "nginx.conf"
        if docker_template:
            # Exercise the Docker server's real routing directives; replace only
            # its vhost envelope and FastCGI transport with this fixture gateway.
            source = template.read_text(encoding='utf-8').split('server {', 1)[1].rsplit('}', 1)[0]
            source = re.sub(r'^    (listen|server_name|root|index) .*?;\n', '', source, flags=re.M)
            source = re.sub(r'    location ~ \^/index.*?\n    }\n', '', source, flags=re.S)
            template = root / 'docker-routes.conf'
            template.write_text(source, encoding='utf-8')
        backend = http.server.HTTPServer(('127.0.0.1', 0), EntryGateway)
        threading.Thread(target=backend.serve_forever, daemon=True).start()
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
        location ~ ^/index\\.php(?:/|$) {{
            proxy_set_header Host $http_host;
            proxy_pass http://127.0.0.1:{backend.server_port};
        }}
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
                "/platform/index.html": (200, "platform-entry"),
                "/platform/favicon.ico": (200, "platform-icon"),
                "/platform/assets/missing.js": (404, "tenant-error-page"),
                "/platform/.env": (404, "tenant-error-page"),
                "/platform/hidden.php": (404, "tenant-error-page"),
                "/platform-other/workbench": (200, "dynamic-entry"),
                "/platformapi/config/getConfig": (200, "dynamic-entry"),
                "/tenantapi/config/getConfig": (200, "dynamic-entry"),
                "/admin": (200, "admin-entry"),
                "/admin/": (200, "admin-entry"),
                "/admin/login": (200, "admin-entry"),
                "/admin/workbench": (200, "admin-entry"),
                "/admin/user/user": (200, "admin-entry"),
                "/admin/channel/overview?tenant_id=42": (200, "admin-entry"),
                "/admin/setting/system/setting": (200, "admin-entry"),
                "/admin/system-service/license-test": (200, "admin-entry"),
                "/admin/permission/role/edit?id=1&tenant_id=42": (200, "admin-entry"),
                "/admin/apps/aigc_canvas/config": (200, "admin-entry"),
                "/admin/foo/LICENSE": (200, "admin-entry"),
                "/admin/foo/README.md": (200, "admin-entry"),
                "/admin/assets/app.js": (200, "admin-script"),
                "/admin/assets/app.css": (200, "admin-style"),
                "/admin/assets/missing.js": (404, "tenant-error-page"),
                "/admin/.env": (404, "tenant-error-page"),
                "/admin/hidden.php": (404, "tenant-error-page"),
                "/admin-other/workbench": (200, "dynamic-entry"),
                "/t/42/admin/user/user": (200, "dynamic-entry"),
                "/wechat/open-platform/callback": (200, "dynamic-entry"),
                "/pricing?tenant_id=42": (200, "dynamic-entry"),
                "/LICENSE": (404, "tenant-error-page"),
            }
            if docker_template:
                # Tenant-admin/PC fallbacks differ between the deployment
                # templates; the Docker run verifies this task's host boundary.
                paths = {path: expected for path, expected in paths.items()
                         if path.startswith(('/platform', '/tenantapi/', '/wechat/'))}
            for path, expected in paths.items():
                try:
                    response = urllib.request.urlopen(urllib.request.Request(
                        f"http://127.0.0.1:{port}{path}", headers={'Host': 'platform.test'}), timeout=3)
                except urllib.error.HTTPError as error:
                    response = error
                with response:
                    actual = response.status, response.read().decode()
                assert actual == expected, f"{path}: expected {expected}, got {actual}"
            opener = urllib.request.build_opener(NoRedirect)
            cases = [('platform.test', '/', 302, '/platform/'),
                     ('platform.test', '/?tenant_id=42', 200, 'dynamic-entry')]
            for host in ['customer.platform.test', 'bound.customer.test']:
                cases.extend((host, path, code, body) for path, code, body in [
                    ('/', 200, 'dynamic-entry'),
                    ('/platform', 404, 'platform-denied'),
                    ('/platform/', 404, 'platform-denied'),
                    ('/platform/index.html', 404, 'platform-denied'),
                    ('/platform/system-service/license?tenant_id=42', 404, 'platform-denied'),
                    ('/platformapi/login/account', 403, 'platform-api-denied'),
                    ('/platform/assets/app.js', 200, 'platform-script'),
                ])
            for host, path, code, body in cases:
                request = urllib.request.Request(f'http://127.0.0.1:{port}{path}', headers={'Host': host})
                try:
                    response = opener.open(request, timeout=3)
                except urllib.error.HTTPError as error:
                    response = error
                with response:
                    actual = (response.status, response.headers.get('Location') if code == 302 else response.read().decode())
                assert actual == (code, body), f'{host}{path}: {actual}'
            print(f"PASS: {len(paths) + len(cases)} real Nginx platform/admin/static/API/tenant route checks")
        finally:
            process.terminate()
            process.wait(timeout=5)
            backend.shutdown()
            backend.server_close()


if __name__ == "__main__":
    main()
