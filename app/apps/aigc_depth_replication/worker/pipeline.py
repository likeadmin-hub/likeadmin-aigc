"""Local, shot-isolated Video Depth Anything Small analysis and lossless lighting maps."""
import argparse
import base64
from fractions import Fraction
import json
import os
from pathlib import Path
import subprocess
import sys
import time

import av
import cv2
import numpy as np


def probe(path, frames=False):
    args = [os.getenv('FFPROBE_BINARY', 'ffprobe'), '-v', 'error', '-protocol_whitelist', 'file,pipe', '-show_streams', '-show_format']
    if frames:
        args += ['-select_streams', 'v:0', '-show_frames', '-show_entries',
                 'stream:stream_side_data:format:frame=best_effort_timestamp,pkt_duration']
    return json.loads(subprocess.check_output(args + ['-of', 'json', str(path)]))


def layout(source):
    info = probe(source)
    stream = next(s for s in info['streams'] if s['codec_type'] == 'video')
    w, h = stream['width'], stream['height']
    sar = stream.get('sample_aspect_ratio', '1:1')
    if sar not in ('0:1', 'N/A'):
        w = round(w * Fraction(sar.replace(':', '/')))
    rotation = next((s['rotation'] for s in stream.get('side_data_list', []) if 'rotation' in s), 0)
    if round(rotation) % 180 == 90:
        w, h = h, w
    if min(w, h) < 8 or max(w, h) > 4096 or w*h > 4200000 or float(info['format']['duration']) > 15.1:
        raise ValueError('仅支持 15 秒以内、最高 400 万像素的视频')
    index = probe(source, frames=True)['frames']
    pts = [int(f['best_effort_timestamp']) for f in index]
    if not 1 <= len(pts) <= 900 or any(b <= a for a, b in zip(pts, pts[1:])):
        raise ValueError('视频时间轴无效或超过 900 帧')
    durations = [b-a for a, b in zip(pts, pts[1:])]
    durations.append(int(index[-1].get('pkt_duration') or (durations[-1] if durations else 1)))
    tb = Fraction(stream['time_base'])
    scale = min(1, 448 / max(w, h))
    return dict(width=w, height=h, work_width=max(2, round(w*scale)), work_height=max(2, round(h*scale)),
                pts=pts, ticks=durations, time_base=str(tb), rate=stream['avg_frame_rate'],
                times=[float((p-pts[0])*tb) for p in pts], duration=float((pts[-1]-pts[0]+durations[-1])*tb),
                audio=[s['codec_name'] for s in info['streams'] if s['codec_type'] == 'audio'])


def decode(source, meta):
    w, h = meta['work_width'], meta['work_height']
    args = [os.getenv('FFMPEG_BINARY', 'ffmpeg'),'-v','error','-nostdin','-protocol_whitelist','file,pipe','-i',str(source),
            '-map','0:v:0','-vf',f'scale={w}:{h},setsar=1','-vsync','0','-pix_fmt','rgb24','-f','rawvideo','pipe:1']
    frames = np.empty((len(meta['pts']), h, w, 3), dtype=np.uint8)
    with subprocess.Popen(args, stdout=subprocess.PIPE) as proc:
        for frame in frames:
            if proc.stdout.readinto(memoryview(frame).cast('B')) != frame.nbytes:
                raise ValueError('解码帧与视频时间轴不匹配')
        if proc.stdout.read(1) or proc.wait():
            raise ValueError('视频帧数与时间轴不匹配')
    return frames


def cuts(frames):
    starts, previous = [0], None
    for i, frame in enumerate(frames):
        thumb = cv2.resize(frame, (48, 32), interpolation=cv2.INTER_AREA)
        if previous is not None and cv2.absdiff(previous, thumb).mean() > 42:
            starts.append(i)
        previous = thumb
    return starts


def basis(frame, depth):
    # Quantize slopes in a fixed screen coordinate system. The lookup table uses [-4,4].
    smoothed = cv2.bilateralFilter(depth, 5, .04, 2)
    slope = []
    for dx, dy in [(1, 0), (0, 1)]:
        grad = cv2.Sobel(smoothed, cv2.CV_32F, dx, dy, ksize=3, scale=max(frame.shape[:2])/32)
        slope.append(np.clip(np.rint(grad*15.75+63), 0, 126).astype('uint8'))
    gray = cv2.cvtColor(frame, cv2.COLOR_RGB2GRAY).astype('float32')
    detail = np.clip(gray-cv2.GaussianBlur(gray, (0,0), 2.0), -55, 55)
    return np.dstack([*slope, np.rint(detail+128).astype('uint8')])


def write_image(path, data):
    if not cv2.imwrite(str(path), data):
        raise IOError('无法写入帧缓存')


def analyze(source, folder):
    import torch
    root = Path(os.getenv('DEPTH_MODEL_HOME', '/opt/likeadmin-depth'))
    sys.path.insert(0, str(root/'model'))
    from video_depth_anything.video_depth import VideoDepthAnything
    started = time.monotonic()
    meta = layout(source)
    frames = decode(source, meta)
    starts = cuts(frames)
    device = 'cuda' if torch.cuda.is_available() else 'cpu'
    torch.set_num_threads(min(4, os.cpu_count() or 1))
    model = VideoDepthAnything(encoder='vits', features=64, out_channels=[48,96,192,384])
    model.load_state_dict(torch.load(root/'small.pth', map_location='cpu', weights_only=True))
    model.to(device).eval()
    meta['shots'] = starts
    for start, end in zip(starts, starts[1:]+[len(frames)]):
        chunk = frames[start:end]
        depths, _ = model.infer_video_depth(chunk, float(Fraction(meta['rate'])), input_size=196 if device=='cpu' else 322,
                                            device=device, fp32=device=='cpu')
        if not np.isfinite(depths).all():
            raise ValueError(f'镜头第 {start+1} 帧的深度无效')
        lo, hi = np.percentile(depths[:, ::8, ::8], [1,99])
        for i, (rgb, depth) in enumerate(zip(chunk, depths), start):
            normalized = np.clip((depth-lo)/max(1e-6,hi-lo),0,1)
            packed = basis(rgb,normalized)
            write_image(folder/f'{i}.png',cv2.cvtColor(packed,cv2.COLOR_RGB2BGR))
            write_image(folder/f'{i}.jpg',cv2.cvtColor(rgb,cv2.COLOR_RGB2BGR))
        del depths
    meta['analyze_seconds'] = round(time.monotonic()-started, 3)
    (folder/'index.json').write_text(json.dumps(meta))


def render(source, folder, tables, output):
    started = time.monotonic()
    meta = json.loads((folder/'index.json').read_text())
    lookups = {s: np.frombuffer(base64.b64decode(data),np.uint8) for s,data in tables.items()}
    base = Fraction(meta['time_base'])
    intermediate = output.with_suffix('.silent.mp4')
    with av.open(str(intermediate),'w') as mux:
        stream = mux.add_stream('libx264',rate=Fraction(meta['rate']))
        stream.width, stream.height = meta['width'], meta['height']
        stream.pix_fmt = 'yuv444p' if stream.width%2 or stream.height%2 else 'yuv420p'
        stream.options = {'preset':'veryfast','crf':'16','bf':'0'}
        stream.codec_context.time_base = stream.time_base = base
        lengths = dict(zip(meta['pts'], meta['ticks']))
        def emit(packets):
            for packet in packets:
                packet.duration = lengths[round(packet.pts*packet.time_base/base)]
                mux.mux(packet)
        for i, pts in enumerate(meta['pts']):
            if i in meta['shots']:
                lut = lookups.get(str(i),lookups['all'])
            bgr = cv2.imread(str(folder/f'{i}.png'))
            if bgr is None:
                raise ValueError(f'第 {i+1} 帧的深度缓存缺失，请重新分析')
            index = bgr[:,:,2].astype(np.int32)*127 + bgr[:,:,1]
            gray = np.clip(lut[index].astype(np.int16)+lut[127*127+bgr[:,:,0].astype(np.int32)].astype(np.int16)-128,0,255).astype(np.uint8)
            full = cv2.resize(gray,(stream.width,stream.height),interpolation=cv2.INTER_LINEAR)
            frame = av.VideoFrame.from_ndarray(full, format='gray')
            frame.pts, frame.time_base = pts, base
            emit(stream.encode(frame))
        emit(stream.encode())
    audio_codec = 'copy' if all(x in ('aac','mp3','alac','ac3','eac3') for x in meta['audio']) else 'aac'
    args = [os.getenv('FFMPEG_BINARY', 'ffmpeg'),'-v','error','-nostdin','-y','-copyts','-protocol_whitelist','file,pipe','-i',str(intermediate),
            '-protocol_whitelist','file,pipe','-i',str(source),'-map','0:v:0','-map','1:a?','-c:v','copy','-c:a',audio_codec,
            '-avoid_negative_ts','disabled','-movflags','+faststart',str(output)]
    subprocess.run(args,check=True)
    final = layout(output)
    offsets = [Fraction(p)*Fraction(final['time_base']) - Fraction(q)*base for p,q in zip(final['pts'],meta['pts'])]
    if (len(final['pts']) != len(meta['pts']) or max(map(abs,offsets)) > .0011
            or len(final['audio']) != len(meta['audio']) or abs(final['duration']-meta['duration']) > .002):
        raise ValueError('导出音视频时间轴校验失败')
    intermediate.unlink()
    return {'render_seconds':round(time.monotonic()-started,3),'audio_codec':audio_codec}


if __name__ == '__main__':
    parser = argparse.ArgumentParser()
    parser.add_argument('action', choices=['analyze','render'])
    parser.add_argument('source',type=Path)
    parser.add_argument('cache',type=Path)
    parser.add_argument('--tables',type=Path)
    parser.add_argument('--output',type=Path)
    args = parser.parse_args()
    cv2.setNumThreads(1)
    if args.action=='analyze':
        args.cache.mkdir(parents=True, exist_ok=True)
        analyze(args.source,args.cache)
    else:
        print(json.dumps(render(args.source,args.cache,json.loads(args.tables.read_text()),args.output)))

