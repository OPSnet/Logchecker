<?php

namespace OrpheusNET\Logchecker;

use OrpheusNET\Logchecker\Exception\FileNotFoundException;
use Symfony\Component\Process\Process;

class Chardet
{
    private static $executable = null;
    private $executables = [
        'chardet',
        'chardetect'
    ];

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

    public function analyze($filename)
    {

        if (!file_exists($filename)) {
            throw new FileNotFoundException($filename);
        }

        $process = new Process([static::$executable, $filename]);
        $process->run();

        // Following regex:
        //    matches[1] - file path
        //    matches[2] - charset
        //    matches[3] - confidence

        if ((preg_match('/(.+): (.+) .+confidence:? ([^\)]+)/', $process->getOutput(), $matches) === 0)) {
            throw new \Exception('This file is not analyzed');
        } elseif (isset($matches[2]) && $matches[2] === 'None') {
            throw new \Exception('Could not determine character set');
        }

        return ['file' => $matches[1], 'charset' => strtolower($matches[2]), 'confidence' => (float) $matches[3]];
    }
}
