<?php

namespace OrpheusNET\Logchecker\Exception;

/**
 * Thrown when a file was not found.
 */
class FileNotFoundException extends \RuntimeException {
    /**
     * @param string $path The path to the file that was not found
     */
    public function __construct(string $path) {
        parent::__construct("The file '{$path}' does not exist");
    }
}