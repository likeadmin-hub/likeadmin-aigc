<?php
declare(strict_types=1);
namespace app\common\service\decorate\agent;

/** This new submission failed validation or rolled back before a new run committed. */
final class DecorationAgentDesignExtractionRejected extends \RuntimeException {}
