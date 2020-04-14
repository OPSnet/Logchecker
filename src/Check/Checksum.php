<?php

namespace OrpheusNET\Logchecker\Check;

use OrpheusNET\Logchecker\Util;
use Symfony\Component\Process\Process;

class Checksum
{
  /**
   * Will return the checksum status of the given logfile,
   * see ChecksumStates.php for the possible values.
   */
    public static function validate(string $logPath, $EAC)
    {
        if ($EAC) {
            $command = 'eac_logchecker';
            $noChecksumResult = 'Log entry has no checksum!';
            $invalidResult = 'Log entry was modified, checksum incorrect!';
            $goodResult = 'Log entry is fine!';
        } else {
            $command = 'xld_logchecker';
            $noChecksumResult = 'Not a logfile';
            $invalidResult =  'Malformed';
            $goodResult = 'OK';
        }

        if (static::logcheckerExists($command)) {
            $process = new Process([$command, $logPath]);
            $process->run();
            $output = $process->getOutput();
            if (strpos($output, $goodResult) === false) {
                if ($output == null) {
                    return ChecksumStates::CHECKSUM_MISSING;
                } elseif (strpos($output, $noChecksumResult) !== false) {
                    return ChecksumStates::CHECKSUM_MISSING;
                } elseif (strpos($output, $invalidResult) !== false) {
                    return ChecksumStates::CHECKSUM_INVALID;
                }
            }
        }

        return ChecksumStates::CHECKSUM_OK;
    }

    public static function logcheckerExists($EAC)
    {
        if ($EAC) {
            $command = 'eac_logchecker';
        } else {
            $command = 'xld_logchecker';
        }

        return Util::commandExists($command);
    }
}
