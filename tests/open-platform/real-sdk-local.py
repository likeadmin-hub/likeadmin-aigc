#!/usr/bin/env python3
"""Authorized real local SDK calls. Fixture credentials stay in private process pipes."""
import importlib.metadata, json, subprocess, sys
from openai import OpenAI, DefaultHttpxClient

if sys.argv[1:] != ['--authorized-1000']:
    raise SystemExit('Explicit local 1000 point cap required')
child = subprocess.Popen(['docker', 'exec', '-i', '-w', '/www/wwwroot/likeadmin_aigc_saas/server', 'bt', '/www/server/php/82/bin/php', 'tests/open-platform/real-sdk-local.php', '--authorized-1000'], stdin=subprocess.PIPE, stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True)
result = {'passed': False, 'sdk_version': importlib.metadata.version('openai'), 'automatic_retries': 0, 'cases': []}
try:
    fixture = json.loads(child.stdout.readline())
    # macOS system proxies can route localhost through an external proxy. Scope
    # this override to the loopback fixture; leave all system settings intact.
    client = OpenAI(api_key=fixture['key'], base_url='http://127.0.0.1:19875/api/v1', max_retries=0, timeout=210,
                    http_client=DefaultHttpxClient(trust_env=False, follow_redirects=False))
    for case in fixture['cases']:
        options = dict(case['params'])
        options['extra_headers'] = {'Idempotency-Key': case['idempotency_key']}
        response = client.chat.completions.create(**options)
        if options['stream']:
            output, usage, events = '', None, 0
            for event in response:
                events += 1
                if event.usage is not None: usage = event.usage.total_tokens
                for choice in event.choices: output += choice.delta.content or ''
            assert output and usage and events > 1, 'Missing typed streamed output or trusted usage'
            result['cases'].append({'stream': True, 'typed_events': events, 'usage_tokens': usage, 'output_present': bool(output)})
        else:
            assert response.choices and response.choices[0].message.content and response.usage.total_tokens > 0, 'Missing typed completion or usage'
            result['cases'].append({'stream': False, 'usage_tokens': response.usage.total_tokens, 'output_present': True})
    result['passed'] = True
except Exception as error:
    # SDK exceptions may embed payloads/headers. Do not print their text or credentials.
    result['error_class'] = type(error).__name__
finally:
    if child.poll() is None:
        child.stdin.write(json.dumps(result) + '\n'); child.stdin.flush(); child.stdin.close()
    remaining = child.stdout.read(); child.wait(timeout=30)
    lines = remaining.strip().splitlines()
    try:
        report = json.loads(lines[-1])
    except (ValueError, IndexError):
        raise SystemExit('Local SDK fixture failed; inspect protected diagnostics, do not repeat uncertain calls')
    print(json.dumps(report, ensure_ascii=False))
    if child.returncode or report.get('result') != 'passed': raise SystemExit(1)
