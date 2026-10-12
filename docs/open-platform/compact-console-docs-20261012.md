# Compact request details and public documents: local acceptance

Scope: request/task detail presentation and empty public-document categories. This
record does not complete the overall P0–P7 open-platform matrix or authorize a release.

## Verified source and local runtime

- Web executable commit: `8978f2ea` (compact details and document selection originally
  `bba8d6af`). Server executable commit: `ce08f0630` (read-only projections originally
  `86a0ad3d4`). Both feature checkpoints fast-forwarded local develop before preview.
- Isolated SPA output: `/private/tmp/open-platform-compact-doc-final-output`.
  Local Node preview uses port 19876 behind existing loopback bridge 19875.
  No public deployment, remote push or permission publication took place.

## Findings and changes

- Ordinary API Reference opened without a selected operation, producing an empty
  main column despite a nonempty directory. It now selects the first matching
  published operation. Unknown explicit contract links still fail closed.
- Shared-path variants were absent from search and separate navigation. Each variant
  now has its own entry, and model/name/code/path search selects its exact identity.
  The model selector offers only search-matching variants; clearing search retains
  a valid selected model. Latest request and tenant own data and OpenAPI Blob URLs.
- Local tenant 1 had twelve nonempty published documents: seven general guides plus
  five operation guides. Its pricing and changelog categories had zero authored
  publications. Missing categories now receive labelled read-only generated content
  from currently visible published contracts. Existing authored content has priority.
- Pricing includes only SKU IDs actually bound by those contracts and current tenant
  retail/unit data. Versions show current contract version and recorded time, with
  an explicit explanation that this is not complete software-release history.
- Blank operation summaries use a bounded prose introduction from their already
  published guide. Stored contract config, digest and typed examples are unchanged.
- Request details use 680px compact shared dialog, with Overview, Billing and ledger,
  and Customer callbacks tabs. New requests and closing reset to Overview; stale
  reads/replay results cannot replace another request. Values remain unchanged;
  displayed monetary values use two decimals. Key/capability dialog widths stay as
  before. Document search consumes the current theme rather than a white default.

## Evidence

- 88 frontend focused tests passed: `/tmp/open-platform-compact-doc-final-tests.log`.
  Subsequent document-theme polish passed 20 applicable tests in
  `/tmp/open-platform-compact-doc-polish-tests.log`.
- Three changed Vue files passed script/template/style compilation. Exact source
  snapshot strict UI audit: zero findings, `/tmp/open-platform-compact-doc-audit.json`
  with hash inventory `/tmp/open-platform-compact-doc-audit.sources.json`.
- Production SPA build passed: `/tmp/open-platform-compact-doc-final-build.log`.
  Existing full-repository semantic type-check limitations are not represented as
  resolved by these scoped checks.
- Eight focused PHP scripts and four PHP syntax checks passed:
  `/tmp/open-platform-public-documentation-verification.log`. Tests cover public
  documents, typed examples, public contracts, economic response boundaries and a
  full isolated installation with repeated migrations. Heading polish re-ran the
  read-only document fixture and PHP syntax. New test is registered in serial runner.
- Read-view fixtures roll back and compare real published config hashes/digests,
  document rows and request counts. They do not publish a contract, generate a task,
  change actual prices, create Keys or mutate a real wallet.
- Logged-in browser: 847x853 dialog actual surface 680x461; at 390x844 it was 358x506,
  x=16, with no page overflow. Billing tab displayed real 20.00 debit plus its
  independent existing ledger. ArrowRight switched to callbacks; Escape returned
  focus to the originating View button. Reopening another request reset Overview.
- Browser docs showed automatic API正文, separate GPT Image / Nano entries, exact
  GPT search, no-match explanation and clear-search recovery. Pricing showed five
  published SKU references (TTS 50/1000 characters, image 20 and 6 per success,
  lyrics 12/call, style 14/call). Version rows displayed actual contract timestamps.
- Screenshots: `/tmp/open-platform-compact-request-20261012.png` and
  `/tmp/open-platform-current-pricing-doc-20261012.png`. Existing tabs were reused,
  temporary viewport override reset, and no paid API request was sent in this task.

## Remaining scope

Only five published contracts (four protocol paths, two models sharing /tasks) are
currently visible in the local public API reference. Registered/sellable draft
capabilities are not silently published by a documentation fix. The two music
operations have request parameters and response schemas but no explicit wire
response examples; the panel explains their absence instead of inventing bodies.
The full interface/tool acceptance and release matrix remains open.
