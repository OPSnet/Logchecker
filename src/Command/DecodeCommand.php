<?php

namespace OrpheusNET\Logchecker\Command;

use OrpheusNET\Logchecker\Util;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class DecodeCommand extends Command
{
    protected function configure()
    {
        $this
            ->setName('decode')
            ->setDescription('Decodes log from whatever encoding into UTF-8')
            ->setHelp(<<<HELP
This command decodes a log from whatever encoding into UTF-8.

XLD and Whipper generates logs that are in UTF-8, while EAC uses UTF-16. However, older
EAC logs will often be in a smattering of different encoding (most popular is CP-1251, which
is a Cyrillic code page), which are in-compatible with UTF-8 based analysis, and so require
decoding first. Due to the difficulty of this problem, we use chardet (if installed) to give
us the encoding if we cannot detect it via a BOM.

If no [out_file] is specified, the decoded log will be printed to stdout.
HELP
            )
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
        $log = Util::decodeEncoding($log, $filename);

        if ($input->getArgument('out_file')) {
            file_put_contents($input->getArgument('out_file'), $log);
        } else {
            $output->write($log);
        }

        return 0;
    }
}
