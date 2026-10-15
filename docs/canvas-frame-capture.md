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

## Local acceptance limitation (2026-10-16)

The local `s.cn` PHP 8.2 configuration disables `exec` and `proc_open`. FFmpeg is installed, but that website runtime cannot invoke it. The user explicitly chose to preserve this configuration and accept source delivery with this limitation. No PHP, storage CORS, or site security configuration was changed.

The ownership/contract suite passed all 7 groups and PHP syntax checks passed. The existing real-media FFmpeg suite passed 8 checks in an isolated CLI invocation with `-d disable_functions=`; this command affected only that process and does not establish website runtime availability. Browser testing confirmed the fallback fails while preserving the source video. End-to-end successful extraction through the website remains unverified in this environment. No paid generation was invoked.
