<?php

namespace OrpheusNET\Logchecker\Check;

use OrpheusNET\Logchecker\Check\Checksum\EAC;
use OrpheusNET\Logchecker\Check\Checksum\Whipper;
use OrpheusNET\Logchecker\Check\Checksum\XLD;
use OrpheusNET\Logchecker\Exception\UnknownRipperException;

class Checksum
{
    public const CHECKSUM_OK = 'checksum_ok';
    public const CHECKSUM_INVALID = 'checksum_invalid';
    public const CHECKSUM_MISSING = 'checksum_missing';

    /**
     * Validates a given log, returning a string representing its state.
     * See possible values above.
     */
    public static function validate(string $logPath, string $ripper): string
    {
        if (!file_exists($logPath)) {
            throw new \InvalidArgumentException("Log file does not exist: {$logPath}");
        }

        if ($ripper === Ripper::WHIPPER) {
            return Whipper::validate($logPath);
        } elseif ($ripper === Ripper::EAC) {
            return EAC::validate($logPath);
        } elseif ($ripper === Ripper::XLD) {
            return XLD::validate($logPath);
        } else {
            throw new UnknownRipperException("Unknown ripper: {$ripper}");
        }
    }
}
