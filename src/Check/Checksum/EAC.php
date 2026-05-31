<?php

namespace OrpheusNET\Logchecker\Check\Checksum;

use OrpheusNET\Logchecker\Check\Checksum;
use phpseclib3\Crypt\Rijndael;

/**
 * Native PHP port of eac_logchecker.py. Validates the integrity checksum that
 * EAC appends to its logs. The checksum is a Rijndael cipher run with a 256-bit
 * block size (not standard AES, which is locked to a 128-bit block), so we rely
 * on phpseclib's configurable Rijndael implementation.
 */
class EAC
{
    private const EAC_KEY = '9378716cf13e4265ae55338e940b376184da389e50647726b35f6f341ee3efd9';

    // EAC crashes if there are more than 2^13 characters in a line.
    private const MAX_LINE_LENGTH = 8192;

    public static function validate(string $logPath)
    {
        $data = @file_get_contents($logPath);
        if ($data === false) {
            return Checksum::CHECKSUM_MISSING;
        }

        $logs = self::getLogs($data);
        if ($logs === null) {
            // Decode failure or line too long: matches the Python catch where a
            // log with no usable checksum is reported as missing.
            return Checksum::CHECKSUM_MISSING;
        }

        $statuses = [];
        foreach ($logs as $log) {
            $statuses[] = self::verify($log);
        }

        // Collapse the per-entry statuses into a single value, with BAD > NO > OK.
        if (in_array('BAD', $statuses, true)) {
            return Checksum::CHECKSUM_INVALID;
        } elseif (in_array('NO', $statuses, true)) {
            return Checksum::CHECKSUM_MISSING;
        } elseif (in_array('OK', $statuses, true)) {
            return Checksum::CHECKSUM_OK;
        }

        return Checksum::CHECKSUM_MISSING;
    }

    /**
     * Decode the raw log bytes (UTF-16-LE) and split them into individual log
     * entries. Returns null when the data cannot be decoded or violates EAC's
     * line-length limit.
     *
     * @return array<array{text: string, modified: bool}>|null
     */
    private static function getLogs(string $data): ?array
    {
        $text = @iconv('UTF-16LE', 'UTF-8', $data);
        if ($text === false) {
            return null;
        }

        // Strip off the BOM.
        if (str_starts_with($text, "\u{FEFF}")) {
            $text = substr($text, strlen("\u{FEFF}"));
        }

        // The checksum strips newlines anyway, so normalise them for our regexes.
        $text = str_replace("\r\n", "\n", $text);

        // Null bytes screw it up.
        $nullPos = strpos($text, "\0");
        if ($nullPos !== false) {
            $text = substr($text, 0, $nullPos);
        }

        foreach (explode("\n", $text) as $line) {
            if (mb_strlen($line, 'UTF-8') + 1 > self::MAX_LINE_LENGTH) {
                return null;
            }
        }

        $splits = preg_split(
            '/(\n\n==== .* [A-Z0-9]+ ====)/u',
            $text,
            -1,
            PREG_SPLIT_DELIM_CAPTURE
        );

        $segments = [];
        foreach ($splits as $split) {
            if (trim($split) !== '') {
                $segments[] = $split;
            }
        }

        $logs = [];
        $count = count($segments);
        if ($count > 1) {
            $length = ($count % 2 === 1) ? $count - 1 : $count;
            for ($i = 0; $i < $length; $i += 2) {
                $logText = $segments[$i] . $segments[$i + 1];
                $modified = false;
                if ($i > 0) {
                    $logText = preg_replace(
                        '/[^-]-{60}[^-]/u',
                        '',
                        $logText,
                        1,
                        $matches
                    );
                    if ($matches === 0) {
                        $modified = true;
                    }
                }
                $logs[] = ['text' => $logText, 'modified' => $modified];
            }
            for ($i = $length; $i < $count; $i++) {
                $logs[] = ['text' => $segments[$i], 'modified' => false];
            }
        } else {
            $logs[] = ['text' => $segments[0] ?? '', 'modified' => false];
        }

        return $logs;
    }

    /**
     * Determine the status ('OK', 'NO', or 'BAD') for a single log entry.
     *
     * @param array{text: string, modified: bool} $log
     */
    private static function verify(array $log): string
    {
        $version = null;
        $oldChecksum = null;
        $unsignedText = $log['text'];

        if ($log['text'] !== '') {
            foreach (explode("\n", $log['text']) as $line) {
                if (str_starts_with($line, 'Exact Audio Copy')) {
                    $parts = preg_split('/\s+/', trim($line));
                    $version = array_slice($parts, 3, 3);
                } elseif (preg_match('/^[a-zA-Z]/', $line)) {
                    break;
                }
            }

            if (preg_match('/\n\n==== (.*) ([A-Z0-9]+) ====/u', $log['text'], $match)) {
                $parts = preg_split('/\n\n==== ' . preg_quote($match[1], '/') . '/u', $log['text'], 2);
                $unsignedText = $parts[0];
                $checksumParts = preg_split('/\s+/', trim($parts[1] ?? ''));
                $oldChecksum = $checksumParts[0] ?? null;
            }
        }

        if ($version === null || $oldChecksum === null) {
            return 'NO';
        }

        if ($log['modified'] || $oldChecksum !== self::checksum($unsignedText)) {
            return 'BAD';
        }

        return 'OK';
    }

    /**
     * Compute the EAC checksum (Rijndael-256, CBC, zero IV) over the unsigned
     * portion of a log entry.
     */
    private static function checksum(string $unsignedText): string
    {
        // Ignore newlines.
        $text = str_replace(["\r", "\n"], '', $unsignedText);

        // Fuzzing reveals BOMs are also ignored.
        $text = str_replace(["\u{FEFF}", "\u{FFFE}"], '', $text);

        // Encode the text as UTF-16-LE.
        $plaintext = iconv('UTF-8', 'UTF-16LE', $text);

        // With no data there are no cipher blocks, so the checksum stays at its
        // all-zeroes initial value (the IV).
        if ($plaintext === '') {
            return str_repeat('00', 32);
        }

        // Zero-pad to a multiple of the 32-byte block size.
        if (strlen($plaintext) % 32 !== 0) {
            $plaintext = str_pad($plaintext, intdiv(strlen($plaintext) + 31, 32) * 32, "\0");
        }

        $cipher = new Rijndael('cbc');
        $cipher->setBlockLength(256);
        $cipher->setKeyLength(256);
        $cipher->setKey(hex2bin(self::EAC_KEY));
        // The IV is all zeroes.
        $cipher->setIV(str_repeat("\0", 32));
        $cipher->disablePadding();

        $ciphertext = $cipher->encrypt($plaintext);

        // The checksum is the final ciphertext block.
        $checksum = substr($ciphertext, -32);

        return strtoupper(bin2hex($checksum));
    }
}
