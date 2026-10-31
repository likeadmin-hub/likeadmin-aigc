<?php
namespace app\api\http\middleware;

use app\common\service\ai\GenerationSubmission;
use app\common\service\open_platform\OpenCallContext;

final class GenerationSubmissionMiddleware
{
    public function handle($request, \Closure $next)
    {
        $key = $request->header('x-generation-request-key', '');
        $operation = GenerationSubmission::operation($request->controller(), $request->action());
        if ($request->method() !== 'POST' || $key === '' || $operation === '' || OpenCallContext::active()) return $next($request);
        $params = $request->post('', [], [static fn($value) => is_string($value) ? trim($value) : $value]);
        return GenerationSubmission::run((int)$request->tenantId, (int)$request->userId, $operation, $key, $params, function () use ($request, $next, $params, $key, $operation) {
            if (in_array($operation, ['app.aigc_video.generate/index', 'app.aigc_music.generate/index', 'app.aigc_product_promo_video.generate/index'], true) && empty($params['idempotency_key'])) $request->withPost($params + ['idempotency_key' => $key]);
            return $next($request);
        });
    }
}
