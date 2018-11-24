<?php

namespace OrpheusNET\Logchecker;

class Util {
    public static function commandExists($cmd) {
        $return = shell_exec(sprintf("which %s", escapeshellarg($cmd)));
        return !empty($return);
    }

    public static function strposArray(string $haystack, array $needles) {
        foreach ($needles as $needle) {
            if (($pos = strpos($haystack, $needle)) !== false) {
                return $pos;
            }
        }
        return false;
    }
}
