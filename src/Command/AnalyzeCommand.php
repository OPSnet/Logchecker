<?php

namespace OrpheusNET\Logchecker\Command;

use OrpheusNET\Logchecker\Logchecker;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\Output;
use Symfony\Component\Console\Output\OutputInterface;

class AnalyzeCommand extends Command
{
    protected function configure()
    {
        $this
            ->setName('analyze')
            ->setAliases(['analyse'])
            ->setDescription('analyze log file')
            ->setHelp('This command analyzes a log file')
            ->addOption('html', null, InputOption::VALUE_NONE, 'Print the HTML version of log, without color')
            ->addOption('no_text', null, InputOption::VALUE_NONE, 'Do not print log text to console')
            ->addArgument('file', InputArgument::REQUIRED, 'Log file to analyze')
            ->addArgument('out_file', InputArgument::OPTIONAL, 'Write HTML log to outfile');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $filename = $input->getArgument('file');
        if (!file_exists($filename)) {
            $output->writeln("Invalid file");
            return 1;
        }

        $logchecker = new Logchecker();
        $logchecker->newFile($filename);
        $logchecker->parse();

        if ($input->getArgument('out_file')) {
            file_put_contents($input->getArgument('out_file'), $logchecker->getLog());
            return 0;
        }

        $output->writeln('Ripper  : ' . $logchecker->getRipper());
        $output->writeln('Version : ' . $logchecker->getRipperVersion());
        $output->writeln('Language: ' . $logchecker->getLanguage());
        $output->writeln('Score   : ' . $logchecker->getScore());
        $output->writeln('Checksum: ' . $logchecker->getChecksumState());

        $details = $logchecker->getDetails();
        if (count($details) > 0) {
            $output->writeln('Details :');
            foreach ($details as $detail) {
                $output->writeln('    ' . $detail);
            }
        }

        if ($input->getOption('no_text')) {
            return 0;
        }
        $output->writeln('');
        $output->writeln('Log Text:');
        $output->writeln('');
        $replaces = [
            "</span>" => "</>",
            "</strong>" => "</>",
            "<span class='good'>" => "<fg=green;options=bold>",
            "<span class='bad'>" => "<fg=red;options=bold>",
            "<span class='goodish'>" => "<fg=cyan;options=bold>",
            "<span class='badish'>" => "<fg=yellow;options=bold>",
            "<span class='log1'>" => "<options=underscore>",
            "<span class='log2'>" => "<fg=yellow>",
            "<span class='log3'>" => "<fg=blue>",
            "<span class='log4'>" => "<options=bold>",
            "<span class='log5'>" => "<options=underscore>",
            "<span class='log4 log1'>" => "<options=bold>",
            "<span class='log4 log3'>" => "<fg=blue;options=bold>",
            "<span class='log4 log5'>" => "<options=bold,underscore>",
            "<strong>" => "<options=bold>",
        ];
        $log = preg_replace('/<span class="([a-zA-Z0-9 ]+)">/', "<span class='$1'>", $logchecker->getLog());

        if (!$input->getOption('html')) {
            $log = str_replace(array_keys($replaces), array_values($replaces), $log);
        }

        $output->writeln($log, $input->getOption('html') ? Output::OUTPUT_RAW : Output::OUTPUT_NORMAL);

        return 0;
    }
}
