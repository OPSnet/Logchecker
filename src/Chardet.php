<?php

namespace OrpheusNET\Logchecker;

use OrpheusNET\Logchecker\Exception\FileNotFoundException;
use Symfony\Component\Process\Process;

/**
 * Wrapper class around the cchardet / chardet executables
 *
 * @see https://github.com/PyYoshi/cChardet
 * @see https://github.com/chardet/chardet
 */
class Chardet
{
    private static $executable = null;
    private $executables = [
        'cchardetect',
        'chardetect',
        'chardet'
    ];

    /**
     * Construct Chardet class. If no chardet executable is found on the system,
     * this will throw an exception.
     *
     * @throws \RuntimeException
     */
    public function __construct()
    {
        if (static::$executable === null) {
            foreach ($this->executables as $executable) {
                if (Util::commandExists($executable)) {
                    static::$executable = $executable;
                    break;
                }
            }

            if (static::$executable === null) {
                throw new \RuntimeException('chardet not installed');
            }
        }
    }

    /**
     * @throws \Exception
     */
    public function analyze($filename): array
    {
        if (!file_exists($filename)) {
            throw new FileNotFoundException($filename);
        }

        $process = new Process([static::$executable, $filename]);
        $process->run();

        /**
         * General output is something like:
         * test.log: UTF-8-SIG with confidence 0.9900000095367432
         *
         * and our regex should give us the following elements:
         *      matches[1] - file path
         *      matches[2] - charset
         *      matches[3] - confidence
         */

        if ((preg_match('/(.+): (.+) .+confidence:? ([^\)]+)/', strtolower($process->getOutput()), $matches) === 0)) {
            throw new \Exception('This file is not analyzed');
        } elseif (isset($matches[2]) && $matches[2] === 'None') {
            throw new \Exception('Could not determine character set');
        }

        return ['file' => $matches[1], 'charset' => strtolower($matches[2]), 'confidence' => (float) $matches[3]];
    }
}
