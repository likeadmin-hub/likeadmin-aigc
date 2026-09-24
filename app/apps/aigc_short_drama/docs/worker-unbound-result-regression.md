# Refunded unbound result queue repair — 2026-09-22

## Root cause

Local tenant 1 result job 3268 (`process_result`, consumption 992) repeatedly
returned false from terminal business synchronization. Consumption was already
`failed / refunded` with error `timeout`; linked app task 971 was
`script_parse_validation`, `aigc_short_drama_script_task`, business_id=0.
No script row referenced app_task_id=971. This is a missing write-back target,
not an in-progress Provider request. The worker polled every five seconds,
accumulating more than 14,800 attempts.

## Change and boundary

Source commit: `6a5302ed5`, server feature/short-drama-optimization, integrated
into local develop before testing and Worker restart. No web changes.

- Known business adapters with no bound target may finish result processing
  only when the call failed/canceled AND billing is already refunded.
- Successful calls, pending settlement, bound targets, missing app links and
  unknown adapters retain their previous behavior.
- Result-processing waits now share the query-job bounded 5–60 second backoff.
  This does not fail, cancel, refund or discard a business task.
- No API/schema/config changes, migration, Provider submit or manual task-state
  mutation. No remote deployment or push.

## Verification

- PASS: AiTaskUnboundResultTest, 3 tests / 65 assertions; terminal/billing/target
  combinations, unknown/missing links, and query/process backoff boundaries.
- PASS: focused regression including MarketImageSubmitRetryPolicyTest,
  ShortDramaRepairResultGuardTest and AiTaskModelDisplayContractTest:
  13 tests / 92 assertions including the new test.
- PASS: actual terminal synchronization of consumption 992 returned true.
- PASS: after checking running queues were empty, gracefully reloaded the
  existing managed Worker group. New result Worker PID 1270911 processed job
  3268 to success at attempt 14857 through the normal queue path.
- PASS: consumption 992 remained failed/refunded/timeout and its update_time
  remained 1789911784; no repeat settlement/refund performed by this repair.
- FAIL (environment prerequisite): expanded 18-test run had one error in
  AiTaskFilterContractTest::testAdminBundleSendsAllNewFilterParameters because
  `public/admin/assets/index-BmV1gMRX.js` was absent. Do not count this as PASS;
  the test depends on a specific generated frontend bundle, unrelated to the
  result queue change. No compiled output was generated or committed.
- Existing suite-load warning: unused global ReflectionMethod import in
  PlatformTenantPowerConsumeContractTest. Not changed in this task.
- NOT_RUN: paid Provider generation and remote production checks; neither is
  needed to reproduce or validate this local post-refund queue defect.

Queue-job success means post-processing is finished; it does not mean the
original timed-out generation succeeded. No pending P3 gate is waived here.
