# Uploaded script final model fallback

The user requested one internal model pass as the final fallback when a screenplay parse is incorrect or fails the required contract. This extends the already authorized real upload-to-production verification.

The normal `file_qa/parse` API remains the first path. Freeze extracted source bytes and the user's selected script model at upload. On a completed parse whose output fails structural, source-boundary, or per-episode timing validation, atomically queue one background model repair. Its prompt includes the original source, original parse receipt and validation reason as data. Rebuild source text only from validated document line ranges. Revalidate the resulting outline and timing before publishing it through the existing story/outline confirmation flow.

Approach: reuse the durable planning-unit and text runtime contracts. A synchronous paid retry during polling would block requests and make duplicate billing harder to prevent; resubmitting the same upstream parser does not provide a separate repair contract. The single background text-model pass fits the requested final fallback. API transport failures, cancellation and uncertain settlement are preserved as existing failures; they do not trigger additional paid requests.

A task has at most one fallback receipt. Repeated polls and worker delivery reuse it. A failed repair remains failed with both original and repair evidence; no second automatic repair. The primary upstream task and consumption association remain intact, and displayed cost includes both calls. Source text and raw repair diagnostics stay private. Existing tasks without a frozen fallback source keep their current behavior.
