<?php

declare(strict_types=1);

namespace OrpheusNET\Logchecker;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;

class UtilTest extends TestCase
{
    public static function commandExistsDataProvider(): array
    {
        return [
            ['cd', true],
            ['totallyfakecommandthatdefinitelydoesnotexist', false]
        ];
    }

    /**
     * @dataProvider commandExistsDataProvider
     */
    public function testCommandExists($command, $exists)
    {
        $this->assertSame($exists, Util::commandExists($command));
    }

    public static function decodeLogDataProvider(): array
    {
        $logPath = implode(DIRECTORY_SEPARATOR, [__DIR__, 'logs', 'eac', 'originals']);
        $return = [];
        foreach (new FilesystemIterator($logPath) as $file) {
            [$language, $logName] = explode('_', $file->getFilename());
            if (!file_exists(implode(DIRECTORY_SEPARATOR, [$logPath, '..', 'utf8', $language]))) {
                continue;
            }
            $return[] = [$file->getPathname(), $language, $logName];
        }
        return $return;
    }

    /**
     * @dataProvider decodeLogDataProvider
     */
    public function testDecodeLog(string $logPath, string $language, string $logName)
    {
        $testLog = implode(DIRECTORY_SEPARATOR, [__DIR__, 'logs', 'eac', 'utf8', $language, $logName]);
        $this->assertStringEqualsFile($testLog, Util::decodeEncoding(file_get_contents($logPath), $logPath));
    }
}
