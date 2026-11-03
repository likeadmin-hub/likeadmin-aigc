#!/usr/bin/env python3
"""Rebuild pinned source evidence from already-present Git objects, without a checkout.

No network, source PHP execution, configuration changes, database or activation.
Missing objects fail closed: obtain authorized source objects separately first.
"""
import hashlib
import json
import os
from pathlib import Path
import subprocess
import sys

REVISION = "b5887e1c153111edd4515beb0635e6b66d9978b6"
REPOSITORY = "https://gitee.com/yc_open/platform-api-saas.git"
EXPECTED_ARTIFACT_SHA256 = "807c75616b0fb7b7225c2814645360b8f9ea8e6e7371d1267540aefe373870db"
MAX_BYTES = 8 * 1024 * 1024
PATHS = [
    "api-doc/model-api/model-63-nano-banana-2.md",
    "api-doc/model-api/model-66-nano-banana-pro.md",
    "api-doc/model-api/model-71-grok-video.md",
    "api-doc/model-api/model-72-veo3.1-fast.md",
    "api-doc/model-api/model-73-veo3.1-pro.md",
    "api-doc/model-api/model-94-gpt-image-2.md",
    "api-doc/model-api/model-96-gpt-image-2.md",
    "api-doc/model-api/model-gpt-image-2-fast.md",
    "api-doc/model-api/model-gpt-image-2-pro.md",
    "api-doc/model-api/model-h3-video.md",
    "api-doc/model-api/model-q4-preview-video.md",
    "api-doc/model-api/model-qwen-image-3.0-pro.md",
    "api-doc/model-api/model-qwen-image-3.0.md",
    "api-doc/model-api/model-wan3.0-video.md",
    "api-doc/model-api/nano-banana-2.1.md",
    "api-doc/online-developer-docs/manifest.json",
    "server/app/api/controller/v1/TaskController.php",
    "server/app/common/command/BailianWanQwenMigrate.php",
    "server/app/common/command/DakkaGptImage25Migrate.php",
    "server/app/common/command/FixNanoBananaDrawUpstreamMapping.php",
    "server/app/common/command/GptImage2OpenaiMModelParamsMigrate.php",
    "server/app/common/command/GptImage2UserParamsAlignMigrate.php",
    "server/app/common/command/GrokVideoMigrate.php",
    "server/app/common/command/MinimaxH3Migrate.php",
    "server/app/common/command/OpenAIYIGptImage2Migrate.php",
    "server/app/common/command/OpenaiDGptImage2FastMigrate.php",
    "server/app/common/command/OpenaiMGptImage2ProMigrate.php",
    "server/app/common/command/QwenImage3Migrate.php",
    "server/app/common/command/VeoVideoMigrate.php",
    "server/app/common/command/ViduQ4Migrate.php",
    "server/app/common/service/ai/DeveloperDocPublicService.php",
    "server/app/common/service/ai/ModelRouterService.php",
    "server/app/common/service/ai/ParamsSchemaValidateService.php",
    "server/app/common/service/ai/app/SeedanceDriver.php",
    "server/app/common/service/ai/driver/DashScopeQwenImageDriver.php",
    "server/app/common/service/ai/driver/DrawDriver.php",
    "server/app/common/service/ai/driver/OfficialTaskDriver.php",
    "server/app/common/service/ai/driver/VideoTaskDriver.php",
    "server/app/common/service/ai/driver/ViduQ4VideoDriver.php",
    "server/app/common/service/ai/official/OfficialCatalogService.php",
    "server/app/common/service/ai/official/OfficialMediaService.php",
    "server/app/common/service/ai/official/OfficialSkuPolicy.php",
    "server/public/install/db/ai_platform_migration_nano_banana_21.sql",
    "server/public/install/db/ai_platform_migration_vidu_q4.sql"
]


def main():
    if len(sys.argv) != 2:
        raise ValueError("Usage: capture-owned-platform-source-evidence.py git-object-directory")
    directory = Path(sys.argv[1]).resolve(strict=True)
    if not directory.is_dir() or not (directory / "objects").is_dir():
        raise ValueError("Expected existing Git object directory")
    env = dict(os.environ, GIT_NO_LAZY_FETCH="1", GIT_TERMINAL_PROMPT="0", GIT_NO_REPLACE_OBJECTS="1")
    command = ["git", "--no-replace-objects", "--git-dir=" + str(directory)]

    def read(*args):
        result = subprocess.run(command + list(args), check=False, stdout=subprocess.PIPE,
                                stderr=subprocess.DEVNULL, env=env, timeout=30)
        if result.returncode or len(result.stdout) > MAX_BYTES:
            raise ValueError("Pinned local Git object unavailable")
        return result.stdout

    if read("cat-file", "-t", REVISION).strip() != b"commit":
        raise ValueError("Pinned commit unavailable")
    files = []
    total = 0
    for path in PATHS:
        data = read("show", REVISION + ":" + path)
        total += len(data)
        if total > MAX_BYTES:
            raise ValueError("Pinned evidence exceeds bound")
        blob = read("rev-parse", REVISION + ":" + path).decode("ascii").strip()
        if hashlib.sha1(b"blob " + str(len(data)).encode("ascii") + b"\0" + data).hexdigest() != blob:
            raise ValueError("Git blob mismatch")
        files.append({"path": path, "git_blob": blob,
                      "sha256": hashlib.sha256(data).hexdigest(), "content": data.decode("utf-8")})
    evidence = {"version": 1, "repository": REPOSITORY, "revision": REVISION, "files": files}
    output = (json.dumps(evidence, ensure_ascii=False, indent=2) + "\n").encode("utf-8")
    if len(output) > MAX_BYTES or hashlib.sha256(output).hexdigest() != EXPECTED_ARTIFACT_SHA256:
        raise ValueError("Pinned source evidence mismatch")
    sys.stdout.buffer.write(output)


if __name__ == "__main__":
    try:
        main()
    except Exception:
        print("Source evidence capture failed: verify the pinned local Git objects privately. No source executed or network requested.", file=sys.stderr)
        sys.exit(1)
