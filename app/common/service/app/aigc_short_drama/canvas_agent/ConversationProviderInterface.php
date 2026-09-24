<?php
declare(strict_types=1);
namespace app\common\service\app\aigc_short_drama\canvas_agent;

/** Server-only adapter. Never select an implementation from a user parameter. */
interface ConversationProviderInterface
{
    /** Local, no-cost authorization, model, safety and billing prechecks only.
     * Throw before any external paid request/reservation requiring compensation.
     */
    public function preflight(int $tenant,int $user,array $request): void;

    /** One attempt only. Adapter owns shared billing, safety and Provider DTOs.
     * Return ['content'=>string,'tool_calls'=>[]]; no tools execute in P2.
     * Throwing after invocation is an uncertain outcome, never auto-retried.
     */
    public function generate(int $tenant,int $user,array $request): array;
}
