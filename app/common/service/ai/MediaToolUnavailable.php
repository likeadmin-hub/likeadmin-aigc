<?php
declare(strict_types=1);

namespace app\common\service\ai;

final class MediaToolUnavailable extends \RuntimeException
{
    public function __construct(string $binary)
    {
        parent::__construct('站点暂时无法处理参考素材，请联系管理员配置 ' . $binary);
    }
}
