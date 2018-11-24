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
* chardet
* eac_logchecker.py
* xld_logchecker.py

## Installation
```
$ composer require orpheusnet/logchecker
$ pip3 install chardet
$ pip3 install eac-logchecker
```
