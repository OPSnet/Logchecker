<?php

namespace OrpheusNET\Logchecker\Command;

use OrpheusNET\Logchecker\Parser\EAC\Translator;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class TranslateCommand extends Command
{
    protected function configure()
    {
        $this
            ->setName('translate')
            ->setDescription('Translates a log into english')
            ->setHelp("Translates a log into english")
            ->addOption('language', 'l', InputOption::VALUE_OPTIONAL, 'Force language to use')
            ->addArgument('file', InputArgument::REQUIRED, 'Log file to decode')
            ->addArgument('out_file', InputArgument::OPTIONAL, 'File to write decoded log file to');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $filename = $input->getArgument('file');
        if (!file_exists($filename)) {
            $output->writeln("Invalid file");
            return 1;
        }

        $log = file_get_contents($filename);
        $outFile = $input->getArgument('out_file');
        if (!is_null($outFile)) {
            $output->setVerbosity(OutputInterface::VERBOSITY_QUIET);
        }
        if ($input->getOption('language')) {
            $code = $input->getOption('language');
            $output->writeln("Translating from {$code} to English");
        } else {
            $language = Translator::getLanguage($log);
            $code = $language['code'];
            $output->writeln("Translating from {$language['name']} ({$language['name_english']}) to English");
        }

        $log = Translator::translate($log, $language['code']);

        if (!is_null($outFile)) {
            file_put_contents($outFile, $log);
        } else {
            $output->write($log);
        }

        return 0;
    }
}
