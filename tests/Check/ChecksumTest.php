<?php

declare(strict_types=1);

namespace OrpheusNET\Logchecker\Check;

use PHPUnit\Framework\TestCase;
use OrpheusNET\Logchecker\Exception\UnknownRipperException;

class ChecksumTest extends TestCase
{
    public function testLogcheckerExistsInvalidRipper(): void
    {
        $this->expectException(UnknownRipperException::class);
        $this->expectExceptionMessage('Unknown ripper: invalid');
        Checksum::logcheckerExists('invalid');
    }
}
