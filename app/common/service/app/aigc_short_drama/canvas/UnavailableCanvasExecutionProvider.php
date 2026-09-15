<?php

namespace app\common\service\app\aigc_short_drama\canvas;

/** Fail-closed default. It contains no vendor credentials or fallback behavior. */
final class UnavailableCanvasExecutionProvider implements CanvasExecutionProviderInterface
{
    public function isReady(): bool { return false; }
    public function unavailableMessage(): string { return '短剧画布独立执行渠道尚未配置'; }
    public function invokeAgent(array $request): array { $this->fail(); }
    public function quoteMedia(array $proposal): array { $this->fail(); }
    public function submitMedia(array $request): array { $this->fail(); }
    public function reconcileMedia(array $request): array { $this->fail(); }
    /** @return never */
    private function fail(): never { CanvasPolicy::fail('EXECUTION_NOT_READY', $this->unavailableMessage()); }
}
