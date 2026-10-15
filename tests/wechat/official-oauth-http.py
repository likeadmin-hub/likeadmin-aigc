#!/usr/bin/env python3
"""Local Nginx/ThinkPHP smoke test. Never follows the WeChat URL."""
import json
import re
import subprocess
import urllib.error
import urllib.parse
import urllib.request

CMD = ["docker", "exec", "bt", "/www/server/php/82/bin/php", "/www/wwwroot/likeadmin_aigc_saas/server/tests/wechat/official-oauth-runtime.php"]
seed = json.loads(subprocess.check_output(CMD + ["--seed-http"], text=True))
state = urllib.parse.parse_qs(urllib.parse.urlsplit(seed["url"]).query)["state"][0]
checks = 0

def get(path, cookie=None, https=True):
    headers = {"Host": "z.cn", "X-Forwarded-Proto": "https" if https else "http"}
    if cookie:
        headers["Cookie"] = cookie
    try:
        return urllib.request.urlopen(urllib.request.Request("http://127.0.0.1" + path, headers=headers), timeout=20)
    except urllib.error.HTTPError as response:
        return response

try:
    response = get("/wechat/official-oauth/authorize?state=" + state)
    body = response.read().decode()
    assert response.status == 200, (response.status, body[:100])
    checks += 1
    cookie = response.headers.get("Set-Cookie", "")
    for token in ["wechat_official_oauth_state=" + state, "path=/wechat/official-oauth/", "secure", "httponly", "samesite=lax"]:
        assert token.lower() in cookie.lower(), (token, cookie)
        checks += 1
    for name, value in [("Cache-Control", "no-store"), ("Referrer-Policy", "no-referrer"), ("X-Frame-Options", "DENY")]:
        assert response.headers.get(name) == value
        checks += 1
    target = json.loads(re.search(r"location.replace\((.+?)\);", body)[1])
    params = urllib.parse.parse_qs(urllib.parse.urlsplit(target).query)
    assert params["redirect_uri"] == ["https://z.cn/wechat/official-oauth/callback"]
    checks += 1
    assert params["state"] == [state]
    checks += 1
    for bad_cookie, secure in [(None, True), ("wechat_official_oauth_state=wrong", True), ("wechat_official_oauth_state=" + state, False)]:
        response = get("/wechat/official-oauth/callback?state=" + state + "&code=never-exchanged", bad_cookie, secure)
        assert response.status == 400, response.read().decode()[:100]
        assert response.headers.get("Cache-Control") == "no-store"
        checks += 2
    response = get("/wechat/official-oauth/authorize?state=unknown")
    assert response.status == 400
    checks += 1
    print(f"OAuth real HTTP: {checks} checks passed (Nginx routes, secure Cookie, callback guards; no WeChat request)")
finally:
    subprocess.check_call(CMD + ["--cleanup-http", state])
