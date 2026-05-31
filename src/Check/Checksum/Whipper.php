<?php

namespace OrpheusNET\Logchecker\Check\Checksum;

use OrpheusNET\Logchecker\Check\Checksum;

class Whipper
{
    public static function validate(string $logPath)
    {
        $log = trim(file_get_contents($logPath));
        preg_match('/SHA-256 hash: ([A-Z0-9]+)$/', $log, $matches);
        if (isset($matches[1])) {
            $lines = explode("\n", $log);
            $testHash = strtolower(hash('sha256', implode("\n", array_slice($lines, 0, count($lines) - 1))));
            return $testHash === strtolower($matches[1]) ? Checksum::CHECKSUM_OK : Checksum::CHECKSUM_INVALID;
        } else {
            return Checksum::CHECKSUM_MISSING;
        }
    }
}
