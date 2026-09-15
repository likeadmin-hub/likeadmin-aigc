<?php

namespace app\common\service\app\aigc_short_drama\canvas;

/** Independent transport/billing boundary for future canvas-only providers. */
interface CanvasExecutionProviderInterface
{
    public function isReady(): bool;
    public function unavailableMessage(): string;

    /** @return array<string,mixed> Must contain a settled billing reference before drafts are applied. */
    public function invokeAgent(array $request): array;

    /** @return array<string,mixed> Must be quoted before a paid media submission. */
    public function quoteMedia(array $proposal): array;

    /** @return array<string,mixed> One idempotent media submission only. */
    public function submitMedia(array $request): array;

    /** @return array<string,mixed> Reconciles an unknown provider submission without retrying it. */
    public function reconcileMedia(array $request): array;
}
