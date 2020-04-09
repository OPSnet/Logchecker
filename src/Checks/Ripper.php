<?php

declare(strict_types=1);

namespace OrpheusNET\Logchecker\Checks;

use OrpheusNET\Logchecker\Exception\UnknownRipperException;

class Ripper
{

    public const WHIPPER = 'whipper';
    public const XLD = 'XLD';
    public const EAC = 'EAC';

    public static function getRipper(string $log): string
    {
        if (strpos($log, "Log created by: whipper") !== false) {
            return Ripper::WHIPPER;
        } elseif (strpos($log, "X Lossless Decoder version") !== false) {
            return Ripper::XLD;
        } elseif (strpos($log, "Exact Audio Copy") !== false) {
            return Ripper::EAC;
        } else if (strpos($log, "EAC") === 0) {
            return Ripper::EAC;
        } else {
            throw new UnknownRipperException("Could not determine ripper");
        }
    }
}
