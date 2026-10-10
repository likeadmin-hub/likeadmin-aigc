# Short-drama Agent decision candidate

This is a staged replacement of dialogue orchestration, not a replacement of PHP production services.

## Architecture

`user message + scoped memory + frozen workflow → one decision → PHP validation → existing transactional executor → durable reply/events/card`

- `ConversationAgentDecision` owns the model-neutral proposal contract and adapts to existing validated execution contracts.
- `ConversationAgentMemory` retains up to 32 source-backed notes. A note cannot invoke tools or confirm production settings. It is not unlimited semantic recall; model extraction is still fallible.
- A card is an explicit decision (`show_card`), only eligible with a story direction. Once shown it stays anchored to its first assistant run.
- Production tools, task identities, billing, tenancy, revision checks, confirmations and task recovery remain in PHP.
- `execution=pause` handles mixed requests such as “one episode, 60 seconds, don't generate yet” without losing the newly supplied requirements.
- One response remains one metered market call. SDK loops are not authorized to bypass the billing adapter.

## Two adapters, different readiness

1. **PHP JSON decision adapter**: integrated in `ConversationWorker`, using the existing market provider and SSE reply stream. Only server-configured `canvas_agent.dialogue_engine=decision_v1` is frozen into NEW threads. Default is legacy. Existing threads and queued runs do not change engine. To roll back new threads, remove this config field; do not rewrite in-flight snapshots.
2. **Deep Agents candidate (`harness.py`)**: actual SDK graph with checkpoints and a single `propose_decision` tool. Built-in file, shell and delegation tools are excluded and blocked. The SDK emits the same proposal shape. It has no production credentials, DB connection or billing adapter. **It is not the live engine and is not deployed as an HTTP service.** Its offline replay checks SDK mechanics, not real model reasoning.

The Python SDK must use a server-authorized metered model adapter before any live deployment. No direct API key or unmetered provider is configured here. Checkpoints are in-memory for offline testing only; production would require tenant-scoped durable storage and operational recovery.

## Validation

Follow AGENTS.md: commit on feature, merge locally, run on local develop.

PHP: `SHORT_DRAMA_DB_TESTS=1 php vendor/bin/phpunit --filter 'CanvasAgentDecision' tests/Feature`

Python (use an external temporary venv; do not commit installed packages):

```
python3.12 -m venv /tmp/short-drama-agent-venv
/tmp/short-drama-agent-venv/bin/pip install -r agent_runtime/short_drama/requirements.txt
/tmp/short-drama-agent-venv/bin/python -m unittest discover -s agent_runtime/short_drama -p 'test_*.py' -v
```

PHP tests replay greeting → preference → start → story → advice → mixed update/pause → conversation → resume through enqueue, Worker, executor, message/events and read APIs. They assert one reply per run, idempotency, no generation task, stable cards, saved memory and no workflow mutation on chat. Transactions roll back isolated tenant fixtures.

## Acceptance before rollout

Use real-model dialogue evaluation only after separately authorizing model cost. Evaluate existing configured providers with the same conversations, including negation, corrections, ambiguous references, long chats, quoted instructions and missing images. Compare narrative with action, memory, UI and task state; successful HTTP/JSON is not sufficient. A failing case blocks rollout.

Retain ordinary short-drama production as the only authority for script/asset/storyboard generation. Neither model-generated prose nor a framework's generic tool can bypass three-view readiness, stage confirmation, manual video generation, or paid-task idempotency.

## Kimi acceptance — 2026-10-10

Authorization: Kimi only, at most 12 paid text turns; no image/video generation and no mutation of the original conversation. Local tenant 1, model ID 12 (`kimi-k3`), isolated canvas 417 / thread 834. The internal frozen evaluation allowlist excludes every other fallback model. The resumable harness counts prior runs against its 12-turn ceiling.

Exactly 12 conversation runs were submitted: 10 succeeded, 2 failed before the fix. Initial failures were `UNSUPPORTED_MODEL_RESPONSE` / `INVALID_AGENT_INTENT`: the model chose `update_requirements` after a natural clarification, while no project draft existed yet. The repair exposes one `configure_project` operation to the model and lets the server select create/update from the frozen workflow state. It does not relax phase eligibility or enable generation tools. After this repair, seven successive real turns succeeded.

Verified in real replies and durable state:
- Greeting and response-length preference did not start production; the preference was retained with user evidence.
- A story premise became the draft story without concatenated greetings.
- Aspect-ratio advice did not set an unchosen ratio.
- One episode / 60 seconds and pause were applied together.
- The model recalled the story, episode count and duration correctly.
- Changing duration to 90 seconds retained other facts and the paused state.
- Resume cleared pause while missing requirements still prevented production.
- Explicit 16:9 plus a request for remaining choices produced a card containing only `visual_style`; the other four fields were not asked again.
- No canvas nodes or production stages were created by this evaluation.

Two harness assertions were corrected without counting them as product defects: a natural clarification need not start a project immediately, and `一集` is a valid semantic equivalent of `1集`. The paid budget includes those turns and both rejected attempts; no further live calls are authorized by this run.

Regression result: 108 PHP tests / 701 assertions passed in the focused dialogue, state, workflow and provider suite; 3 actual Deep Agents SDK offline graph tests passed. A broader existing Canvas suite still has 12 errors from missing hard-coded compiled frontend filenames and one model-row ordering failure; those are not a passing full-suite result.

Rollout decision: keep tenant defaults on `legacy`; only the isolated evaluation thread uses `decision_v1`. Seven post-fix turns are not evidence for long-context reliability, every production stage, or untested models. No production Deep Agents service has been activated. Further live testing requires a new explicit cost authorization; offline regression remains available without it.
