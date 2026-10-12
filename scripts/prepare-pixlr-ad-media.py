#!/usr/bin/env python3
"""Download reviewed Ads previews and extract their actual first decoded frame.
Run only on local develop. Media stays outside source control. No AI calls.
"""
import argparse, concurrent.futures, hashlib, json, pathlib, subprocess

def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('--media-dir', required=True)
    ap.add_argument('--ffmpeg', default='ffmpeg')
    ap.add_argument('--ffprobe', default='ffprobe')
    args = ap.parse_args()
    rows = json.loads((pathlib.Path(__file__).resolve().parents[1] / 'docs/video-effects/pixlr-ads.json').read_text())['items']
    folder = pathlib.Path(args.media_dir); folder.mkdir(parents=True, exist_ok=True, mode=0o700)
    def prepare(row):
        url = row['source_video']
        if not url.startswith('https://img-cdn.pixlr.com/templates/') or not url.endswith('.mp4'):
            raise RuntimeError('Unexpected media origin')
        video = folder / (row['id'] + '.mp4'); image = folder / (row['id'] + '.jpg')
        def inspect(path):
            return json.loads(subprocess.check_output([args.ffprobe, '-v', 'error', '-select_streams', 'v:0', '-show_entries', 'stream=width,height:format=duration', '-of', 'json', str(path)], timeout=30, stderr=subprocess.PIPE))
        probe = None
        if video.exists():
            try: probe = inspect(video)
            except (subprocess.SubprocessError, ValueError):
                video.rename(video.with_suffix('.incomplete'))
        if probe is None:
            temporary = video.with_suffix('.part')
            try:
                subprocess.run(['curl', '--silent', '--show-error', '--fail', '--retry', '2', '--retry-all-errors', '--connect-timeout', '10', '--max-time', '120', '--max-filesize', str(80*1024*1024), '--proto', '=https', '--output', str(temporary), url], check=True, timeout=380)
                probe = inspect(temporary)
                temporary.rename(video)
            finally:
                if temporary.exists(): temporary.unlink()
        stream = probe['streams'][0]; duration = float(probe['format']['duration'])
        if duration <= 0 or duration > 120: raise RuntimeError('Unexpected duration')
        subprocess.run([args.ffmpeg, '-v', 'error', '-nostdin', '-y', '-i', str(video), '-map', '0:v:0', '-frames:v', '1', '-q:v', '2', str(image)], check=True, timeout=60)
        result = dict(id=row['id'], source_video=url, width=stream['width'], height=stream['height'], duration=duration,
                      video_sha256=hashlib.sha256(video.read_bytes()).hexdigest(), image_sha256=hashlib.sha256(image.read_bytes()).hexdigest())
        print('Prepared ' + row['name'], flush=True)
        return result
    with concurrent.futures.ThreadPoolExecutor(max_workers=4) as pool:
        prepared = list(pool.map(prepare, rows))
    (folder / 'media.json').write_text(json.dumps(prepared, ensure_ascii=False, indent=2) + '\n')
    print('Prepared %d previews and first frames' % len(prepared))

if __name__ == '__main__': main()
