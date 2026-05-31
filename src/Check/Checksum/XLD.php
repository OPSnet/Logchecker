<?php

namespace OrpheusNET\Logchecker\Check\Checksum;

use OrpheusNET\Logchecker\Check\Checksum;

/**
 * Native PHP port of xld_logchecker.py. Validates the signature that XLD appends
 * to its logs. The signature is a custom SHA-256 (with a non-standard initial
 * state) over the log text, run through a bespoke "scramble" step and encoded
 * with a non-standard base64 alphabet. It is pure integer/byte math, so no
 * crypto extension is required; 32-bit unsigned arithmetic is emulated with
 * `& 0xFFFFFFFF` masking.
 */
class XLD
{
    private const LOGCHECKER_MIN_VERSION = '20121027';

    private const BEGIN_SIGNATURE = "\n-----BEGIN XLD SIGNATURE-----\n";
    private const END_SIGNATURE = "\n-----END XLD SIGNATURE-----\n";

    private const INITIAL_STATE = [
        0x1D95E3A4, 0x06520EF5, 0x3A9CFB75, 0x6104BCAE,
        0x09CEDA82, 0xBA55E60B, 0xEAEC16C6, 0xEB19AF15,
    ];

    private const ROUND_CONSTANTS = [
        0x428A2F98, 0x71374491, 0xB5C0FBCF, 0xE9B5DBA5, 0x3956C25B, 0x59F111F1,
        0x923F82A4, 0xAB1C5ED5, 0xD807AA98, 0x12835B01, 0x243185BE, 0x550C7DC3,
        0x72BE5D74, 0x80DEB1FE, 0x9BDC06A7, 0xC19BF174, 0xE49B69C1, 0xEFBE4786,
        0x0FC19DC6, 0x240CA1CC, 0x2DE92C6F, 0x4A7484AA, 0x5CB0A9DC, 0x76F988DA,
        0x983E5152, 0xA831C66D, 0xB00327C8, 0xBF597FC7, 0xC6E00BF3, 0xD5A79147,
        0x06CA6351, 0x14292967, 0x27B70A85, 0x2E1B2138, 0x4D2C6DFC, 0x53380D13,
        0x650A7354, 0x766A0ABB, 0x81C2C92E, 0x92722C85, 0xA2BFE8A1, 0xA81A664B,
        0xC24B8B70, 0xC76C51A3, 0xD192E819, 0xD6990624, 0xF40E3585, 0x106AA070,
        0x19A4C116, 0x1E376C08, 0x2748774C, 0x34B0BCB5, 0x391C0CB3, 0x4ED8AA4A,
        0x5B9CCA4F, 0x682E6FF3, 0x748F82EE, 0x78A5636F, 0x84C87814, 0x8CC70208,
        0x90BEFFFA, 0xA4506CEB, 0xBEF9A3F7, 0xC67178F2,
    ];

    private const MAGIC_CONSTANTS = [
        0x99036946, 0xE99DB8E7, 0xE3AE2FA7, 0x0A339740,
        0xF06EB6A9, 0x92FF9B65, 0x028F7873, 0x9070E316,
    ];

    private const STD_ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/';
    private const XLD_ALPHABET = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz._';

    public static function validate(string $logPath)
    {
        $data = @file_get_contents($logPath);
        if ($data === false) {
            return Checksum::CHECKSUM_MISSING;
        }

        // XLD logs are UTF-8; anything else is not a valid logfile.
        if (!mb_check_encoding($data, 'UTF-8')) {
            return Checksum::CHECKSUM_MISSING;
        }

        [$text, $version, $oldSignature] = self::extractInfo($data);

        if ($oldSignature === null) {
            return Checksum::CHECKSUM_MISSING;
        }

        if ($oldSignature !== self::signature($text)) {
            return Checksum::CHECKSUM_INVALID;
        }

        // A signature produced by a pre-20121027 XLD is considered forged.
        if ($version === null || strcmp($version, self::LOGCHECKER_MIN_VERSION) <= 0) {
            return Checksum::CHECKSUM_INVALID;
        }

        return Checksum::CHECKSUM_OK;
    }

    /**
     * Split a log into the portion that gets signed, the XLD version, and the
     * embedded signature (or null when there is no signature block).
     *
     * @return array{0: string, 1: ?string, 2: ?string}
     */
    private static function extractInfo(string $data): array
    {
        $firstLine = explode("\n", $data, 2)[0];
        if (str_starts_with($firstLine, 'X Lossless Decoder version')) {
            $version = preg_split('/\s+/', $firstLine)[4] ?? null;
        } else {
            $version = null;
        }

        $pos = strpos($data, self::BEGIN_SIGNATURE);
        if ($pos === false) {
            return [$data, $version, null];
        }

        $text = substr($data, 0, $pos);
        $remainder = substr($data, $pos + strlen(self::BEGIN_SIGNATURE));
        $signature = trim(explode(self::END_SIGNATURE, $remainder)[0]);

        return [$text, $version, $signature];
    }

    /**
     * Compute the XLD signature for the signable portion of a log.
     */
    private static function signature(string $text): string
    {
        // SHA-256 with a non-standard initial state.
        $checksum = self::sha256WithState($text, self::INITIAL_STATE);

        // A fixed version string is appended to the hex digest of the log text.
        $scrambled = self::scramble($checksum . "\nVersion=0001");

        // Non-standard base64, with padding stripped.
        return rtrim(self::nonstandardBase64Encode($scrambled), '=');
    }

    /**
     * SHA-256 compression function with a caller-supplied initial state.
     *
     * @param array<int> $initialState
     */
    private static function sha256WithState(string $data, array $initialState): string
    {
        $state = $initialState;

        // Pad with a single 1 bit, enough zeroes, and the original bit length.
        $length = 8 * strlen($data);
        $k = 0;
        while (($length + 1 + $k + 64) % 512 !== 0) {
            $k++;
        }
        $data .= "\x80" . str_repeat("\x00", intdiv($k - 7, 8)) . pack('J', $length);

        for ($start = 0; $start < strlen($data); $start += 64) {
            $chunk = substr($data, $start, 64);
            $w = array_values(unpack('N16', $chunk));
            // Pad the schedule out to 64 words (the leading 16 are reused).
            for ($i = 16; $i < 64; $i++) {
                $w[$i] = 0;
            }

            for ($i = 16; $i < 64; $i++) {
                $s0 = self::rotr($w[$i - 15], 7) ^ self::rotr($w[$i - 15], 18) ^ ($w[$i - 15] >> 3);
                $s1 = self::rotr($w[$i - 2], 17) ^ self::rotr($w[$i - 2], 19) ^ ($w[$i - 2] >> 10);
                $w[$i] = ($w[$i - 16] + $s0 + $w[$i - 7] + $s1) & 0xFFFFFFFF;
            }

            [$a, $b, $c, $d, $e, $f, $g, $h] = $state;

            for ($i = 0; $i < 64; $i++) {
                $s0 = self::rotr($a, 2) ^ self::rotr($a, 13) ^ self::rotr($a, 22);
                $maj = ($a & $b) ^ ($a & $c) ^ ($b & $c);
                $t2 = $s0 + $maj;

                $s1 = self::rotr($e, 6) ^ self::rotr($e, 11) ^ self::rotr($e, 25);
                $ch = ($e & $f) ^ ((~$e & 0xFFFFFFFF) & $g);
                $t1 = $h + $s1 + $ch + self::ROUND_CONSTANTS[$i] + $w[$i];

                $h = $g;
                $g = $f;
                $f = $e;
                $e = ($d + $t1) & 0xFFFFFFFF;
                $d = $c;
                $c = $b;
                $b = $a;
                $a = ($t1 + $t2) & 0xFFFFFFFF;
            }

            $add = [$a, $b, $c, $d, $e, $f, $g, $h];
            foreach ($state as $i => $value) {
                $state[$i] = ($value + $add[$i]) & 0xFFFFFFFF;
            }
        }

        $digest = '';
        foreach ($state as $value) {
            $digest .= pack('N', $value);
        }

        return bin2hex($digest);
    }

    /**
     * XLD's bespoke scramble step over the SHA-256 digest.
     */
    private static function scramble(string $data): string
    {
        // Split off the unaligned part.
        $unaligned = '';
        if (strlen($data) % 8 !== 0) {
            $stop = 8 * intdiv(strlen($data), 8);
            $unaligned = substr($data, $stop);
            $data = substr($data, 0, $stop) . str_repeat("\x00", 8);
        }

        $output = [];

        // Magic initial state.
        $x = 0x6479B873;
        $y = 0x48853AFC;

        for ($offset = 0; $offset < strlen($data); $offset += 8) {
            $x ^= self::readUint32($data, $offset);
            $y ^= self::readUint32($data, $offset + 4);

            for ($round = 0; $round < 4; $round++) {
                for ($i = 0; $i < 2; $i++) {
                    $y ^= $x;

                    $a = (self::MAGIC_CONSTANTS[4 * $i + 0] + $y) & 0xFFFFFFFF;
                    $b = ($a - 1 + self::rotl($a, 1)) & 0xFFFFFFFF;

                    $x ^= $b ^ self::rotl($b, 4);

                    $c = (self::MAGIC_CONSTANTS[4 * $i + 1] + $x) & 0xFFFFFFFF;
                    $d = ($c + 1 + self::rotl($c, 2)) & 0xFFFFFFFF;

                    $e = (self::MAGIC_CONSTANTS[4 * $i + 2] + ($d ^ self::rotl($d, 8))) & 0xFFFFFFFF;
                    $ff = (self::rotl($e, 1) - $e) & 0xFFFFFFFF;

                    $y ^= ($x | $ff) ^ self::rotl($ff, 16);

                    $g = (self::MAGIC_CONSTANTS[4 * $i + 3] + $y) & 0xFFFFFFFF;
                    $x ^= ($g + 1 + self::rotl($g, 2)) & 0xFFFFFFFF;
                }
            }

            $output[] = pack('N', $x) . pack('N', $y);
        }

        // Handle the unaligned last chunk differently.
        if ($unaligned !== '') {
            $last = array_pop($output);
            $xored = '';
            for ($i = 0; $i < strlen($unaligned); $i++) {
                $xored .= chr(ord($last[$i]) ^ ord($unaligned[$i]));
            }
            $output[] = $xored;
        }

        return implode('', $output);
    }

    private static function nonstandardBase64Encode(string $data): string
    {
        return strtr(base64_encode($data), self::STD_ALPHABET, self::XLD_ALPHABET);
    }

    private static function readUint32(string $data, int $offset): int
    {
        return unpack('N', substr($data, $offset, 4))[1];
    }

    private static function rotl(int $n, int $k): int
    {
        return ((($n << $k) & 0xFFFFFFFF) | ($n >> (32 - $k))) & 0xFFFFFFFF;
    }

    private static function rotr(int $n, int $k): int
    {
        return self::rotl($n, 32 - $k);
    }
}
