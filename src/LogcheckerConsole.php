<?php

namespace OrpheusNET\Logchecker;

use Symfony\Component\Console\Application;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class LogcheckerConsole extends Application {
    public function __construct() {
        $composer_config = json_decode(file_get_contents(__DIR__.'/../composer.json'), true);
        $version = $composer_config['version'];

        parent::__construct('Logchecker by Orpheus', $version);

        $analyze_command = new Command\AnalyzeCommand();

        $this->addCommands([
            $analyze_command
        ]);

        $this->setDefaultCommand($analyze_command->getName(), true);
    }
}