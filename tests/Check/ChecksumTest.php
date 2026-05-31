<?php

declare(strict_types=1);

namespace OrpheusNET\Logchecker\Check;

use PHPUnit\Framework\TestCase;
use OrpheusNET\Logchecker\Exception\UnknownRipperException;

class ChecksumTest extends TestCase
{
    public function testValidateInvalidRipper(): void
    {
        $this->expectException(UnknownRipperException::class);
        $this->expectExceptionMessage('Unknown ripper: invalid');
        Checksum::validate(__FILE__, 'invalid');
    }

    public function testValidateMissingFile(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Log file does not exist: nonexistent.log');
        Checksum::validate('nonexistent.log', Ripper::EAC);
    }
}
