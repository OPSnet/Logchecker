<?php

declare(strict_types=1);

namespace OrpheusNET\Logchecker;

use PHPUnit\Framework\TestCase;

class UtilTest extends TestCase
{
    public function commandExistsDataProvider(): array
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
}
