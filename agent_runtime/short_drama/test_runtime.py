import json
import os
from pathlib import Path
import subprocess
import sys
import unittest


class RuntimeProtocolTest(unittest.TestCase):
    def invoke(self, frames, *args):
        env = {**os.environ, 'LANGSMITH_TRACING': 'false', 'LANGCHAIN_TRACING_V2': 'false'}
        result = subprocess.run(
            [sys.executable, '-u', str(Path(__file__).with_name('runtime.py')), *args],
            input=''.join(json.dumps(f, ensure_ascii=False) + '\n' for f in frames),
            text=True, capture_output=True, timeout=25, env=env)
        self.assertNotIn('Traceback', result.stdout)
        return result.returncode, [json.loads(line) for line in result.stdout.splitlines()]

    def init(self):
        return {'scope': {'tenant': 1, 'user': 2, 'canvas': 3, 'thread': 4},
                'messages': [{'role': 'user', 'content': '你好'}],
                'instruction': 'Return a single domain proposal.'}

    def test_real_graph_uses_one_metered_receipt_and_returns_exact_proposal(self):
        decision = {'reply_markdown': '你好，有什么想聊的？',
                    'action': {'name': 'respond', 'arguments': []}, 'remember': []}
        code, frames = self.invoke([self.init(), {'type': 'model_result', 'sequence': 1, 'decision': decision}])
        self.assertEqual(0, code)
        self.assertEqual(['ready', 'model_request', 'complete'], [f['type'] for f in frames])
        self.assertEqual(decision, frames[-1]['decision'])
        self.assertEqual(1, frames[-1]['model_calls'])

    def test_invalid_scope_stops_before_any_metered_request(self):
        frame = self.init()
        frame['scope']['tenant'] = 0
        code, frames = self.invoke([frame])
        self.assertEqual(1, code)
        self.assertEqual(['ready', 'error'], [f['type'] for f in frames])

    def test_receipt_sequence_cannot_trigger_a_second_request(self):
        code, frames = self.invoke([self.init(), {'type': 'model_result', 'sequence': 2, 'decision': {}}])
        self.assertEqual(1, code)
        self.assertEqual(['ready', 'model_request', 'error'], [f['type'] for f in frames])

    def test_health_never_requests_model(self):
        code, frames = self.invoke([], '--health')
        self.assertEqual(0, code)
        self.assertEqual([{'type': 'ready', 'protocol': 1, 'engine': 'deepagents_v1'}], frames)


if __name__ == '__main__':
    unittest.main()
