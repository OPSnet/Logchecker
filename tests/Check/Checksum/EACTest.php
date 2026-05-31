<?php

declare(strict_types=1);

namespace OrpheusNET\Logchecker\Check\Checksum;

use PHPUnit\Framework\TestCase;
use OrpheusNET\Logchecker\Check\Checksum;

class EACTest extends TestCase
{
    /**
     * @return array<array{string, string}>
     */
    public static function logDataProvider(): array
    {
        return [
            ['01.log', Checksum::CHECKSUM_OK],
            ['02.log', Checksum::CHECKSUM_OK],
            ['03.log', Checksum::CHECKSUM_MISSING],
            ['04.log', Checksum::CHECKSUM_MISSING],
            ['05.log', Checksum::CHECKSUM_OK],
            ['06.log', Checksum::CHECKSUM_MISSING],
            ['07.log', Checksum::CHECKSUM_MISSING],
            ['08.log', Checksum::CHECKSUM_INVALID],
            ['09.log', Checksum::CHECKSUM_MISSING],
            ['10.log', Checksum::CHECKSUM_MISSING],
            ['11.log', Checksum::CHECKSUM_MISSING],
            ['12.log', Checksum::CHECKSUM_OK],
            ['13.log', Checksum::CHECKSUM_OK],
            ['14.log', Checksum::CHECKSUM_MISSING],
            ['15.log', Checksum::CHECKSUM_INVALID],
            ['16.log', Checksum::CHECKSUM_INVALID],
            ['17.log', Checksum::CHECKSUM_MISSING],
            ['18.log', Checksum::CHECKSUM_OK],
            ['19.log', Checksum::CHECKSUM_MISSING],
            ['20.log', Checksum::CHECKSUM_MISSING],
            ['21.log', Checksum::CHECKSUM_MISSING],
            ['22.log', Checksum::CHECKSUM_MISSING],
            ['23.log', Checksum::CHECKSUM_MISSING],
            ['24.log', Checksum::CHECKSUM_MISSING],
            ['25.log', Checksum::CHECKSUM_MISSING],
            ['26.log', Checksum::CHECKSUM_INVALID],
            ['27.log', Checksum::CHECKSUM_INVALID],
        ];
    }

    /**
     * @dataProvider logDataProvider
     */
    public function testValidate(string $logFile, string $expected): void
    {
        $logPath = __DIR__ . '/../../logs/eac/checksum/' . $logFile;
        $this->assertSame($expected, EAC::validate($logPath));
    }

    public function testValidateMissingFile(): void
    {
        $this->assertSame(Checksum::CHECKSUM_MISSING, EAC::validate('/does/not/exist.log'));
    }
}
