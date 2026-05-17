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
    public function testCommandExists(string $command, bool $exists): void
    {
        $this->assertSame($exists, Util::commandExists($command));
    }

    public static function decodeLogDataProvider(): array
    {
        $eacPath = implode(DIRECTORY_SEPARATOR, [__DIR__, 'logs', 'eac', 'originals']);
        $return = [];
        foreach (new FilesystemIterator($eacPath) as $file) {
            [$language, $logName] = explode('_', $file->getFilename());
            $expectedFile = implode(DIRECTORY_SEPARATOR, [$eacPath, '..', 'utf8', $language, $logName]);
            if (file_exists($expectedFile)) {
                $return[] = [$file->getPathname(), $expectedFile];
            }
        }

        $xldPath = implode(DIRECTORY_SEPARATOR, [__DIR__, 'logs', 'xld', 'originals']);
        foreach (new FilesystemIterator($xldPath) as $file) {
            $expectedFile = implode(DIRECTORY_SEPARATOR, [$xldPath, '..', 'utf8', $file->getFilename()]);
            if (file_exists($expectedFile)) {
                $return[] = [$file->getPathname(), $expectedFile];
            }
        }
        return $return;
    }

    /**
     * @dataProvider decodeLogDataProvider
     */
    public function testDecodeLog(string $logPath, string $expectedFile): void
    {
        $this->assertStringEqualsFile($expectedFile, Util::decodeEncoding(file_get_contents($logPath), $logPath));
    }
}
