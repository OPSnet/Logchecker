<?php

namespace OrpheusNET\Logchecker\Command;

use OrpheusNET\Logchecker\Logchecker;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\{InputArgument,InputOption,InputInterface};
use Symfony\Component\Console\Output\OutputInterface;

class AnalyzeCommand extends Command {
    protected function configure() {
        $this
            ->setName('analyze')
            ->setDescription('analyze log file')
            ->setHelp('This command analyzes a log file')
            ->addOption('output', null, InputOption::VALUE_NONE, 'Print the HTML log text')
            ->addOption('out', 'file', InputOption::VALUE_REQUIRED, 'File to write HTML log text to')
            ->addArgument('file', InputArgument::REQUIRED, 'Log file to analyze');
    }

    protected function execute(InputInterface $input, OutputInterface $output) {
        $filename = $input->getArgument('file');
        if (!file_exists($filename)) {
            $output->writeln("Invalid file");
            return;
        }
        
        $logchecker = new Logchecker();
        $logchecker->new_file($filename);
        list($score, $details, $checksum, $log_text) = $logchecker->parse();
        $output->writeln('Score   : ' . $score);
        $output->writeln('Checksum: ' . ($checksum ? 'true' : 'false'));

        if (count($details) > 0) {
            $output->writeln('Details :');
            foreach ($details as $detail) {
                $output->writeln('    '.$detail);
            }
        }

        if ($input->getOption('output')) {
            $output->writeln('');
            $output->writeln('Log Text:');
            $output->writeln($log_text);
        }

        if ($input->getOption('out')) {
            $html_out = <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <title>Test</title>
    <meta charset="utf-8"/>
    <style>
.log1 {

}

.log2 {
	color: yellow;
}

.log3 {
	color: #0E88C6;
}

.log4 {
 	font-weight: bold;
}

.log5 {
 	text-decoration: underline;
}

.good {
	font-weight: bold;
	color: green;
}

.bad {
	font-weight: bold;
	color: red;
}

.goodish {
	font-weight: bold;
	color: #35BF00;
}

.badish {
	font-weight: bold;
	color: #E5B244;
}
    </style>
</head>
<body>
<pre>{$log_text}</pre>
</body>
</html>
HTML;
            file_put_contents($input->getOption('out'), $html_out);
        }
    }
}