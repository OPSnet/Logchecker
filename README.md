# Logchecker

A CD rip logchecker, used for analyzing the generated logs for any problems that would potentially
indicate a non-perfect rip was produced. Of course, just because a log doesn't score a perfect 100%
does not mean that the produced rip isn't bit perfect, it's just less likely. This library should work
on any OS where PHP and Python are supported.

While this library will analyze most parts of a log, unfortunately it cannot properly validate the checksums
for all types of logs. This is due to creators of these programs making their logchecker closed source
and involves some amount of custom mathematical work to produce it. Therefore, we have to fallback on
external methods to validate the checksums of EAC and XLD. If the logchecker detects that we do not have
the necessary programs, then we will just skip this external step and assume the checksum is valid. For
setting up the necessary programs to validate the checksum, see below for the given program you care about.

## Requirements

* PHP 8.1+

## Optional Requirements

* Python 3.5+
* [cchardet](https://github.com/PyYoshi/cChardet) (or [chardet](https://github.com/chardet/chardet))
* [eac_logchecker.py](https://github.com/OPSnet/eac_logchecker.py)
* [xld_logchecker.py](https://github.com/OPSnet/xld_logchecker.py)

```bash
pip3 install cchardet eac-logchecker xld-logchecker
```

## Standalone

### Installation

Install via composer:

```bash
composer global require orpheusnet/logchecker
```

Alternatively, go to our [releases](https://github.com/OPSnet/Logchecker/releases) and grab the `logchecker.phar`
file. Download this file, and then it can executed via CLI by running `php logchecker.phar`. If you `chmod +x` the
file, then it should be directly executable (i.e. `./logchecker.phar`). To then install it globally, run:

```bash
mv logchecker.phar /usr/local/bin/logchecker
chmod +x /usr/local/bin/logchecker
```

### Usage

```text
$ logchecker list
Logchecker 0.11.1

Usage:
  command [options] [arguments]

Options:
  -h, --help            Display this help message
  -q, --quiet           Do not output any message
  -V, --version         Display this application version
      --ansi            Force ANSI output
      --no-ansi         Disable ANSI output
  -n, --no-interaction  Do not ask any interactive question
  -v|vv|vvv, --verbose  Increase the verbosity of messages: 1 for normal output, 2 for more verbose output and 3 for debug

Available commands:
  analyze    [analyse] analyze log file
  decode     Decodes log from whatever encoding into UTF-8
  help       Displays help for a command
  list       Lists commands
  translate  Translates a log into english
```

Main usage is through the `analyze` command, e.g.:

```text
$ logchecker analyze --no_text path/to/file.log
Ripper  : EAC
Version : 1.0 beta 3
Language: en
Score   : 59
Checksum: checksum_ok
Details :
    Could not verify gap handling (-10 points)
    Could not verify id3 tag setting (-1 point)
    Range rip detected (-30 points)
```

### Code

```php
<?php

$logchecker = new OrpheusNET\Logchecker\Logchecker();
$logchecker->add_file('path/to/file.log');
list($score, $details, $checksum_state, $log_text) = $logchecker->parse();
```

## Library Usage

### Installation

```bash
composer require orpheusnet/logchecker
```

### Usage

```php
<?php

require __DIR__ . '/vendor/autoload.php';

use OrpheusNET\Logchecker\Logchecker;

$logchecker = new Logchecker();
$logchecker->newFile('/path/to/log/file');
$logchecker->parse();
print('Ripper   : ' . $logchecker->getRipper() . "\n");
print('Version  : ' . $logchecker->getRipperVersion() . "\n");
print('Score    : ' . $logchecker->getScore() . "\n");
print('Checksum : ' . $logchecker->getChecksumState() . "\n");
print("\nDetails:\n");
foreach ($logchecker->getDetails() as $detail) {
    print("  {$detail}\n");
}
print("\nLog Text:\n\n{$logchecker->getLog()}");
```

## Building

To build your own phar, see the `release.yml` workflow, but the gist is:

1. Clone this repo and enter repo
1. Install [box](https://github.com/box-project/box)
1. Run `box compile`
1. Get `logchecker.phar` in root of repo
