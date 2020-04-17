<?php

namespace OrpheusNET\Logchecker;

use FilesystemIterator;
use OrpheusNET\Logchecker\Check\Checksum;
use OrpheusNET\Logchecker\Check\Ripper;
use PHPUnit\Framework\TestCase;

class LogcheckerTest extends TestCase
{
    public function logDataProvider(): array
    {
        $return = [];
        foreach ([Ripper::EAC, Ripper::XLD, Ripper::WHIPPER] as $ripper) {
            $basePath = implode(DIRECTORY_SEPARATOR, [__DIR__, 'logs', strtolower($ripper)]);
            foreach (new FilesystemIterator($basePath . DIRECTORY_SEPARATOR . 'originals') as $entry) {
                $return[] = [$ripper, $entry->getPathname(), $entry->getFilename()];
            }
        }
        return $return;
    }

    /**
     * @dataProvider logDataProvider
     */
    public function testLogchecker(string $ripper, string $filePath, string $fileName): void
    {
        $basePath = implode(DIRECTORY_SEPARATOR, [__DIR__, 'logs', $ripper]);
        if (!Checksum::logcheckerExists($ripper)) {
            $this->markTestSkipped("Need to install {$ripper} logchecker");
        }

        $detailsFile = implode(DIRECTORY_SEPARATOR, [$basePath, 'details', str_replace('.log', '.json', $fileName)]);
        $htmlFile = implode(DIRECTORY_SEPARATOR, [$basePath, 'html', $fileName]);
        if (!file_exists($detailsFile) || !file_exists($htmlFile)) {
            $this->markTestIncomplete('Missing details or html output file: ' . $filePath);
        }
        $logchecker = new Logchecker();
        $logchecker->newFile($filePath);
        $logchecker->parse();
        $actual = [
            'ripper' => $logchecker->getRipper(),
            'version' => $logchecker->getRipperVersion(),
            'language' => $logchecker->getLanguage(),
            'score' => $logchecker->getScore(),
            'checksum' => $logchecker->getChecksumState(),
            'details' => $logchecker->getDetails()
        ];

        $this->assertEquals(json_decode(file_get_contents($detailsFile), true), $actual);
        $this->assertStringEqualsFile($htmlFile, $logchecker->getLog());
    }

    public function testGetAcceptValues(): void
    {
        $this->assertSame(".txt,.TXT,.log,.LOG", Logchecker::getAcceptValues());
    }

    public function testGetLogcheckerVersion(): void
    {
        $composer = json_decode(
            file_get_contents(implode(DIRECTORY_SEPARATOR, [__DIR__, '..', 'composer.json'])),
            true
        );
        $this->assertSame($composer['version'], Logchecker::getLogcheckerVersion());
    }
}
