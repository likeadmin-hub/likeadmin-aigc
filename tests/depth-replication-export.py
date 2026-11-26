"""Exercise VFR timestamps and each source audio track independently, without downloading weights."""
import base64
import hashlib
import json
import os
from pathlib import Path
import subprocess
import sys
import tempfile

import cv2
import numpy as np

sys.path.insert(0, str(Path(__file__).resolve().parents[1] / 'app/apps/aigc_depth_replication/worker'))
from pipeline import layout, render


def ffmpeg(*args):
    return subprocess.check_output([os.getenv('FFMPEG_BINARY', 'ffmpeg'), '-nostdin', '-v', 'error', *map(str, args)])


def audio_hash(path, index):
    return hashlib.sha256(ffmpeg('-i', path, '-map', f'0:a:{index}', '-c:a', 'copy', '-f', 'adts', '-')).hexdigest()


def check(folder, name, audio):
    source = folder / (name + '.mov')
    output = folder / (name + '.mp4')
    ffmpeg('-f', 'lavfi', '-i', 'testsrc2=size=64x48:rate=30:duration=1', *audio,
           '-vf', "select='if(lt(t,0.5),not(mod(n,2)),1)'", '-vsync', 'vfr', '-c:v', 'libx264', source)
    meta = layout(source)
    assert len(set(meta['ticks'])) > 1, 'Fixture must exercise variable frame rate'
    meta['shots'] = [0, 5]
    (folder / 'index.json').write_text(json.dumps(meta))
    for i in range(len(meta['pts'])):
        packed = np.full((48, 64, 3), (128, 63, 63), dtype=np.uint8)
        assert cv2.imwrite(str(folder / f'{i}.png'), packed)
    def lookup(gray):
        return base64.b64encode(bytes([gray]) * 16129 + bytes([128]) * 256).decode()
    render(source, folder, {'all': lookup(190), '5': lookup(100)}, output)
    result = layout(output)
    assert len(result['audio']) == len(meta['audio'])
    if audio:
        assert result['audio'] == ['aac', 'aac']
        assert audio_hash(source, 0) == audio_hash(output, 0)
        assert meta['audio_timing'][1]['start'] > .08
    decoded = np.frombuffer(ffmpeg('-i', output, '-map', '0:v:0', '-vsync', '0',
                                   '-pix_fmt', 'gray', '-f', 'rawvideo', '-'), np.uint8).reshape(-1, 48, 64)
    for i, frame in enumerate(decoded):
        assert abs(float(frame.mean()) - (190 if i < 5 else 100)) <= 2, 'Wrong lighting at cut'


with tempfile.TemporaryDirectory() as temp:
    root = Path(temp)
    check(root, 'silent', [])
    check(root, 'two-tracks', [
        '-f', 'lavfi', '-i', 'sine=frequency=220:duration=0.8',
        '-itsoffset', '0.12', '-f', 'lavfi', '-i', 'sine=frequency=440:duration=0.6',
        '-map', '0:v', '-map', '1:a', '-map', '2:a', '-c:a:0', 'aac', '-c:a:1', 'pcm_s16le'])
print('Verified source timestamps, lighting at the cut, silent export, AAC passthrough and offset PCM audio')
