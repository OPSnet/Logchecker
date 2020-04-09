<?php

namespace OrpheusNET\Logchecker;

class Util
{
    public static function commandExists(string $cmd)
    {
        $where = substr(strtolower(PHP_OS), 0, 3) === 'win' ? 'where' : 'command -v';

        exec("{$where} {$cmd} 2>/dev/null", $output, $return_var);
        return $return_var === 0;
    }
}
