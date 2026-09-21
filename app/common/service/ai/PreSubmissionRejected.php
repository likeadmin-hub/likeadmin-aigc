<?php
namespace app\common\service\ai;

/** Only throw before any Provider submit or billing reservation has occurred. */
final class PreSubmissionRejected extends \RuntimeException
{
}
