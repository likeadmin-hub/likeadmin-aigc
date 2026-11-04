<?php

namespace Tests\Feature;

use app\common\model\app\aigc_canvas\AigcCanvasRun;
use app\common\service\app\aigc_canvas\AigcCanvasService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class CanvasRunTimeTest extends TestCase
{
    private string $timezone;

    protected function setUp(): void
    {
        $this->timezone = date_default_timezone_get();
        date_default_timezone_set('Asia/Shanghai');
    }

    protected function tearDown(): void
    {
        date_default_timezone_set($this->timezone);
    }

    private function format(array $row): array
    {
        // Never sync source tasks: this regression suite performs no DB/API work.
        return (new ReflectionMethod(AigcCanvasService::class, 'formatRun'))->invoke(null, $row, false);
    }

    public function testOrmDateStringsDoNotBecomeTheYearAsEpochSeconds(): void
    {
        $run = new AigcCanvasRun([
            'id' => 1,
            'create_time' => 1793584800,
            'update_time' => 1793584860,
            'finish_time' => '2026-11-02 10:02:00',
        ]);
        // Apply the runtime ORM formatting explicitly without booting DB services.
        $run->isAutoWriteTimestamp('int');
        $run->setDateFormat('Y-m-d H:i:s');
        $row = $run->toArray();
        self::assertSame('2026-11-02 10:00:00', $row['create_time']);
        self::assertSame('1970-01-01 08:33:46', date('Y-m-d H:i:s', (int)$row['create_time']));

        $data = $this->format($row);
        self::assertSame(1793584800, $data['create_time']);
        self::assertSame(1793584860, $data['update_time']);
        self::assertSame(1793584920, $data['finish_time']);
        self::assertSame(1793584800, $data['display_create_time']);
        self::assertSame(1793584860, $data['display_update_time']);
    }

    /** @dataProvider localTimes */
    public function testLocalTimesReturnSeconds($value, int $expected): void
    {
        $data = $this->format(['create_time' => $value, 'update_time' => $value, 'finish_time' => $value]);
        foreach (['create_time', 'update_time', 'finish_time', 'display_create_time', 'display_update_time'] as $key) {
            self::assertSame($expected, $data[$key], $key);
        }
    }

    public static function localTimes(): array
    {
        return [
            'date string' => ['2026-11-02 10:00:00', 1793584800],
            'seconds' => [1793584800, 1793584800],
            'seconds string' => ['1793584800', 1793584800],
            'milliseconds' => [1793584800123, 1793584800],
            'milliseconds string' => ['1793584800123', 1793584800],
            'empty' => ['', 0],
            'null' => [null, 0],
            'zero' => [0, 0],
            'invalid date' => ['not-a-date', 0],
            'negative' => [-1, 0],
            'array' => [[], 0],
        ];
    }

    public function testMissingLocalTimesStayUnknown(): void
    {
        $data = $this->format([]);
        foreach (['create_time', 'update_time', 'finish_time', 'display_create_time', 'display_update_time'] as $key) {
            self::assertSame(0, $data[$key], $key);
        }
    }

    /** @dataProvider validSourceTimes */
    public function testValidSourceTimeRetainsPrecedence($source, int $expected): void
    {
        $data = $this->format([
            'create_time' => '2026-11-02 10:00:00',
            'update_time' => '2026-11-02 10:01:00',
            'result_json' => ['create_time' => $source, 'update_time' => $source],
        ]);
        self::assertSame(1793584800, $data['create_time']);
        self::assertSame(1793584860, $data['update_time']);
        self::assertSame($expected, $data['source_create_time']);
        self::assertSame($expected, $data['source_update_time']);
        self::assertSame($expected, $data['display_create_time']);
        self::assertSame($expected, $data['display_update_time']);
    }

    public static function validSourceTimes(): array
    {
        return [
            'date string' => ['2026-11-02 09:00:00', 1793581200],
            'seconds' => [1793581200, 1793581200],
            'seconds string' => ['1793581200', 1793581200],
            'milliseconds' => [1793581200123, 1793581200],
            'milliseconds string' => ['1793581200123', 1793581200],
            // Upstream AI tasks cannot predate 2000-01-01 00:00:00 UTC.
            'minimum accepted source' => [946684800, 946684800],
        ];
    }

    /** @dataProvider invalidSourceTimes */
    public function testInvalidSourceTimeCannotMaskTheLocalTime($source): void
    {
        $data = $this->format([
            'create_time' => 1793584800,
            'update_time' => 1793584860,
            'result_json' => json_encode(['create_time' => $source, 'update_time' => $source]),
        ]);
        self::assertSame(0, $data['source_create_time']);
        self::assertSame(0, $data['source_update_time']);
        self::assertSame(1793584800, $data['display_create_time']);
        self::assertSame(1793584860, $data['display_update_time']);
    }

    public static function invalidSourceTimes(): array
    {
        return [
            'zero' => [0],
            'zero string' => ['0'],
            'empty' => [''],
            'whitespace' => ['  '],
            'null' => [null],
            'invalid date' => ['not-a-date'],
            'negative' => [-1],
            'tiny seconds' => [2026],
            'year string' => ['2026'],
            'before minimum source' => [946684799],
            'array' => [[]],
        ];
    }

    public function testCreateAndUpdateChooseFallbackIndependently(): void
    {
        $data = $this->format([
            'create_time' => 1793584800,
            'update_time' => 1793584860,
            'result_json' => ['create_time' => 'invalid', 'update_time' => 1793584920000],
        ]);
        self::assertSame(1793584800, $data['display_create_time']);
        self::assertSame(1793584920, $data['display_update_time']);
    }
}
