<?php

namespace OrpheusNET\Logchecker;

use OrpheusNET\Logchecker\Exception\{FileNotFoundException};

class Chardet {
    private $executable = null;
    private $executables = [
        'chardet',
        'chardetect'
    ];

    public function __construct() {
        foreach ($this->executables as $executable) {
            if (Util::commandExists($executable)) {
                $this->executable = $executable;
            }
        }

        if ($this->executable === null) {
            throw new \RuntimeException('Chardet not installed');
        }
    }

    public function analyze($filename) {

        if (!file_exists($filename)) {
            throw new FileNotFoundException($filename);
        }

        $output = shell_exec($this->executable . " " . escapeshellarg($filename));
        // Following regex:
        //    matches[1] - file path
        //    matches[2] - charset
        //    matches[3] - confidence
        
        if ((preg_match('/(.+): (.+) .+confidence:? ([^\)]+)/', $output, $matches) === 0)) {
            throw new \Exception('This file is not analyzed');
        }
        elseif (isset($matches[2]) && $matches[2] === 'None') {
            throw new \Exception('Could not determine character set');
        }

        return ['file' => $matches[1], 'charset' => strtolower($matches[2]), 'confidence' => (float) $matches[3]];
    }
}