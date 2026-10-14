<?php
declare(strict_types=1);
namespace app\common\service\power;

/** A local balance check rejected the call before consumption creation or provider I/O. */
final class TextAdmissionRejected extends \InvalidArgumentException
{
}
