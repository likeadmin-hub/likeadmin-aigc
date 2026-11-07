# WeChat Mini Program upload size

The normal MP production build checks the whole emitted tree: main source <= 1900 KiB and each subpackage <= 2048 KiB. Main source headroom is intentional: the official CI compiler adds helpers and wrappers, so raw source bytes do not equal upload bytes. Only the exact root `.artifact.meta.json` is excluded from source totals; full integrity validation remains mandatory on formal artifacts.

Both manual and platform draft upload scripts must exclude `.artifact.meta.json` and node_modules through `ci.Project.ignores`. Retain the inventory on shared artifacts and keep authentication fail-closed before compilation/upload.

Before release, also run the official miniprogram-ci `getCompiledResult` with the same upload settings (`useProjectConfig`, `es6`, `es7`) and one worker, without calling upload. Sum compiled file bytes under the longest matching subpackage root; other files belong to main. Require each package <= 2 MiB and record SDK version and exact bytes. Default project attributes may be supplied solely to this offline compilation diagnostic; this is not an authenticated upload or upload-success proof. Never inject that fallback into the production uploader.

SDK 2.1.31 reproduces the 1.0.432 failure: compiled main 2,334,096 bytes, including the 195,144-byte internal inventory. The raw-only main measurement of 1,968,701 bytes omitted that inventory and CI transformations.

Shared workbench components retain their original shared paths. Unreferenced legacy login icons and background images were removed after checking source and install defaults. Business entry points and current icons remain available.
