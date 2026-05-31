<?php

declare(strict_types=1);

namespace OrpheusNET\Logchecker\Check\Checksum;

use PHPUnit\Framework\TestCase;
use OrpheusNET\Logchecker\Check\Checksum;

class XLDTest extends TestCase
{
    /**
     * @return array<array{string, string}>
     */
    public static function logDataProvider(): array
    {
        return [
            ['01.log', Checksum::CHECKSUM_OK],
            ['02.log', Checksum::CHECKSUM_OK],
            ['03.log', Checksum::CHECKSUM_INVALID],
            ['04.log', Checksum::CHECKSUM_MISSING],
            ['05.log', Checksum::CHECKSUM_OK],
            ['06.log', Checksum::CHECKSUM_OK],
            ['07.log', Checksum::CHECKSUM_MISSING],
            ['08.log', Checksum::CHECKSUM_OK],
        ];
    }

    /**
     * @dataProvider logDataProvider
     */
    public function testValidate(string $logFile, string $expected): void
    {
        $logPath = __DIR__ . '/../../logs/xld/checksum/' . $logFile;
        $this->assertSame($expected, XLD::validate($logPath));
    }

    public function testValidateMissingFile(): void
    {
        $this->assertSame(Checksum::CHECKSUM_MISSING, XLD::validate('/does/not/exist.log'));
    }
}
