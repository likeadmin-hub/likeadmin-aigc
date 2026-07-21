<?php

namespace app\common\controller;

use app\common\service\ai\AiTaskCallbackService;
use think\Request;

class AiTaskCallbackController
{
    public function receive(Request $request)
    {
        try {
            return json(['code' => 1, 'msg' => 'ok', 'data' => AiTaskCallbackService::accept($request)]);
        } catch (\Throwable $e) {
            return json(['code' => 0, 'msg' => 'invalid callback'], 403);
        }
    }
}
