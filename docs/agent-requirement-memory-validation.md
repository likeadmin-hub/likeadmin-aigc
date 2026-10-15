# Requirement recall and quality baseline — 2026-10-15

## Delivered scope

The first quality-focused optimization adds source-backed recall of older user requirements and a repeatable offline evidence baseline. It does not infer a new authoritative memory, approve tasks, or call an extra model to summarize history.

- `AgentContextService::requirements` retrieves current, authorized user messages in the same session/topic. Default keyword candidates cover constraints, decisions and corrections; literal searches also retrieve messages without those cues. Results include original message IDs, Unicode character offsets, lengths and truncation flags. Eight candidates per tool page, with a stable `next_before` cursor.
- `build` previews up to four older candidates outside the recent dialogue window. This complements the existing first/latest-request excerpts. Text around the matched cue can recover a condition in the middle of a long message; excerpts are at most 600 characters and explicitly partial when needed. Original text remains available through `read_message`.
- `AgentRequirementMemory` adds optional previews only when the existing context fitter retains the same dialogue/tool turns as before adding the preview. It reduces or omits previews if necessary, preserving retrieval through the tool. It does not lower the existing token budget or model choice.
- The existing `read_session_context` tool gains the additive `requirements` action. There is no new HTTP endpoint, frontend control, permission, schema migration, billing action, model protocol value or app-version change.
- Historical user text remains unverified source data. Instructions distinguish same-object corrections from unrelated project changes, quotes/hypotheticals from decisions, and historical confirmation from present execution authorization. No automatic fact overwriting or approval occurs.
- Sources are read fresh, so edits, deletion and topic/session boundaries apply immediately. No persistent copy or historical data rewrite is created.

## Fixed baseline and what it measures

`tests/fixtures/agent-quality-baseline.json` contains eight human-authored cases, required source evidence, expected-answer rubrics and forbidden inferences:

1. Old constraints in the middle of a long conversation.
2. Later date corrections invalidating earlier values without changing price/quantity.
3. Negation and written-authorization exceptions.
4. Separate project prices and first-payment amounts.
5. Quoted instructions that must not authorize execution.
6. A requirement in the middle of a long individual message.
7. A requirement without any predefined cue, retrieved by literal search.
8. A short ambiguous confirmation requiring prior context, not automatic approval.

Each database fixture places its target at message 41 among 120 historical user messages. The existing default history excerpts omit that target. All seven cue-bearing targets are recovered by the automatic preview; all eight are recovered by explicit literal search with exact source offsets. **These are fixture evidence-retention results, not real-model answer accuracy percentages.** The expected-answer rubrics are not simulated answers presented as model output. No paid inference was performed.

The actual Neuron/Agent chat test also confirms that an old, same-topic requirement reaches the model system input and that the new action executes through the real tool loop, while the transport is mocked and no generation step is created.

## Regression results

Executed on local `develop` after integrating the feature commits. **14 test files passed**:

- HomeAgentRequirementMemoryTest: all eight rubric sources, exact offsets, partial-source retrieval, 19-item pagination, literal wildcard escaping, live edits/deletion, assistant exclusion, tenant/user/session/topic isolation, optional-preview budget and tool-chain preservation.
- HomeAgentContextChatTest: actual Neuron loop, automatic requirement injection, requirement-tool response, document reading, focus, unchanged model and zero generation steps.
- HomeAgentContextTest, HomeAgentTopicTest, HomeAgentReadCacheTest.
- HomeAgentLongReadFidelityTest, HomeAgentLongReadTest (including 200,000 characters / 50 mocked segments).
- HomeAgentBillingRecoveryTest, HomeAgentPlanManagementTest, HomeAgentSequenceTest.
- HomeAgentExecutionBoundaryTest, HomeAgentResponseRecoveryTest, HomeAgentCapabilityIsolationTest, HomeAgentContractsTest.

Five changed PHP files were syntax checked; the updated tests also executed successfully. Initial new-fixture failures were corrected: a deletion assertion incorrectly expected no matches even though the current question still matched, and the chat fixture had unassociated historical messages outside its resolved topic. Isolation was preserved; no production bypass was added to make the tests pass.

All tests used rollback fixtures and disabled global curl/socket calls and URL file access. Source recall itself needs no supplier request. Existing paid confirmation, cancellation, safe retry, result recovery and settlement paths remain unchanged.

## Safety and activation

Read-only before/after snapshots matched for user/tenant wallets, consumption counts and financial fields, user and tenant ledgers, Agent run/step/artifact counts/status hash, queue states, leases and the STT receipt presence check. Historical review remains **one transcription record reserving 40 points**, with **41 pending_usage / 2 settlement_failed** text records preserved. No historical resubmission, backcharge, refund or repair was run.

After verifying idle leases and no eligible Agent/episode/planning/export tasks, the existing Supervisor group was gracefully stopped and restarted with local `develop` containing the repair. The new Agent process **352220** and supervisor program process **352024** were healthy after more than one minute of observation, with one Agent process and unchanged financial/historical snapshots. The ordinary existing result-processing queue retained its existing pending status; no new paid task was submitted. No production deployment or generated-output delivery was performed.

## Limits and future evaluation

Keyword candidates are a recall aid, not exhaustive semantic extraction. A missing match does not mean no requirement exists; literal search and original-message paging remain necessary. A short confirmation may be ambiguous, and matching text may be a quote rather than a decision. The system does not deterministically prove every answer obeys every requirement. A real-model answer-quality comparison remains pending a suitable authorized inference environment.

This iteration does not change document segmentation or introduce automatic semantic conflict resolution. The original long-read source/summary safeguards stay in place. No token saving is claimed; preserving useful evidence can increase input tokens. The optional preview gate protects dialogue retention under the existing estimator, not a provider-specific exact token count.

## Handoff

task: older requirement recall and offline quality baseline

branch: feature/agent-optimization

server scope: AgentRequirementMemory; AgentContextService/AgentExecutionService; fixed rubric fixture; recall/context-chat regression tests; this record

web scope: unchanged

contract changes: additive internal read_session_context.requirements action and source-pointer metadata; no HTTP API, migration, permission or billing changes

completed / remaining: source implementation, offline regression and local safe reload complete; real-model answer-quality scoring not performed

validation: 14 passing offline test files, source lint, before/after accounting checks, healthy reloaded worker

server commit: 5742ab0a6 (implementation), 7231b6be2 (corrected test fixtures); documentation commit recorded separately in Git

web commit: unchanged at 62eb965926873a16701a014e0fb1ffcba5cf5842

risks or blockers: bounded keyword recall is not exhaustive memory; real-model scoring needs an authorized inference environment; historical review records remain untouched
