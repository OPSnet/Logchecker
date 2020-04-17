<?php

namespace OrpheusNET\Logchecker\Check;

use OrpheusNET\Logchecker\Exception\UnknownRipperException;
use OrpheusNET\Logchecker\Util;
use Symfony\Component\Process\Process;

class Checksum
{
    public const CHECKSUM_OK = 'checksum_ok';
    public const CHECKSUM_INVALID = 'checksum_invalid';
    public const CHECKSUM_MISSING = 'checksum_missing';

    public const EAC_LOGCHECKER = 'eac_logchecker';
    public const XLD_LOGCHECKER = 'xld_logchecker';

    /**
     * Validates a given log, returning a string representing its state.
     * See possible values above.
     */
    public static function validate(string $logPath, string $ripper): string
    {
        if ($ripper === Ripper::WHIPPER) {
            $log = trim(file_get_contents($logPath));
            preg_match('/SHA-256 hash: ([A-Z0-9]+)$/', $log, $matches);
            if (isset($matches[1])) {
                $lines = explode("\n", $log);
                $testHash = strtolower(hash('sha256', implode("\n", array_slice($lines, 0, count($lines) - 1))));
                return $testHash === strtolower($matches[1]) ? static::CHECKSUM_OK : static::CHECKSUM_INVALID;
            } else {
                return static::CHECKSUM_MISSING;
            }
        } else {
            if ($ripper === Ripper::EAC) {
                $command = static::EAC_LOGCHECKER;
                $noChecksumResult = 'Log entry has no checksum!';
                $invalidResult = 'Log entry was modified, checksum incorrect!';
                $goodResult = 'Log entry is fine!';
            } else {
                $command = static::XLD_LOGCHECKER;
                $noChecksumResult = 'Not a logfile';
                $invalidResult =  'Malformed';
                $goodResult = 'OK';
            }

            if (static::logcheckerExists($ripper)) {
                $process = new Process([$command, $logPath]);
                $process->run();
                $output = $process->getOutput();
                if (strpos($output, $goodResult) === false) {
                    if ($output == null) {
                        return static::CHECKSUM_MISSING;
                    } elseif (strpos($output, $noChecksumResult) !== false) {
                        return static::CHECKSUM_MISSING;
                    } elseif (strpos($output, $invalidResult) !== false) {
                        return static::CHECKSUM_INVALID;
                    }
                }
            }
        }

        return static::CHECKSUM_OK;
    }

    public static function logcheckerExists(string $ripper): bool
    {
        if ($ripper === Ripper::WHIPPER) {
            return true;
        }

        if ($ripper === Ripper::EAC) {
            $command = static::EAC_LOGCHECKER;
        } elseif ($ripper === Ripper::XLD) {
            $command = static::XLD_LOGCHECKER;
        } else {
            throw new UnknownRipperException("Unknown ripper: {$ripper}");
        }
        return Util::commandExists($command);
    }
}
