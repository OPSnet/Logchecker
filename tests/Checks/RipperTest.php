<?php

declare(strict_types=1);

namespace OrpheusNET\Logchecker\Checks;

use PHPUnit\Framework\TestCase;
use OrpheusNET\Logchecker\Checks\Ripper;
use OrpheusNET\Logchecker\Exception\UnknownRipperException;

class RipperTest extends TestCase
{
    public function ripperDataProvider()
    {
        return [
            [
                "Exact Audio Copy V1.3 from 2. September 2016\n\nEAC extraction logfile from 11. December 2016, 0:14",
                Ripper::EAC
            ],
            [
                "EAC 展開 ログファイル 日付： 24. 12月 2005, 18:37 for CD",
                Ripper::EAC
            ],
            [
                "Log created by: whipper 0.7.0 (internal logger)\nLog creation date: 2018-11-23T15:19:21Z",
                Ripper::WHIPPER
            ],
            [
                "X Lossless Decoder version 20161007 (149.3)\n\nXLD extraction logfile from 2017-01-04 18:59:53 -0500",
                Ripper::XLD
            ]
            ];
    }

    /**
     * @dataProvider ripperDataProvider
     */
    public function testGetRipper($testString, $ripper)
    {
        $this->assertSame($ripper, Ripper::getRipper($testString));
    }

    public function testInvalidRipper()
    {
        $this->expectException(UnknownRipperException::class);
        $this->expectExceptionMessage('Could not determine ripper');
        Ripper::getRipper('invalid invalid invalid');
    }
}
