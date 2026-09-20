<?php
namespace Tests\Feature;

use app\common\service\app\aigc_short_drama\ShortDramaPlanningUnit as Unit;
use PHPUnit\Framework\TestCase;
use think\facade\Db;

/** Transactional fixtures only, no external provider or billing calls. */
class ShortDramaPlanningUnitPersistenceTest extends TestCase
{
    private bool $transaction = false;
    private string $task;
    protected function setUp(): void
    {
        if (getenv('SHORT_DRAMA_STORY_DB_TESTS') !== '1') self::markTestSkipped('Opt in to transactional local DB tests');
        (new \think\App())->initialize(); Db::startTrans(); $this->transaction = true;
        $this->task = 'test_receipt_' . bin2hex(random_bytes(8));
    }
    protected function tearDown(): void { if ($this->transaction) Db::rollback(); }
    public function testReceivedResponseIsReusedAndChangedContextCannotReplayIt(): void
    {
        $calls = 0;
        $provider = static function () use (&$calls) { $calls++; return ['result' => ['content' => '{"ok":true}']]; };
        $first = Unit::call(2000000719, 7, $this->task, 'v3_script', ['content' => 'same'], $provider);
        self::assertSame($first, Unit::call(2000000719, 7, $this->task, 'v3_script', ['content' => 'same'], $provider));
        self::assertSame(1, $calls);
        $this->expectExceptionCode(409);
        Unit::call(2000000719, 7, $this->task, 'v3_script', ['content' => 'changed'], $provider);
    }
    public function testAmbiguousReadTimeoutDoesNotAutomaticallyResubmit(): void
    {
        $calls = 0;
        for ($i = 0; $i < 2; $i++) {
            try { Unit::call(2000000719, 7, $this->task, 'v3_script', [], static function () use (&$calls) { $calls++; throw new \RuntimeException('Operation timed out after 120000 milliseconds'); }); }
            catch (\RuntimeException $error) { self::assertStringContainsString('timed out', $error->getMessage()); }
        }
        self::assertSame(1, $calls);
        self::assertFalse(Unit::retryableBeforeSubmission('Operation timed out after 120000 milliseconds'));
        self::assertTrue(Unit::retryableBeforeSubmission('Could not resolve host: provider.test'));
        self::assertTrue(Unit::retryableBeforeSubmission('cURL error 6: Could not resolve host'));
        self::assertTrue(Unit::retryableBeforeSubmission('Network is unreachable'));
    }
    public function testConnectionEstablishmentFailureBacksOffBeforeRetry(): void
    {
        try { Unit::call(2000000719, 7, $this->task, 'v3_script', [], static function () { throw new \RuntimeException('Connection refused'); }); }
        catch (\RuntimeException $error) { self::assertSame(425, $error->getCode()); }
        self::assertFalse(Unit::ready(2000000719, 7, $this->task));
        $this->expectExceptionCode(425);
        Unit::call(2000000719, 7, $this->task, 'v3_script', [], static function () { self::fail('Backoff bypassed'); });
    }
}
