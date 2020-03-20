<?php

namespace OrpheusNET\Logchecker\Checks;

use OrpheusNET\Logchecker\Util;

class ChecksumStates {
    const CHECKSUM_MISSING = 'checksum_missing';
    const CHECKSUM_INVALID = 'checksum_invalid';
    const CHECKSUM_OK = 'checksum_ok';
}
