# Canvas video frame capture

The PC workbench can fall back to server-side extraction when an existing managed video cannot be exported through a browser canvas. This operation extracts a still image and does not call an AI provider or point-deduction service.

`POST /api/app.aigc_canvas.generate/captureFrame` accepts `project_id`, `node_id`, and finite numeric `time` in seconds (0–28,800). The saved, undeleted project must belong to the current tenant and user, and the specified node must be a video. Save and await the canvas snapshot before requesting a newly added video node. The request cannot supply a source URL or storage configuration. The service resolves the saved node's managed URI and independently requires an undeleted video upload owned by that tenant/user or their undeleted video-generation result. Editable node metadata alone never grants file access. Source storage comes from that server record and server configuration, never node scope/engine/domain. Sources without verifiable file ownership are rejected with a re-upload instruction. Legacy `videoNode`/`data` and media URL aliases remain supported.

The response contains `uri`, authorized `url`, `width`, `height`, `mime_type`, `file_size`, `storage_scope`, `storage_engine`, and `storage_domain`. The resulting image is registered as a tenant file owned by the current user. Failed extraction creates no output file record; the frontend retains its original source and marks its pending derived node as failed with the server reason. The derived node and its source connection remain undoable together.

This reuses `AigcVideoPosterService::createFrame`. FFmpeg, PHP process execution, a writable runtime directory, and access to the configured source/output storage are required. Existing download and extraction time/size bounds apply. No storage CORS setting, schema migration, worker configuration, or paid-generation change is needed. Unknown external/provider URLs and transient blob/data URLs are intentionally unsupported by the fallback.

Run only from local `develop` after integrating the feature commit:

```sh
php app/apps/aigc_canvas/tests/run_frame_capture_contract.php
php app/apps/aigc_short_drama/tests/canvas_frame_media.php
php -l app/common/service/app/aigc_canvas/AigcCanvasFrameService.php
php -l app/api/controller/app/aigc_canvas/GenerateController.php
```

The contract test uses in-memory stubs with no database, storage, FFmpeg, or paid-generation calls. The existing media test needs FFmpeg and uses temporary generated video files, without database or storage writes.

## Restricted PHP runtimes

FFmpeg detection and extraction use `proc_open` with a bounded deadline, without requiring `exec`, `shell_exec`, or `system`. A missing process capability now reports a media-process configuration error separately from an unavailable FFmpeg binary. Extraction invokes FFmpeg directly as an argument array, so timeout terminates the decoder rather than only a shell wrapper.

If the website disables process execution, preserve its restrictions and run a **separate FPM master** for the authenticated capture endpoint. Do not relax the global website PHP configuration or route the entire site to that master. `scripts/canvas-frame-fpm.sh` combines the FPM and CLI restriction lists and removes only `proc_open`, leaving all other disabled functions in place. It fails closed if the FPM restrictions cannot be read. The dedicated FPM configuration must contain only its own bounded pool, Unix socket, pid/log paths, and the existing unprivileged site user. Example pool (replace paths/user for the deployment):

```ini
[global]
pid = /run/canvas-frame-fpm.pid
error_log = /var/log/canvas-frame-fpm.log
[canvas-frame]
user = www
group = www
listen = /run/canvas-frame.sock
listen.owner = www
listen.group = www
listen.mode = 0600
pm = ondemand
pm.max_children = 2
pm.process_idle_timeout = 10s
pm.max_requests = 100
request_terminate_timeout = 360s
security.limit_extensions = .php
```

Start it through the existing process supervisor with `CANVAS_PHP_BIN`, `CANVAS_FPM_BIN`, and `CANVAS_FPM_CONFIG` pointing to the intended PHP version and dedicated configuration. In the intended site's Nginx configuration, route **only** the exact `/api/app.aigc_canvas.generate/captureFrame` location to this socket. Set `SCRIPT_FILENAME` to that site's `scripts/canvas-frame-entry.php`, retain standard FastCGI request parameters, and allow a 360-second read timeout. This entry accepts only POST requests to that exact route, pins the ThinkPHP routing parameter, then uses the normal application bootstrap and authentication. All other locations keep their original PHP socket. Tenant-aware pages still call this canonical API with the existing tenant header/query context. Check configuration and unauthenticated rejection before browser acceptance. These are operator-local settings, not automatic app migrations.

OSS need not allow browser CORS: the existing fallback reads only the server-authorized file and saves a new image with the caller's storage and ownership metadata. Never add a wildcard CORS rule or change another tenant's bucket to make extraction work. The frontend waits for the source snapshot to save and refuses extraction after a failed save or project switch. Its request timeout accommodates the bounded download and decode operations, with automatic retries disabled.

## Previous local acceptance limitation (2026-10-16)

The local `s.cn` PHP 8.2 configuration disables `exec` and `proc_open`. FFmpeg is installed, but that website runtime cannot invoke it. The user explicitly chose to preserve this configuration and accept source delivery with this limitation. No PHP, storage CORS, or site security configuration was changed.

The ownership/contract suite passed all 7 groups and PHP syntax checks passed. The existing real-media FFmpeg suite passed 8 checks in an isolated CLI invocation with `-d disable_functions=`; this command affected only that process and does not establish website runtime availability. Browser testing confirmed the fallback fails while preserving the source video. End-to-end successful extraction through the website remains unverified in this environment. No paid generation was invoked.

## Local acceptance (2026-10-17)

The new request to resolve capture was validated with an isolated FPM master for the local site's capture endpoint. The ordinary PHP process retains its disabled `proc_open` and `exec`; the dedicated master retains disabled `exec` and grants only the necessary `proc_open`. FFmpeg was already installed, so no binary or package installation was needed. OSS still returns no browser CORS allowance, and its configuration was not changed.

On localhost project 58, the existing stored 640×480, 5.802667-second video produced three durable 640×480 images: opening frame, tail frame, and the paused current frame at 2.87115 seconds. The results were named, moved apart and reloaded successfully. The original source remained present; historical failed captures were retained rather than rewritten. No generation endpoint was invoked and the displayed points stayed unchanged.

The seven authorization/ownership contract groups passed, including cross-tenant/user rejection and legacy node aliases. Ten real-media/process checks passed with `exec` disabled, including distinct first/current/tail outputs, audio extending beyond video, exact opening/last frame comparison, out-of-range rejection and process timeout. The dedicated HTTP entry rejects non-POST/other-action requests, unauthenticated calls still require a token, and a forged `s` query cannot select a different action. Frontend regression tests also cover save failure, pending drafts, and project switches before frame submission.

The dedicated FPM and Nginx/Supervisor settings are local operator configuration and are intentionally not delivered as machine-specific repository files. Deployments with PHP process execution disabled need the isolated media runtime described above; pulling source alone does not change their PHP restrictions. This acceptance does not authorize a release.
