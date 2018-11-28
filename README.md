Logchecker
==========

A CD rip logchecker, used for analyzing the generated logs for any problems that would potentially
indicate a non-perfect rip was produced. Of course, just because a log doesn't score a perfect 100%
does not mean that the produced rip isn't bit perfect, it's just less likely. While this library can
largely run on both Linux and Windows, validating of checksums is only really supported for Linux.

While this library will analyze most parts of a log, unfortunately it cannot properly validate the checksums
for all types of logs. This is due to creators of these programs making their logchecker closed source
and involves some amount of custom mathematical work to produce it. Therefore, we have to fallback on
external methods to validate the checksums of EAC and XLD. If the logchecker detects that we do not have
the necessary programs, then we will just skip this external step and assume the checksum is valid. For
setting up the necessary programs to validate the checksum, see below for the given program you care about.

## Requirements
* PHP 7.0+
* Python3.4+
* [chardet](https://github.com/chardet/chardet)
* [eac_logchecker.py](https://github.com/OPSnet/eac_logchecker.py)
* [xld_logchecker.py](https://github.com/OPSnet/xld_logchecker.py)

## Installation
```
$ composer require orpheusnet/logchecker
$ pip3 install chardet eac-logchecker xld-logchecker
```

## Usage
### CLI
```
$ logchecker list
Logchecker by Orpheus 0.5.0

Usage:
  command [options] [arguments]

Options:
  -h, --help            Display this help message
  -V, --version         Display this application version

Available commands:
  analyze  analyze log file
  help     Displays help for a command
  list     Lists commands

$ logchecker analyze --help
Description:
  analyze log file

Usage:
  analyze [options] [--] <file>

Arguments:
  file                  Log file to analyze

Options:
      --output          Print the HTML log text
  -h, --help            Display this help message

Help:
  This command analyzes a log file

$ logchecker analyze tests/logs/wgdbcm.log
Score   : 57
Checksum: false
Details :
    [Notice] Translated log from Русский (Russian) to English.
    EAC version older than 0.99 (-30 points)
    Could not verify read mode (-1 point)
    Could not verify read offset (-1 point)
    Could not verify null samples
    Could not verify gap handling (-10 points)
    Could not verify id3 tag setting (-1 point)
```

### Code
```
<?php

$logchecker = new OrpheusNET\Logchecker\Logchecker();
$logchecker->add_file('path/to/file.log');
list($score, $details, $checksum, $log_text) = $logchecker->parse();
```
