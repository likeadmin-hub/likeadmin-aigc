# Avatar seconds and application availability

## User request

Remove the redundant “历史上传” link under the digital-human upload empty state.
Verify digital-human generation uses seconds and repair any count/fixed/rounded
quantity path. Every application tool needs a usable switch; closed tools are
absent from PC/mobile entrypoints and sidebars, and direct links display
“当前应用已关闭”.

## Decisions from existing contracts

- Reuse platform app status installed/disabled and tenant app shelf on/off; do not
  create a conflicting switch. Only system_default is mandatory framework state.
- Defaults must not override an explicit close during config requests or upgrades.
  Owners retain backend management access to reopen apps. Completed/pending task
  polling and existing billing reconciliation remain available after a close.
- Availability is separate from login, membership and network failure. Unknown or
  failed catalogue requests show retry rather than falsely claiming an app closed.
- Keep fractional actual seconds in quotes, reservation, usage and stored billing
  quantity. Unknown measured duration cannot be accepted as authoritative usage.
  Conservative estimates may still be used before TTS completes.
- Existing monetary amounts and historical pricing remain unchanged; new tasks
  retain stable price/unit snapshots. Avatar/voice cloning remains its own action.
- PC and UniApp share effective app identities, not desktop visual structure.
  Mixed routes use explicit requested mode when supplied; a default route chooses
  an available mode without falling back to a closed application.

No production writes, deployment, paid inference or release is part of this repair.
