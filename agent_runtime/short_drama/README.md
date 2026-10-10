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
