<?php
namespace app\common\service\power;

/** Only thrown before a provider request, never inferred from an upstream error string. */
final class TextRequestNotSubmittedException extends \RuntimeException {}
