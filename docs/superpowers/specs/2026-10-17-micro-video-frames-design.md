# 微视频首尾帧一致性扩展

Authority: user approved the full in-chat design and explicitly requested implementation on 2026-10-17, including admin, PC, mobile, every theme and real end-to-end acceptance with the existing woman/blue bottle. No new approval gate is required for this authorized work. Preserve unrelated work, local feature checkpoints -> local develop before runtime checks; no remote develop/master push or release.

## Product contract
- Planning left brief has 返回继续创作. Retain inputs and existing task. Back never cancels; completion of a detached task cannot replace a newly edited form. Same unchanged pending brief reopens original work rather than paying again. Unknown admission keeps the same idempotency key.
- Confirmation copy: 本次微视频创作共由{count}段视频组成，计划总时长{seconds}秒。 Final actual duration notice remains.
- New task models have callable start_end capability and valid specifications. Legacy tasks retain frozen reference-mode behavior. Runtime availability and permission/pricing still apply.
- At confirmation choose reference (default) or start_end. Opt-in alone never generates or bills. Start/end suits few references and continuous scenes; clearly warn for many subjects/scene changes.
- N video segments share N+1 boundary image versions. Segment i uses boundary i and i+1; previous tail and next head MUST refer to the exact same managed file/version.
- Generate image boundaries sequentially, using relevant original references and previous generated anchor plus common identity/scene/light/framing/scale instructions within actual model limits. Never silently drop original identities/products. First image establishes shot geometry. No unsupported product claims.
- Each frame has editable full static prompt and selected source image indices. Static frame prompt and timed video prompt agree on endpoint state. Keep 0-3秒 etc video timelines.
- Quote before image submit and video submit independently. Images are paid per actual generated boundary, not per displayed slot. User may regenerate a single image with a new paid attempt. Known failure retry only that image. Unknown provider results query/resume without another paid submit. Successful images retained.
- All frames must be current/success/confirmed to submit start_end video. Quotes bind task/plan revision/frame revision/mode/model/image version identities. Editing invalidates affected frames/confirmation/quotes and dependent anchors, not unaffected successful media. Video admission freezes all requests. Video failure retries only that segment using its frozen frames; merge failure reruns merge only.
- Generating frame set locks that plan's mode/content. Return to another creation remains available. No cancel/refund promise for already issued work.
- Public running/history remains whole-work UX. At confirmation users see full per-segment details and first/last previews. Admin sees execution/billing details. Tenant/user isolation and managed asset ownership mandatory.
- Theme tokens, en/zh-TW PC localization, distinct mobile layouts, safe areas, keyboard/focus and reduced motion.

## Frozen frontend/backend contract
Config user additions:
`frames_enabled:number`, `frame_models:FrameModel[]`, `default_frame_model_id:string`, `default_frame_quality:string`.
FrameModel `{id:string,label:string,max_reference_images:number,specs:Array<{quality:string,ratio:string,user_charge_points:number,market_sku_id?:number}>}`. Expose only actually usable multi-reference image/edit models with prequotable reserved billing; don't hardcode vendors.
Video model additions `generation_modes:string[]`, `supports_first_last_frame:boolean`; preserve existing fields. Eligible v2 models must support start_end plus current reference mode. Keep old models available to legacy task resolution, distinguish user new-task list from legacy runtime resolver.
Admin config additions `frames_enabled`, `frame_model_ids:string[]`, `default_frame_model_id`, `default_frame_quality`, `frame_template:string`; admin detail includes `available_frame_models` plus previous existing config.
MicroTask additions: `generation_mode:'reference'|'start_end'`, `frame_status:'off'|'idle'|'generating'|'review'|'ready'|'waiting_continue'`, `frame_model_id:string`, `frame_quality:string`, `frames_revision:number`, `frames:Frame[]`.
Frame `{id:number,boundary_index:number,version:number,prompt:string,reference_indices:number[],status:'pending'|'running'|'success'|'failed'|'stale',image_url:string,confirmed:boolean,user_charge_points:number}`. No raw provider error in user DTO. Additional admin-only billing/attempt details allowed.
Segment additions `first_frame_id:number,last_frame_id:number` in confirmation detail. Bind authoritative IDs server-side, ignore browser-supplied image URLs. Shared boundary represented as one frame row/version used twice.
POST endpoints under existing `/api/app.aigc_micro_video.*` (same declared API schema/permissions conventions):
- `frame/configure` `{id,plan_revision,generation_mode,frame_model_id?,frame_quality?}` -> MicroTask.
- `frame/save` `{id,plan_revision,frames_revision,frame_id,prompt,reference_indices}` -> MicroTask.
- `frame/estimate` `{id,plan_revision,frame_ids?:number[]}` -> `{user_charge_points,frame_count,frames_revision,quote_token,approximate:false}`.
- `frame/generate` `{id,plan_revision,frames_revision,quote_token,idempotency_key,frame_ids?:number[]}` -> MicroTask. Omit ids for pending missing set; explicit successful frame id requests a NEW image version. Replay same key returns same admission.
- `frame/confirm` `{id,plan_revision,frames_revision}` -> MicroTask, confirms current successful set atomically.
Frame generation continues while main status `awaiting_confirmation`; poll `frame_status==='generating'`. Exact video estimate/submit existing endpoints honor selected mode and frame readiness. Frame update returns new frame/plan revisions as authoritative; clients always use returned values.

## Persistence and runtime
- Extend task with mode/frame state/model/settings/revisions/admission/quote snapshots. Extend segment with authoritative first/last frame IDs; stable segment IDs on ordinary save (no delete/reinsert when matching).
- New tenant/user-owned frame table has logical boundary index, immutable version/result URI+storage metadata, prompt/reference/dependency fingerprints, status, confirmed, latest marker. Keep old versions for audit; pending job cannot overwrite newer selected version.
- Attempts can represent frame execution (frame_id indexed in execution uniqueness) or reference core image task/consumption without duplicate billing. Use shared MarketImage runtime/PointService and managed AigcImageAssetService. Never debit balances directly, never trust a browser price.
- No DB locks during provider HTTP/media work. Durable attempt reservation and linking must be atomic/recoverable. Retain receipt before persistence; storage retry must not resubmit successful upstream job. Worker leases/flock and terminal idempotency remain.
- App install SQL + idempotent upgrade migration, manifest/schema/permissions/changelog parity. Existing tasks default reference and frames off; new feature snapshot flag prevents applying new model restrictions to legacy frozen tasks.

## Acceptance
Meaningful service/controller tests for shared boundaries, stale revisions, ownership, duplicate/uncertain admission, selective invalidation, quote binding, legacy retry and mode-gated payloads. Real worker + managed images + 2 videos + merge, with injected recoverable failure BEFORE provider call for one frame and one segment to prove selective retry (never fake a charge/refund or rewrite successful state). Also actual single-image regeneration with visible quote if needed. Snapshot attempt/consumption counts before/after retry. Verify media using ffprobe, sampled end/start frames and Chinese ASR; do not claim seamless unless visual evidence supports it. Keep first failed sample and fix actual defects; report residual model limitations honestly.
