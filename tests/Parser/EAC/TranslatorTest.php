<?php

declare(strict_types=1);

namespace OrpheusNET\Logchecker\Parser\EAC;

use FilesystemIterator;
use OrpheusNET\Logchecker\Exception\UnknownLanguageException;
use PHPUnit\Framework\TestCase;

class TranslatorTest extends TestCase
{
    public function foreignLogDataProvider()
    {
        $logs = [];
        $logPath = implode(DIRECTORY_SEPARATOR, [__DIR__, '..', '..', 'logs', 'transcoded_foreign_logs']);
        foreach (new FilesystemIterator($logPath, FilesystemIterator::SKIP_DOTS) as $dir) {
            if ($dir->isFile()) {
                continue;
            }
            foreach (new FilesystemIterator($dir->getPathname(), FilesystemIterator::SKIP_DOTS) as $logFiles) {
                if (preg_match("/[0-9]+\.log/", $logFiles->getFilename())) {
                    $logs[] = [$dir->getFilename(), $logFiles->getPathname()];
                }
            }
        }
        return $logs;
    }

    /**
     * @dataProvider foreignLogDataProvider
     */
    public function testTranslateLog($language, $logPath)
    {
        $log = file_get_contents($logPath);
        $translatedLogPath = str_replace('.log', '_en.log', $logPath);
        $langDetails = Translator::getLanguage($log);
        $this->assertSame($language, $langDetails['code']);
        $this->assertNotNull($langDetails['name']);
        $this->assertNotNull($langDetails['name_english']);
        $this->assertStringEqualsFile($translatedLogPath, Translator::translate($log, $language));
    }

    public function testInvalidLanguage()
    {
        $this->expectException(UnknownLanguageException::class);
        $this->expectExceptionMessage('Could not determine language of EAC log');
        Translator::getLanguage(
            file_get_contents(implode(DIRECTORY_SEPARATOR, [__DIR__, '..', '..', 'logs', 'xld', 'xld_perfect.log']))
        );
    }
}
