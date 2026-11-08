<?php

namespace app\common\service\app;

/** Transient contention; durable background work should yield and retry. */
class AppAccessBusyException extends \RuntimeException
{
}
