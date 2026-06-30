<?php

namespace app\common\service\app\aigc_action_transfer;

class ActionTransferGenerateResult
{
    public function __construct(
        public bool $success,
        public string $status = 'running',
        public string $taskId = '',
        public array $videos = [],
        public string $error = '',
        public array $raw = [],
        public array $usage = [],
        public bool $pending = false
    ) {
    }
}
