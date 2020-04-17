# Logchecker

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

Go to our [releases](https://github.com/OPSnet/Logchecker/releases) and grab the logchecker.phar
file. Download this file, and then it can executed via CLI by running `php logchecker.phar`.
Alternatively, if you `chmod +x logchecker.phar`, it can be executed directly by doing `./logchecker.phar`.

To install it globally, run:

```bash
mv logchecker.phar /usr/local/bin/logchecker
chmod +x /usr/local/bin/logchecker
```

### Usage

```
$ logchecker --help
Usage:
  analyze [options] [--] <file>

Arguments:
  file                  Log file to analyze

Options:
      --output          Print the HTML log text
  -h, --help            Display this help message
  -q, --quiet           Do not output any message
  -V, --version         Display this application version
      --ansi            Force ANSI output
      --no-ansi         Disable ANSI output
  -n, --no-interaction  Do not ask any interactive question
  -file, --out=OUT      File to write HTML log text to
  -v|vv|vvv, --verbose  Increase the verbosity of messages: 1 for normal output, 2 for more verbose output and 3 for debug

Help:
  This command analyzes a log file

$ logchecker tests/logs/wgdbcm.log
Score   : 57
Checksum: checksum_missing
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

To build your own phar, you can checkout this repository, and then
run the `bin/compile` script. To do this, run the following commands:

```bash
git clone https://github.com/OPSnet/Logchecker
cd Logchecker
composer install
php -d phar.readonly=0 bin/compile
```
