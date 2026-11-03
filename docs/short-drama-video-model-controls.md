# Storyboard video model controls

Storyboard estimate and generation normalize advanced controls through `MarketCreationParameters`, the same validator used by standalone video creation. Both top-level controls and the historical nested `params` envelope are supported; the top-level value remains authoritative. Nested SKU identity is included in control validation.

Prompt assembly retains native provider options and the validated flat mirrors required by legacy adapters. The assembled prompt, model selection, resolution, duration and server-resolved references remain authoritative. A storyboard task explicitly defaults `multi_shots` to false because it represents one shot; an explicit choice is retained, SKU locks still win, and models that do not declare this option never receive it on the wire. This fixes missing required single-shot choices without maintaining model-name exceptions.

No API path, permission, database schema, pricing, refund, storage, task status or frontend change is required. Existing saved provider requests are not rewritten. To roll back new-request behavior, revert the service integration and helper together; retain historical task, asset and consumption records.

## Verification on 2026-11-03

- The actual prompt assembler is exercised with native and legacy encoders, explicit false/zero values, nested input, supplier defaults, required controls, SKU locks and protected business fields. These tests never call a supplier or bill points.
- Focused regression covers storyboard reference roles, frame readiness and ownership, duration, audio, director prompts, model variants, shared video adapters and frozen receipt routing, including rollback-only database fixtures.
- A read-only catalogue audit prepared 613 supported model/mode/SKU combinations across all 46 video models and 4 application interfaces (383 SKUs). Video-input SKUs combined with modes that cannot carry video were excluded rather than submitted. This verifies local encoding and admission contracts, not supplier availability or generated-video quality.

## Separate upstream rejection investigation

The reported Seedance 2.5 storyboard request was rejected during upstream submission, before an upstream task identifier was issued. Standalone video creation and storyboard generation resolve the same product/SKU and shared submission interface. Four controlled standalone submissions reproduced the rejection: the original request, a prompt-only change, a reference-only change from that baseline, and a duration-only change from that baseline. All four returned the same generic upstream rejection and were refunded; recorded actual user and tenant costs are zero.

The generic response does not identify a content, image, duration or internal supplier failure. The model-control omission above is independently reproduced and fixed; it is not established as the cause of this Seedance rejection. No other model received a live test request, and no automatic model fallback or repeated paid retry was added.

## Confirmed frame-ratio rejection and reference-mode correction

The platform's stored exchange for the later failed attempt explicitly returned HTTP 422: Seedance 2.5 first-frame and first-last-frame tasks only support adaptive aspect ratio. The native contract now contains that conditional equality rule. It is exposed on frame-mode schemas and checked at the shared admission boundary; text and contextual references retain their declared ratios. The composed storyboard app adapts its preferred project ratio before prompt assembly and quoting, and returns the requested/resolved ratio and a notice. Saved project dimensions and historical receipts are retained.

A valid, supported tail selects `start_end` and exactly two frame slots. With no tail, two or more available contextual images prefer a declared `omni_reference` or `image_reference` mode. Required non-image inputs and timed keyframe fields cannot qualify as contextual images. Historical `multi_frame` image adapters remain supported. A lone image, or a model without suitable contextual-reference support, uses `image_to_video` when supported. Reference ordering remains current selected storyboard image, bound character turnaround/main image, bound scene, then explicitly mentioned storyboard images. The current image's asset ID remains present as a logical anchor even when its native role is `reference_image`; it does not become a first-frame URL in that mode.

Preview, quote and generation use this same mode decision and material set. The PC composer and batch dialog display complete server quote totals, invalidate stale quotes after edits, and keep actual-usage settlement explicit. Validation for these corrections uses offline encoders, read-only quotes and recorded receipts; no additional paid generation is required.
