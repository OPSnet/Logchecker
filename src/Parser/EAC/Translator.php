<?php

declare(strict_types=1);

namespace OrpheusNET\Logchecker\Parser\EAC;

use OrpheusNET\Logchecker\Exception\UnknownLanguageException;

class Translator
{
    public static function translate(string $log, string $language_code): string
    {
        if ($language_code === 'en') {
            return $log;
        }
        $lang_directory = __DIR__ . DIRECTORY_SEPARATOR . 'languages' . DIRECTORY_SEPARATOR;
        $english = json_decode(file_get_contents($lang_directory . 'en.json'), true);
        $translation = json_decode(file_get_contents($lang_directory . $language_code . '.json'), true);

        foreach ($translation as $key => $value) {
            if (empty($english[$key])) {
                continue;
            }

            if (!is_array($value)) {
                $value = [$value];
            }
            foreach ($value as $vvalue) {
                $tmp_log = preg_replace('/' . preg_quote($vvalue, '/') . '/ui', $english[$key], $log);
                if ($tmp_log !== null) {
                    $log = $tmp_log;
                }
            }
        }

        return $log;
    }

    public static function getLanguage(string $log): array
    {
        $languages = json_decode(
            file_get_contents(__DIR__ . DIRECTORY_SEPARATOR . 'languages' . DIRECTORY_SEPARATOR . 'master.json'),
            true
        );
        foreach ($languages as $code => $lang) {
            foreach ($lang['eac_strings'] as $eac_string) {
                if (preg_match('/' . preg_quote($eac_string, "/") . '/ui', $log) === 1) {
                    return [
                        'code' => $code,
                        'name' => $lang['name'],
                        'name_english' => $lang['name_english']
                    ];
                }
            }
        }

        throw new UnknownLanguageException('Could not determine language of EAC log');
    }
}
