<?php

namespace OrpheusNET\Logchecker;

use OrpheusNET\Logchecker\Check\Ripper;
use OrpheusNET\Logchecker\Exception\UnknownLanguageException;
use OrpheusNET\Logchecker\Exception\UnknownRipperException;
use OrpheusNET\Logchecker\Parser\EAC\Translator;
use Symfony\Component\Yaml\Yaml;
use Symfony\Component\Yaml\Exception\ParseException;

if (!defined('LOGCHECKER_LEVENSTEIN_DISTANCE')) {
    define('LOGCHECKER_LEVENSTEIN_DISTANCE', 0);
}

/*******************************************************************
 * Automated EAC/XLD log checker *
 ********************************************************************/

class Logchecker
{
    private $log = '';
    private $logPath = null;
    private $logs = array();
    private $language = 'en';
    private $Tracks = array();
    private $checksumStatus = Check\Checksum::CHECKSUM_OK;
    private $Score = 100;
    private $Details = array();
    private $Offsets = array();
    private $DriveFound = false;
    private $AllDrives = [];
    private $Drives = [];
    private $SecureMode = true;
    private $NonSecureMode = null;
    private $BadTrack = array();
    private $DecreaseScoreTrack = 0;
    private $ripper = null;
    private $ripperVersion = null;
    private $TrackNumber = null;
    private $ARTracks = array();
    private $Combined = null;
    private $CurrLog = null;
    private $Range = null;
    private $ARSummary = null;
    private $XLDSecureRipper = false;
    private $FakeDrives = [
        'Generic DVD-ROM',
        'Generic DVD-ROM SCSI CdRom Device'
    ];

    private $ValidateChecksum = true;

    public function __construct()
    {
        $this->AllDrives = json_decode(
            file_get_contents(implode(DIRECTORY_SEPARATOR, [__DIR__, 'resources', 'drives.json']))
        );
    }

    public function getLog(): string
    {
        return $this->log;
    }

    /**
     * @param string $LogPath path to log file on local filesystem
     */
    public function newFile(string $LogPath): void
    {
        $this->reset();
        $this->logPath = $LogPath;
        $this->log = file_get_contents($this->logPath);
    }

    private function reset(): void
    {
        $this->logPath = null;
        $this->logs = array();
        $this->Tracks = array();
        $this->checksumStatus = Check\Checksum::CHECKSUM_OK;
        $this->Score = 100;
        $this->Details = array();
        $this->Offsets = array();
        $this->DriveFound = false;
        $this->Drives = array();
        $this->SecureMode = true;
        $this->NonSecureMode = null;
        $this->BadTrack = array();
        $this->DecreaseScoreTrack = 0;
        $this->ripper = null;
        $this->ripperVersion = null;
        $this->TrackNumber = null;
        $this->ARTracks = array();
        $this->Combined = null;
        $this->CurrLog = null;
        $this->Range = null;
        $this->ARSummary = null;
        $this->XLDSecureRipper = false;
    }

    public function validateChecksum(bool $Bool): void
    {
        $this->ValidateChecksum = $Bool;
    }

    public function parse(): void
    {
        try {
            $this->log = Util::decodeEncoding($this->log, $this->logPath);
        } catch (\Exception $exc) {
            $this->Score = 0;
            $this->account('Could not detect log encoding, log is corrupt.');
            return;
        }

        try {
            $this->ripper = Ripper::getRipper($this->log);
        } catch (UnknownRipperException $exc) {
            $this->Score = 0;
            $this->account('Unknown log file, could not determine ripper.');
            $this->ripper = Ripper::UNKNOWN;
            return;
        }

        if ($this->ripper === Ripper::WHIPPER) {
            $this->whipperParse();
        } else {
            $this->legacyParse();
        }
    }

    private function whipperParse(): void
    {
        if (preg_match('/whipper ([0-9]+\.[0-9]+\.[0-9])/', $this->log, $matches)) {
            if (version_compare('0.7.3', $matches[1]) === 1) {
                $this->account('Logs must be produced by whipper 0.7.3+.', 100);
                return;
            }
        }

        // Whipper 0.7.x has an issue where it can produce invalid YAML
        // as it hand writes out the values without dealing properly
        // with the escaping the output, so we fix that here
        $log = preg_replace_callback('/  (Release|Album): (.+)/', function ($match) {
            return "  {$match[1]}: " . Yaml::dump($match[2]);
        }, $this->log);

        // symfony/yaml will attempt to parse CRCs that start with 0 as octals
        $log = preg_replace_callback('/CRC: ([A-Z0-9]+)/', function ($match) {
            return "CRC: \"{$match[1]}\"";
        }, $log);

        try {
            $Yaml = Yaml::parse($log);
        } catch (ParseException $exception) {
            $this->account('Could not parse whipper log.', 100);
            return;
        }

        $this->ripperVersion = explode(" ", $Yaml['Log created by'])[1];

        // Releases before this used octal numbers for tracks in the log which
        // gets messed up in parsing and we lose track data (e.g. tracks 08 and
        // 09 get merged into one entry).
        if (empty($this->ripperVersion) || version_compare('0.7.3', $this->ripperVersion) === 1) {
            $this->account('Logs must be produced by whipper 0.7.3+', 100);
            return;
        }

        $Yaml['Log created by'] = preg_replace(
            '/^(whipper) ([^\s]+)/',
            "<span class='good'>$1</span> <span class='log1'>$2</span>",
            $Yaml['Log created by']
        );

        if (empty($Yaml['SHA-256 hash'])) {
            $this->checksumStatus = Check\Checksum::CHECKSUM_MISSING;
        } else {
            $this->checksumStatus = Check\Checksum::validate($this->logPath, $this->ripper);
            $Class = $this->checksumStatus === Check\Checksum::CHECKSUM_OK ? 'good' : 'bad';
            $Yaml['SHA-256 hash'] = "<span class='{$Class}'>{$Yaml['SHA-256 hash']}</span>";
        }

        $Key = 'Ripping phase information';
        $Drive = $Yaml[$Key]['Drive'];
        $Offset = $Yaml[$Key]['Read offset correction'];

        if (in_array(trim($Drive), $this->FakeDrives)) {
            $this->account('Virtual drive used: ' . $Drive, 20, false, false, false);
            $Yaml[$Key]['Drive'] = "<span class='bad'>{$Drive}</span>";
        } else {
            $this->getDrives($Drive);

            $DriveClass = 'badish';

            if (count($this->Drives) > 0) {
                $DriveClass = 'good';
                if (in_array((string) $Offset, $this->Offsets)) {
                    $OffsetClass = 'good';
                } else {
                    $OffsetClass = 'bad';
                    $this->account(
                        'Incorrect read offset for drive. Correct offsets are: ' .
                        implode(', ', $this->Offsets) . ' (Checked against the following drive(s): ' .
                        implode(', ', $this->Drives) . ')',
                        5,
                        false,
                        false,
                        false
                    );
                }
            } else {
                $Drive .= ' (not found in database)';
                $OffsetClass = 'badish';
                if ($Offset === '0') {
                    $OffsetClass = 'bad';
                    $this->account(
                        'The drive was not found in the database, so we cannot determine the correct read ' .
                        'offset. However, the read offset in this case was 0, which is almost never correct. ' .
                        'As such, we are assuming that the offset is incorrect',
                        5,
                        false,
                        false,
                        false
                    );
                }
            }
            $Yaml[$Key]['Drive'] = "<span class='{$DriveClass}'>{$Drive}</span>";
            $Offset = ($Offset > 0) ? '+' . (string) $Offset : (string) $Offset;
            $Yaml[$Key]['Read offset correction'] = "<span class='{$OffsetClass}'>{$Offset}</span>";
        }

        $DefeatCache = $Yaml[$Key]['Defeat audio cache'];
        if (is_string($DefeatCache)) {
            $Value = (strtolower($DefeatCache) === 'yes') ? 'Yes' : 'No';
            $Class = (strtolower($DefeatCache) === 'yes') ? 'good' : 'bad';
        } else {
            $Value = ($DefeatCache === true) ? 'true' : 'false';
            $Class = ($DefeatCache === true) ? 'good' : 'bad';
        }
        if ($Class === 'bad') {
            $this->account('"Defeat audio cache" should be Yes/true', 10);
        }

        $Yaml[$Key]['Defeat audio cache'] = "<span class='{$Class}'>{$Value}</span>";

        if (is_bool($Yaml[$Key]['Overread into lead-out'])) {
            $Yaml[$Key]['Overread into lead-out'] = $Yaml[$Key]['Overread into lead-out'] ? 'true' : 'false';
        }
        $Yaml[$Key]['Overread into lead-out'] = "<span class='log4'>{$Yaml[$Key]['Overread into lead-out']}</span>";

        // CD Metadata
        $Key = 'CD metadata';
        $ReleaseKey = isset($Yaml[$Key]['Release']) ? 'Release' : 'Album';
        if (is_string($Yaml[$Key][$ReleaseKey])) {
            $Yaml[$Key][$ReleaseKey] = "<span class='log4'>{$Yaml[$Key][$ReleaseKey]}</span>";
        } else {
            $Yaml[$Key][$ReleaseKey]['Artist'] = "<span class='log4'>{$Yaml[$Key][$ReleaseKey]['Artist']}</span>";
            $Yaml[$Key][$ReleaseKey]['Title'] = "<span class='log4'>{$Yaml[$Key][$ReleaseKey]['Title']}</span>";
        }

        // TOC
        foreach ($Yaml['TOC'] as &$Track) {
            foreach (['Start', 'Length', 'Start sector', 'End sector'] as $Key) {
                $Track[$Key] = "<span class='log1'>{$Track[$Key]}</span>";
            }
        }
        unset($Track);

        // Tracks
        foreach ($Yaml['Tracks'] as &$Track) {
            $Track['Peak level'] = sprintf('%.6f', $Track['Peak level']);
            $Class = 'good';
            if ($Track['Test CRC'] !== $Track['Copy CRC']) {
                $Class = 'bad';
                $this->account("CRC mismatch: {$Track['Test CRC']} and {$Track['Copy CRC']}", 30);
            }

            $Track['Test CRC'] = "<span class='{$Class}'>{$Track['Test CRC']}</span>";
            $Track['Copy CRC'] = "<span class='{$Class}'>{$Track['Copy CRC']}</span>";

            $Class = ($Track['Status'] === 'Copy OK') ? 'good' : 'bad';
            $Track['Status'] = "<span class='{$Class}'>{$Track['Status']}</span>";

            foreach (['Filename', 'Pre-gap length', 'Peak level', 'Extraction speed', 'Extraction quality'] as $Key) {
                if (!isset($Track[$Key])) {
                    continue;
                }
                $Track[$Key] = "<span class='log3'>{$Track[$Key]}</span>";
            }

            foreach (['v1', 'v2'] as $Version) {
                $Key = 'AccurateRip ' . $Version;
                if (isset($Track[$Key])) {
                    $Class = $Track[$Key]['Result']  === 'Found, exact match' ? 'good' : 'badish';
                    $Track[$Key]['Result'] = "<span class='{$Class}'>{$Track[$Key]['Result']}</span>";
                    if (isset($Track[$Key]['Local CRC']) && isset($Track[$Key]['Remote CRC'])) {
                        $Class = ($Track[$Key]['Local CRC'] === $Track[$Key]['Remote CRC']) ? 'goodish' : 'badish';
                        $Track[$Key]['Local CRC'] = "<span class='{$Class}'>{$Track[$Key]['Local CRC']}</span>";
                        $Track[$Key]['Remote CRC'] = "<span class='{$Class}'>{$Track[$Key]['Remote CRC']}</span>";
                    }
                }
            }
        }
        unset($Track);

        // Conclusive status report
        $Key = 'Conclusive status report';
        $Class = $Yaml[$Key]['AccurateRip summary'] === 'All tracks accurately ripped' ? 'good' : 'badish';
        $Yaml[$Key]['AccurateRip summary'] = "<span class='{$Class}'>{$Yaml[$Key]['AccurateRip summary']}</span>";

        $HealthKey = isset($Yaml[$Key]['Health Status']) ? 'Health Status' : 'Health status';
        $Class = $Yaml[$Key][$HealthKey] === 'No errors occurred' ? 'good' : 'bad';
        $Yaml[$Key][$HealthKey] = "<span class='{$Class}'>{$Yaml[$Key][$HealthKey]}</span>";

        $CreationDate = gmdate("Y-m-d\TH:i:s\Z", $Yaml['Log creation date']);

        $this->log = "Log created by: {$Yaml['Log created by']}\nLog creation date: {$CreationDate}\n\n";
        $this->log .= "Ripping phase information:\n";
        foreach ($Yaml['Ripping phase information'] as $Key => $Value) {
            if (is_bool($Value)) {
                $Value = ($Value) ? 'true' : 'false';
            }
            $this->log .= "  {$Key}: {$Value}\n";
        }
        $this->log .= "\n";

        $this->log .= "CD metadata:\n";
        foreach ($Yaml['CD metadata'] as $Key => $Value) {
            if (is_array($Value)) {
                $this->log .= "  {$Key}:\n";
                foreach ($Value as $KKey => $VValue) {
                    $this->log .= "    {$KKey}: {$VValue}\n";
                }
            } else {
                if (is_bool($Value)) {
                    $Value = ($Value) ? 'true' : 'false';
                }
                $this->log .= "  {$Key}: {$Value}\n";
            }
        }
        $this->log .= "\n";

        $this->log .= "TOC:\n";
        foreach ($Yaml['TOC'] as $Key => $Track) {
            $this->log .= "  {$Key}:\n";
            foreach ($Track as $KKey => $Value) {
                $this->log .= "    {$KKey}: {$Value}\n";
            }
            $this->log .= "\n";
        }

        $this->log .= "Tracks:\n";
        foreach ($Yaml['Tracks'] as $Key => $Track) {
            $this->log .= "  {$Key}:\n";
            foreach ($Track as $KKey => $Value) {
                if (is_array($Value)) {
                    $this->log .= "    {$KKey}:\n";
                    foreach ($Value as $KKKey => $VValue) {
                        $this->log .= "      {$KKKey}: {$VValue}\n";
                    }
                } else {
                    if (is_bool($Value)) {
                        $Value = ($Value) ? 'Yes' : 'No';
                    }
                    $this->log .= "    {$KKey}: {$Value}\n";
                }
            }
            $this->log .= "\n";
        }

        $this->log .= "Conclusive status report:\n";
        foreach ($Yaml['Conclusive status report'] as $Key => $Value) {
            $this->log .= "  {$Key}: {$Value}\n";
        }
        $this->log .= "\n";
        if (isset($Yaml['SHA-256 hash'])) {
            $this->log .= "SHA-256 hash: {$Yaml['SHA-256 hash']}\n";
        }
    }

    private function legacyParse()
    {
        if ($this->ripper === Ripper::EAC) {
            $translator = new Translator();
            try {
                $lang = $translator->getLanguage($this->log);
                if ($lang['code'] !== 'en') {
                    $this->language = $lang['code'];
                    $this->account(
                        "Translated log from {$lang['name']} ({$lang['name_english']}) to English.",
                        false,
                        false,
                        false,
                        true
                    );
                    $this->log = $translator->translate($this->log, $lang['code']);
                }
            } catch (UnknownLanguageException $exc) {
                $this->language = 'en';
                $this->account('Could not determine language. Assuming English.', false, false, false, true);
            }
        }

        $this->log = str_replace(array("\r\n", "\r"), array("\n", ""), $this->log);

        // Split the log apart
        if (preg_match("/[\=]+\s+Log checksum/i", $this->log)) { // eac checksum
            $this->logs = preg_split("/(\n\=+\s+Log checksum.*)/i", $this->log, -1, PREG_SPLIT_DELIM_CAPTURE);
        } elseif (
            preg_match(
                "/[\-]+BEGIN XLD SIGNATURE[\S\n\-]+END XLD SIGNATURE[\-]+/i",
                $this->log
            )
        ) { // xld checksum (plugin)
            $this->logs = preg_split(
                "/(\n[\-]+BEGIN XLD SIGNATURE[\S\n\-]+END XLD SIGNATURE[\-]+)/i",
                $this->log,
                -1,
                PREG_SPLIT_DELIM_CAPTURE
            );
        } else { //no checksum
            $this->checksumStatus = Check\Checksum::CHECKSUM_MISSING;
            $this->logs = preg_split("/(\nEnd of status report)/i", $this->log, -1, PREG_SPLIT_DELIM_CAPTURE);
            foreach ($this->logs as $Key => $Value) {
                if (preg_match("/---- CUETools DB Plugin V.+/i", $Value)) {
                    unset($this->logs[$Key]);
                }
            }
        }

        foreach ($this->logs as $Key => $Log) {
            $Log = trim($Log);
            if ($Log === "" || preg_match('/^\-+$/i', $Log)) {
                unset($this->logs[$Key]);
            } elseif (
                $this->checksumStatus !== Check\Checksum::CHECKSUM_OK
                && preg_match("/End of status report/i", $Log)
            ) {
                //strip empty
                //append stat msgs
                $this->logs[$Key - 1] .= $Log;
                unset($this->logs[$Key]);
            } elseif (
                $this->checksumStatus === Check\Checksum::CHECKSUM_OK
                && preg_match("/[\=]+\s+Log checksum/i", $Log)
            ) {
                $this->logs[$Key - 1] .= $Log;
                unset($this->logs[$Key]);
            } elseif (
                $this->checksumStatus === Check\Checksum::CHECKSUM_OK
                && preg_match("/[\-]+BEGIN XLD SIGNATURE/i", $Log)
            ) {
                $this->logs[$Key - 1] .= $Log;
                unset($this->logs[$Key]);
            }
        }

        $this->logs = array_values($this->logs); //rebuild index
        if (count($this->logs) > 1) {
            $this->Combined = count($this->logs);
        } //is_combined
        foreach ($this->logs as $LogArrayKey => $Log) {
            $this->CurrLog = $LogArrayKey + 1;
            if (preg_match('/Exact Audio Copy (.+) from/i', $Log, $Matches)) { //eac v1 & checksum
                if ($Matches[1]) {
                    $this->ripperVersion = ltrim($Matches[1], 'V');
                    $versionCheck = explode(" ", $this->ripperVersion)[0];
                    if (version_compare($versionCheck, "1.0") < 0) {
                        $this->checksumStatus = Check\Checksum::CHECKSUM_MISSING;
                        if ($this->ripperVersion <= 0.95) {
                            # EAC 0.95 and before was missing a handful of stuff for full log validation
                            # that 0.99 included (-30 points)
                            $this->account("EAC version older than 0.99", 30);
                        }
                    } elseif (!preg_match('/(\=+\s+Log checksum.*)/i', $Log)) {
                        // Above version 1 and no checksum
                        $this->checksumStatus = Check\Checksum::CHECKSUM_MISSING;
                    }
                } else {
                    $this->checksumStatus = Check\Checksum::CHECKSUM_MISSING;
                    $this->account("EAC version older than 0.99", 30);
                }
            } elseif (preg_match('/EAC extraction logfile from/i', $Log)) {
                $this->checksumStatus = Check\Checksum::CHECKSUM_MISSING;
                $this->account("EAC version older than 0.99", 30);
            }

            if (preg_match('/X Lossless Decoder version (\d+) \((.+)\)/i', $Log, $Matches)) { //xld version & checksum
                $this->ripperVersion = $Matches[1];
                if (
                    version_compare($this->ripperVersion, "20121222") >= 0
                    && !preg_match('/([\-]+BEGIN XLD SIGNATURE[\S\n\-]+END XLD SIGNATURE[\-]+)/i', $Log)
                ) {
                    $this->checksumStatus = Check\Checksum::CHECKSUM_MISSING;
                    //$this->account('No checksum with XLD 20121222 or newer', 15);
                }
            }
            $Log = preg_replace(
                '/Exact Audio Copy (.+) from (.+)/i',
                'Exact Audio Copy <span class="log1">$1</span> from <span class="log1">$2</span>',
                $Log,
                1,
                $Count
            );
            $Log = preg_replace(
                "/EAC extraction logfile from (.+)\n+(.+)/i",
                "<span class='good'>EAC extraction logfile from <span class='log5'>$1</span></span>\n\n" .
                    '<span class="log4">$2</span>',
                $Log,
                1,
                $EAC
            );
            $Log = preg_replace(
                "/X Lossless Decoder version (.+) \((.+)\)/i",
                "X Lossless Decoder version <span class=\"log1\">$1</span> (<span class=\"log1\">$2</span>)",
                $Log,
                1,
                $Count
            );
            $Log = preg_replace(
                "/XLD extraction logfile from (.+)\n+(.+)/i",
                "<span class='good'>XLD extraction logfile from <span class='log5'>$1</span></span>\n\n" .
                    '<span class="log4">$2</span>',
                $Log,
                1,
                $XLD
            );

            if (!$EAC && !$XLD) {
                if ($this->Combined) {
                    unset($this->Details);
                    $this->Details[] = "Combined Log (" . $this->Combined . ")";
                    $this->Details[] = "Unrecognized log file (" . $this->CurrLog . ")! " .
                        "Feel free to report for manual review.";
                } else {
                    $this->Details[] = "Unrecognized log file! Feel free to report for manual review.";
                }
                $this->Score = 0;
                return;
            }

            if (
                $this->ValidateChecksum
                && $this->checksumStatus === Check\Checksum::CHECKSUM_OK
                && !empty($this->logPath)
            ) {
                if (Check\Checksum::logcheckerExists($this->ripper)) {
                    $this->checksumStatus = Check\Checksum::validate($this->logPath, $this->ripper);
                } else {
                    $this->account(
                        "Could not find {$this->ripper} logchecker, checksum not validated.",
                        false,
                        false,
                        false,
                        true
                    );
                }
            }

            $Class = $this->checksumStatus === Check\Checksum::CHECKSUM_OK ? 'good' : 'bad';
            $Log = preg_replace('/(\=+\s+Log checksum.*)/i', "<span class='{$Class}'>$1</span>", $Log, 1, $eacCount);
            $Log = preg_replace(
                '/([\-]+BEGIN XLD SIGNATURE[\S\n\-]+END XLD SIGNATURE[\-]+)/i',
                "<span class='{$Class}'>$1</span>",
                $Log,
                1,
                $xldCount
            );
            // EAC will at least output "no checksum" for some malformed logs with checksums
            if (($eacCount > 0 || $xldCount > 0) && $this->checksumStatus === Check\Checksum::CHECKSUM_MISSING) {
                $this->checksumStatus = Check\Checksum::CHECKSUM_INVALID;
            }

            if (
                $EAC
                && (
                    preg_match("/Used output format[ ]+:[ ]+[a-z0-9 ]+MP3/i", $Log) === 1
                    || preg_match("/Command line compressor[ ]+:.+(MP3|lame)\.exe/i", $Log) === 1
                )
            ) {
                if ($this->Combined) {
                    $this->Details[] = "Skipping Log (" . $this->CurrLog . "), MP3 Rip";
                } else {
                    $this->account("Invalid Log (MP3)", 100);
                }
                $this->logs[$LogArrayKey] = $Log;
                continue;
            }

            $Log = preg_replace_callback("/Used drive( *): (.+)/i", array(
                $this,
                'drive'
            ), $Log, 1, $Count);
            if (!$Count) {
                $this->account('Could not verify used drive', 1);
            }
            $Log = preg_replace_callback("/Media type( *): (.+)/i", array(
                $this,
                'mediaTypeXld'
            ), $Log, 1, $Count);
            if ($XLD && $this->ripperVersion && $this->ripperVersion >= 20130127 && !$Count) {
                $this->account('Could not verify media type', 1);
            }
            $Log = preg_replace_callback('/Read mode( *): ([a-z]+)(.*)?/i', array(
                $this,
                'readMode'
            ), $Log, 1, $Count);
            if (!$Count && $EAC) {
                $this->account('Could not verify read mode', 1);
            }
            $Log = preg_replace_callback('/Ripper mode( *): (.*)/i', array(
                $this,
                'ripperModeXld'
            ), $Log, 1, $XLDRipperMode);
            $Log = preg_replace_callback('/Use cdparanoia mode( *): (.*)/i', array(
                $this,
                'cdparanoiaModeXld'
            ), $Log, 1, $XLDCDParanoiaMode);
            if (!$XLDRipperMode && !$XLDCDParanoiaMode && $XLD) {
                $this->account('Could not verify read mode', 1);
            }
            $Log = preg_replace_callback('/Max retry count( *): (\d+)/i', array(
                $this,
                'maxRetryCount'
            ), $Log, 1, $Count);
            if (!$Count && $XLD) {
                $this->account('Could not verify max retry count');
            }
            $Log = preg_replace_callback('/Utilize accurate stream( *): (Yes|No)/i', array(
                $this,
                'accurateStream'
            ), $Log, 1, $EAC_ac_stream);
            $Log = preg_replace_callback('/, (|NO )accurate stream/i', array(
                $this,
                'accurateStreamEacPre9'
            ), $Log, 1, $EAC_ac_stream_pre99);
            if (!$EAC_ac_stream && !$EAC_ac_stream_pre99 && !$this->NonSecureMode && $EAC) {
                $this->account('Could not verify accurate stream', 20);
            }
            $Log = preg_replace_callback('/Defeat audio cache( *): (Yes|No)/i', array(
                $this,
                'defeatAudioCache'
            ), $Log, 1, $EAC_defeat_cache);
            $Log = preg_replace_callback('/ (|NO )disable cache/i', array(
                $this,
                'defeatAudioCacheEacPre99'
            ), $Log, 1, $EAC_defeat_cache_pre99);
            if (!$EAC_defeat_cache && !$EAC_defeat_cache_pre99 && !$this->NonSecureMode && $EAC) {
                $this->account('Could not verify defeat audio cache', 1);
            }
            $Log = preg_replace_callback('/Disable audio cache( *): (.*)/i', array(
                $this,
                'defeatAudioCacheXld'
            ), $Log, 1, $Count);
            if (!$Count && $XLD) {
                $this->account('Could not verify defeat audio cache', 1);
            }
            $Log = preg_replace_callback('/Make use of C2 pointers( *): (Yes|No)/i', array(
                $this,
                'c2Pointers'
            ), $Log, 1, $C2);
            $Log = preg_replace_callback('/with (|NO )C2/i', array(
                $this,
                'c2PointersEacPre99'
            ), $Log, 1, $C2_EACpre99);
            if (!$C2 && !$C2_EACpre99 && !$this->NonSecureMode) {
                $this->account('Could not verify C2 pointers', 1);
            }
            $Log = preg_replace_callback('/Read offset correction( *): ([+-]?[0-9]+)/i', array(
                $this,
                'readOffset'
            ), $Log, 1, $Count);
            if (!$Count) {
                $this->account('Could not verify read offset', 1);
            }
            $Log = preg_replace(
                "/(Combined read\/write offset correction\s*:\s+\d+)/i",
                "<span class=\"bad\">$1</span>",
                $Log,
                1,
                $Count
            );
            if ($Count) {
                $this->account('Combined read/write offset cannot be verified', 4, false, false, false);
            }
            //xld alternate offset table
            $Log = preg_replace(
                "/(List of \w+ offset correction values) *(\n+)(( *.*confidence .*\) ?\n)+)/i",
                "<span class=\"log5\">$1</span>$2<span class=\"log4\">$3</span>\n",
                $Log,
                1,
                $Count
            );
            $Log = preg_replace(
                "/(List of \w+ offset correction values) *\n( *\# +\| +Absolute +\| +Relative +\| +Confidence) *\n" .
                    "( *\-+) *\n(( *\d+ +\| +\-?\+?\d+ +\| +\-?\+?\d+ +\| +\d+ *\n)+)/i",
                "<span class=\"log5\">$1</span>\n<span class=\"log4\">$2\n$3\n$4\n</span>",
                $Log,
                1,
                $Count
            );
            $Log = preg_replace(
                '/Overread into Lead-In and Lead-Out( +): (Yes|No)/i',
                '<span class="log5">Overread into Lead-In and Lead-Out$1</span>: <span class="log4">$2</span>',
                $Log,
                1,
                $Count
            );
            $Log = preg_replace_callback('/Fill up missing offset samples with silence( +): (Yes|No)/i', array(
                $this,
                'fillOffsetSamples'
            ), $Log, 1, $Count);
            if (!$Count && $EAC) {
                $this->account('Could not verify missing offset samples', 1);
            }
            $Log = preg_replace_callback('/Delete leading and trailing silent blocks([ \w]*)( +): (Yes|No)/i', array(
                $this,
                'deleteSilentBlocks'
            ), $Log, 1, $Count);
            if (!$Count && $EAC) {
                $this->account('Could not verify silent blocks', 1);
            }
            $Log = preg_replace_callback('/Null samples used in CRC calculations( +): (Yes|No)/i', array(
                $this,
                'nullSamples'
            ), $Log, 1, $Count);
            if (!$Count && $EAC) {
                $this->account('Could not verify null samples');
            }
            $Log = preg_replace_callback('/Normalize to( +): ([0-9% ]+)/i', array(
                $this,
                'normalizeEac'
            ), $Log, 1);
            $Log = preg_replace(
                '/Used interface( +): ([^\n]+)/i',
                '<span class="log5">Used interface$1</span>: <span class="log4">$2</span>',
                $Log,
                1,
                $Count
            );
            $Log = preg_replace_callback('/Gap handling( +): ([^\n]+)/i', array(
                $this,
                'gapHandling'
            ), $Log, 1, $Count);
            if (!$Count && $EAC) {
                $this->account('Could not verify gap handling', 10);
            }
            $Log = preg_replace_callback('/Gap status( +): (.*)/i', array(
                $this,
                'gapHandlingXld'
            ), $Log, 1, $Count);
            if (!$Count && $XLD) {
                $this->account('Could not verify gap status', 10);
            }
            $Log = preg_replace(
                '/Used output format( *): ([^\n]+)/i',
                '<span class="log5">Used output format$1</span>: <span class="log4">$2</span>',
                $Log,
                1,
                $Count
            );
            $Log = preg_replace(
                '/Sample format( +): ([^\n]+)/i',
                '<span class="log5">Sample format$1</span>: <span class="log4">$2</span>',
                $Log,
                1,
                $Count
            );
            $Log = preg_replace(
                '/Selected bitrate( +): ([^\n]+)/i',
                '<span class="log5">Selected bitrate$1</span>: <span class="log4">$2</span>',
                $Log,
                1,
                $Count
            );
            $Log = preg_replace(
                '/( +)(\d+ kBit\/s)/i',
                '$1<span class="log4">$2</span>',
                $Log,
                1,
                $Count
            );
            $Log = preg_replace(
                '/Quality( +): ([^\n]+)/i',
                '<span class="log5">Quality$1</span>: <span class="log4">$2</span>',
                $Log,
                1,
                $Count
            );
            $Log = preg_replace_callback('/Add ID3 tag( +): (Yes|No)/i', array(
                $this,
                'addId3Tag'
            ), $Log, 1, $Count);
            if (!$Count && $EAC) {
                $this->account('Could not verify id3 tag setting', 1);
            }
            $Log = preg_replace(
                "/(Use compression offset\s+:\s+\d+)/i",
                "<span class=\"bad\">$1</span>",
                $Log,
                1,
                $Count
            );
            if ($Count) {
                $this->account('Ripped with compression offset', false, 0);
            }
            $Log = preg_replace(
                '/Command line compressor( +): ([^\n]+)/i',
                '<span class="log5">Command line compressor$1</span>: <span class="log4">$2</span>',
                $Log,
                1,
                $Count
            );
            $Log = preg_replace(
                "/Additional command line options([^\n]{70,110} )/",
                "Additional command line options$1<br>",
                $Log
            );
            $Log = preg_replace(
                '/( *)Additional command line options( +): (.+)\n/i',
                '<span class="log5">Additional command line options$2</span>: <span class="log4">$3</span>' . "\n",
                $Log,
                1,
                $Count
            );
            // xld album gain
            $Log = preg_replace(
                "/All Tracks\s*\n(\s*Album gain\s+:) (.*)?\n(\s*Peak\s+:) (.*)?/i",
                "<span class=\"log5\">All Tracks</span>\n<strong>$1 <span class=\"log3\">$2</span>\n" .
                    "$3 <span class=\"log3\">$4</span></strong>",
                $Log,
                1,
                $Count
            );
            if (!$Count && $XLD) {
                $this->account('Could not verify album gain');
            }
            // pre-0.99
            $Log = preg_replace(
                '/Other options( +):/i',
                '<span class="log5">Other options$1</span>:',
                $Log,
                1,
                $Count
            );
            $Log = preg_replace(
                '/\n( *)Native Win32 interface(.+)/i',
                "\n$1<span class=\"log4\">Native Win32 interface$2</span>",
                $Log,
                1,
                $Count
            );
            // 0.99
            $Log = str_replace(
                'TOC of the extracted CD',
                '<span class="log4 log5">TOC of the extracted CD</span>',
                $Log
            );
            $Log = preg_replace(
                '/( +)Track( +)\|( +)Start( +)\|( +)Length( +)\|( +)Start sector( +)\|( +)End sector( ?)/i',
                '<strong>$0</strong>',
                $Log
            );
            $Log = preg_replace('/-{10,100}/', '<strong>$0</strong>', $Log);
            $Log = preg_replace_callback(
                '/( +)([0-9]{1,3})( +)\|( +)(([0-9]{1,3}:)?[0-9]{2}[\.:][0-9]{2})( +)\|' .
                    '( +)(([0-9]{1,3}:)?[0-9]{2}[\.:][0-9]{2})( +)\|( +)([0-9]{1,10})( +)\|' .
                    '( +)([0-9]{1,10})( +)\n/i',
                [
                    $this,
                    'toc'
                ],
                $Log
            );
            $Log = str_replace(
                'None of the tracks are present in the AccurateRip database',
                '<span class="badish">None of the tracks are present in the AccurateRip database</span>',
                $Log
            );
            $Log = str_replace(
                'Disc not found in AccurateRip DB.',
                '<span class="badish">Disc not found in AccurateRip DB.</span>',
                $Log
            );
            $Log = preg_replace('/No errors occurr?ed/i', '<span class="good">No errors occurred</span>', $Log);
            $Log = preg_replace("/(There were errors) ?\n/i", "<span class=\"bad\">$1</span>\n", $Log);
            $Log = preg_replace("/(Some inconsistencies found) ?\n/i", "<span class=\"badish\">$1</span>\n", $Log);
            $Log = preg_replace('/End of status report/i', '<span class="good">End of status report</span>', $Log);
            $Log = preg_replace(
                '/Track(\s*)Ripping Status(\s*)\[Disc ID: ([0-9a-f]{8}-[0-9a-f]{8})\]/i',
                '<strong>Track</strong>$1<strong>Ripping Status</strong>$2<strong>Disc ID: </strong>' .
                    '<span class="log1">$3</span>',
                $Log
            );
            $Log = preg_replace('/(All Tracks Accurately Ripped\.?)/i', '<span class="good">$1</span>', $Log);
            $Log = preg_replace("/\d+ track.* +accurately ripped\.? *\n/i", '<span class="good">$0</span>', $Log);
            $Log = preg_replace(
                "/\d+ track.* +not present in the AccurateRip database\.? *\n/i",
                '<span class="badish">$0</span>',
                $Log
            );
            $Log = preg_replace("/\d+ track.* +canceled\.? *\n/i", '<span class="bad">$0</span>', $Log);
            $Log = preg_replace(
                "/\d+ track.* +could not be verified as accurate\.? *\n/i",
                '<span class="badish">$0</span>',
                $Log
            );
            $Log = preg_replace(
                "/Some tracks could not be verified as accurate\.? *\n/i",
                '<span class="badish">$0</span>',
                $Log
            );
            $Log = preg_replace(
                "/No tracks could be verified as accurate\.? *\n/i",
                '<span class="badish">$0</span>',
                $Log
            );
            $Log = preg_replace("/You may have a different pressing.*\n/i", '<span class="goodish">$0</span>', $Log);
            //xld accurip summary
            $Log = preg_replace_callback("/(Track +\d+ +: +)(OK +)\(A?R?\d?,? ?confidence +(\d+).*?\)(.*)\n/i", array(
                $this,
                'arSummaryConfXld'
            ), $Log);
            $Log = preg_replace_callback("/(Track +\d+ +: +)(NG|Not Found).*?\n/i", array(
                $this,
                'arSummaryConfXld'
            ), $Log);
            $Log = preg_replace( //Status line
                "/( *.{2} ?)(\d+ track\(s\).*)\n/i",
                "$1<span class=\"log4\">$2</span>\n",
                $Log,
                1
            );
            //(..) may need additional entries
            //accurip summary (range)
            $Log = preg_replace(
                "/\n( *AccurateRip summary\.?)/i",
                "\n<span class=\"log4 log5\">$1</span>",
                $Log
            );
            $Log = preg_replace_callback(
                "/(Track +\d+ +.*?accurately ripped\.? *)(\(confidence +)(\d+)\)(.*)\n/i",
                [
                    $this,
                    'arSummaryConf'
                ],
                $Log
            );
            $Log = preg_replace(
                "/(Track +\d+ +.*?in database *)\n/i",
                "<span class=\"badish\">$1</span>\n",
                $Log,
                -1,
                $Count
            );
            if ($Count) {
                $this->ARSummary['bad'] = $Count;
            }
            $Log = preg_replace(
                "/(Track +\d+ +.*?(could not|cannot) be verified as accurate.*)\n/i",
                "<span class=\"badish\">$1</span>\n",
                $Log,
                -1,
                $Count
            );
            if ($Count) {
                $this->ARSummary['bad'] = $Count;
            } //don't mind the actual count
            //range rip
            $Log = preg_replace("/\n( *Selected range)/i", "\n<span class=\"bad\">$1</span>", $Log, 1, $Range1);
            $Log = preg_replace(
                '/\n( *Range status and errors)/i',
                "\n<span class=\"bad\">$1</span>",
                $Log,
                1,
                $Range2
            );
            if ($Range1 || $Range2) {
                $this->Range = 1;
                $this->account('Range rip detected', 30);
            }
            $FormattedTrackListing = '';
            //------ Handle individual tracks ------//
            if (!$this->Range) {
                preg_match('/\nTrack( +)([0-9]{1,3})([^<]+)/i', $Log, $Matches);
                $TrackListing = $Matches[0];
                $FullTracks   = preg_split('/\nTrack( +)([0-9]{1,3})/i', $TrackListing, -1, PREG_SPLIT_DELIM_CAPTURE);
                array_shift($FullTracks);
                $TrackBodies = preg_split('/\nTrack( +)([0-9]{1,3})/i', $TrackListing, -1);
                array_shift($TrackBodies);
                //------ Range rip ------//
            } else {
                preg_match('/\n( +)Filename +(.*)([^<]+)/i', $Log, $Matches);
                $TrackListing = $Matches[0];
                $FullTracks   = preg_split('/\n( +)Filename +(.*)/i', $TrackListing, -1, PREG_SPLIT_DELIM_CAPTURE);
                array_shift($FullTracks);
                $TrackBodies = preg_split('/\n( +)Filename +(.*)/i', $TrackListing, -1);
                array_shift($TrackBodies);
            }
            $Tracks = array();
            foreach ($TrackBodies as $Key => $TrackBody) {
                // The number of spaces between 'Track' and the number, to keep formatting intact
                $Spaces                = $FullTracks[($Key * 3)];
                // Track number
                $TrackNumber           = $FullTracks[($Key * 3) + 1];
                $this->TrackNumber     = $TrackNumber;
                // How much to decrease the overall score by, if this track fails and
                // no attempt at recovery is made later on
                $this->DecreaseScoreTrack = 0;
                // List of things that went wrong to add to $this->Bad if this track fails and
                // no attempt at recovery is made later on
                $this->BadTrack        = array();
                // The track number is stripped in the preg_split, let's bring it back, eh?
                if (!$this->Range) {
                    $TrackBody = '<span class="log5">Track</span>' . $Spaces . '<span class="log4 log1">' .
                        $TrackNumber . '</span>' . $TrackBody;
                } else {
                    $TrackBody = $Spaces . '<span class="log5">Filename</span> <span class="log4 log3">' .
                        $TrackNumber . '</span>' . $TrackBody;
                }
                /* match newline for xld multifile encodes */
                $TrackBody = preg_replace(
                    '/Filename ((.+)?\.(wav|flac|ape))\n/is',
                    "<span class=\"log4\">Filename <span class=\"log3\">$1</span></span>\n",
                    $TrackBody,
                    -1,
                    $Count
                );
                if (!$Count && !$this->Range) {
                    // EAC truncates file names that would exceed 243 characters, so we'll just mark
                    // it as fine, as it almost always is, and if it wouldn't have been, then
                    // some other filename will have hit the -1 deduction anyway.
                    if ($EAC) {
                        if (preg_match('/Filename (.+)\n/i', $TrackBody, $Matches) && strlen($Matches[1]) >= 243) {
                            $TrackBody = preg_replace(
                                '/Filename ((.+)?)\n/i',
                                "<span class=\"log4\">Filename <span class=\"log3\">$1</span></span>\n",
                                $TrackBody,
                                -1,
                                $Count
                            );
                            $this->accountTrack('Could not verify filename, too long');
                        } else {
                            $this->accountTrack('Could not verify filename or file extension', 1);
                        }
                    } else {
                        $this->accountTrack('Could not verify filename or file extension', 1);
                    }
                }
                // xld track gain
                $TrackBody = preg_replace(
                    "/( *Track gain\s+:) (.*)?\n(\s*Peak\s+:) (.*)?/i",
                    "<strong>$1 <span class=\"log3\">$2</span>\n$3 <span class=\"log3\">$4</span></strong>",
                    $TrackBody,
                    -1,
                    $Count
                );
                $TrackBody = preg_replace(
                    '/( +)(Statistics *)\n/i',
                    "$1<span class=\"log5\">$2</span>\n",
                    $TrackBody,
                    -1,
                    $Count
                );
                $TrackBody = preg_replace_callback('/(Read error)( +:) (\d+)/i', array(
                    $this,
                    'xldStat'
                ), $TrackBody, -1, $Count);
                if (!$Count && $XLD) {
                    $this->accountTrack('Could not verify read errors');
                }
                $TrackBody = preg_replace_callback('/(Skipped \(treated as error\))( +:) (\d+)/i', array(
                    $this,
                    'xldStat'
                ), $TrackBody, -1, $Count);
                if (!$Count && $XLD && !$this->XLDSecureRipper) {
                    $this->accountTrack('Could not verify skipped errors');
                }
                $TrackBody = preg_replace_callback('/(Edge jitter error \(maybe fixed\))( +:) (\d+)/i', array(
                    $this,
                    'xldStat'
                ), $TrackBody, -1, $Count);
                if (!$Count && $XLD && !$this->XLDSecureRipper) {
                    $this->accountTrack('Could not verify edge jitter errors');
                }
                $TrackBody = preg_replace_callback('/(Atom jitter error \(maybe fixed\))( +:) (\d+)/i', array(
                    $this,
                    'xldStat'
                ), $TrackBody, -1, $Count);
                if (!$Count && $XLD && !$this->XLDSecureRipper) {
                    $this->accountTrack('Could not verify atom jitter errors');
                }
                $TrackBody = preg_replace_callback( //xld secure ripper
                    '/(Jitter error \(maybe fixed\))( +:) (\d+)/i',
                    array(
                    $this,
                    'xldStat'
                    ),
                    $TrackBody,
                    -1,
                    $Count
                );
                if (!$Count && $XLD && $this->XLDSecureRipper) {
                    $this->accountTrack('Could not verify jitter errors');
                }
                $TrackBody = preg_replace_callback( //xld secure ripper
                    '/(Retry sector count)( +:) (\d+)/i',
                    array(
                    $this,
                    'xldStat'
                    ),
                    $TrackBody,
                    -1,
                    $Count
                );
                if (!$Count && $XLD && $this->XLDSecureRipper) {
                    $this->accountTrack('Could not verify retry sector count');
                }
                $TrackBody = preg_replace_callback( //xld secure ripper
                    '/(Damaged sector count)( +:) (\d+)/i',
                    array(
                    $this,
                    'xldStat'
                    ),
                    $TrackBody,
                    -1,
                    $Count
                );
                if (!$Count && $XLD && $this->XLDSecureRipper) {
                    $this->accountTrack('Could not verify damaged sector count');
                }
                $TrackBody = preg_replace_callback('/(Drift error \(maybe fixed\))( +:) (\d+)/i', array(
                    $this,
                    'xldStat'
                ), $TrackBody, -1, $Count);
                if (!$Count && $XLD && !$this->XLDSecureRipper) {
                    $this->accountTrack('Could not verify drift errors');
                }
                $TrackBody = preg_replace_callback('/(Dropped bytes error \(maybe fixed\))( +:) (\d+)/i', array(
                    $this,
                    'xldStat'
                ), $TrackBody, -1, $Count);
                if (!$Count && $XLD && !$this->XLDSecureRipper) {
                    $this->accountTrack('Could not verify dropped bytes errors');
                }
                $TrackBody = preg_replace_callback(
                    '/(Duplicated bytes error \(maybe fixed\))( +:) (\d+)/i',
                    [
                        $this,
                        'xldStat'
                    ],
                    $TrackBody,
                    -1,
                    $Count
                );
                if (!$Count && $XLD && !$this->XLDSecureRipper) {
                    $this->accountTrack('Could not verify duplicated bytes errors');
                }
                $TrackBody = preg_replace_callback('/(Inconsistency in error sectors)( +:) (\d+)/i', array(
                    $this,
                    'xldStat'
                ), $TrackBody, -1, $Count);
                if (!$Count && $XLD && !$this->XLDSecureRipper) {
                    $this->accountTrack('Could not verify inconsistent error sectors');
                }
                $TrackBody = preg_replace(
                    "/(List of suspicious positions +)(: *\n?)(( *.* +\d{2}:\d{2}:\d{2} *\n)+)/i",
                    '<span class="bad">$1</span><strong>$2</strong><span class="bad">$3</span></span>',
                    $TrackBody,
                    -1,
                    $Count
                );
                if ($Count) {
                    $this->accountTrack('Suspicious position(s) found', 20);
                }
                $TrackBody = preg_replace(
                    '/Suspicious position( +)([0-9]:[0-9]{2}:[0-9]{2})/i',
                    '<span class="bad">Suspicious position$1<span class="log4">$2</span></span>',
                    $TrackBody,
                    -1,
                    $Count
                );
                if ($Count) {
                    $this->accountTrack('Suspicious position(s) found', 20);
                }
                $TrackBody = preg_replace(
                    '/Timing problem( +)([0-9]:[0-9]{2}:[0-9]{2})/i',
                    '<span class="bad">Timing problem$1<span class="log4">$2</span></span>',
                    $TrackBody,
                    -1,
                    $Count
                );
                if ($Count) {
                    $this->accountTrack('Timing problem(s) found', 20);
                }
                $TrackBody = preg_replace(
                    '/Missing samples/i',
                    '<span class="bad">Missing samples</span>',
                    $TrackBody,
                    -1,
                    $Count
                );
                if ($Count) {
                    $this->accountTrack('Missing sample(s) found', 20);
                }
                $TrackBody = preg_replace(
                    '/Copy aborted/i',
                    '<span class="bad">Copy aborted</span>',
                    $TrackBody,
                    -1,
                    $Count
                );
                if ($Count) {
                    $Aborted = true;
                    $this->accountTrack('Copy aborted', 100);
                } else {
                    $Aborted = false;
                }
                $TrackBody = preg_replace(
                    '/Pre-gap length( +|\s+:\s+)([0-9]{1,2}:[0-9]{2}:[0-9]{2}.?[0-9]{0,2})/i',
                    '<span class="log4">Pre-gap length$1<span class="log3">$2</span></span>',
                    $TrackBody,
                    -1,
                    $Count
                );
                $TrackBody = preg_replace(
                    '/Peak level ([0-9]{1,3}\.[0-9] %)/i',
                    '<span class="log4">Peak level <span class="log3">$1</span></span>',
                    $TrackBody,
                    -1,
                    $Count
                );
                $TrackBody = preg_replace(
                    '/Extraction speed ([0-9]{1,3}\.[0-9]{1,} X)/i',
                    '<span class="log4">Extraction speed <span class="log3">$1</span></span>',
                    $TrackBody,
                    -1,
                    $Count
                );
                $TrackBody = preg_replace(
                    '/Track quality ([0-9]{1,3}\.[0-9] %)/i',
                    '<span class="log4">Track quality <span class="log3">$1</span></span>',
                    $TrackBody,
                    -1,
                    $Count
                );
                $TrackBody = preg_replace(
                    '/Range quality\s+([0-9]{1,3}\.[0-9] %)/i',
                    '<span class="log4">Range quality <span class="log3">$1</span></span>',
                    $TrackBody,
                    -1,
                    $Count
                );
                $TrackBody = preg_replace(
                    '/CRC32 hash \(skip zero\)(\s*:) ([0-9A-F]{8})/i',
                    '<span class="log4">CRC32 hash (skip zero)$1<span class="log3"> $2</span></span>',
                    $TrackBody,
                    -1,
                    $Count
                );
                $TrackBody = preg_replace_callback(
                    '/Test CRC ([0-9A-F]{8})\n(\s*)Copy CRC ([0-9A-F]{8})/i',
                    [
                        $this,
                        'testCopy'
                    ],
                    $TrackBody,
                    -1,
                    $EACTC
                );
                $TrackBody = preg_replace_callback(
                    '/CRC32 hash \(test run\)(\s*:) ([0-9A-F]{8})\n(\s*)CRC32 hash(\s+:) ([0-9A-F]{8})/i',
                    [
                        $this,
                        'testCopy'
                    ],
                    $TrackBody,
                    -1,
                    $XLDTC
                );
                if (!$EACTC && !$XLDTC && !$Aborted) {
                    $this->account('Test and copy was not used', 10);
                    if (!$this->SecureMode) {
                        if ($EAC) {
                            $Msg = 'Rip was not done in Secure mode, and T+C was not used - as a result, ' .
                                'we cannot verify the authenticity of the rip (-40 points)';
                        } else {
                            $Msg = 'Rip was not done with Secure Ripper / in CDParanoia mode, and T+C was not used ' .
                                '- as a result, we cannot verify the authenticity of the rip (-40 points)';
                        }
                        if (!in_array($Msg, $this->Details)) {
                            $this->Score -= 40;
                            $this->Details[] = $Msg;
                        }
                    }
                }
                $TrackBody = preg_replace(
                    '/Copy CRC ([0-9A-F]{8})/i',
                    '<span class="log4">Copy CRC <span class="log3">$1</span></span>',
                    $TrackBody,
                    -1,
                    $Count
                );
                $TrackBody = preg_replace(
                    '/CRC32 hash(\s*:) ([0-9A-F]{8})/i',
                    '<span class="log4">CRC32 hash$1<span class="goodish"> $2</span></span>',
                    $TrackBody,
                    -1,
                    $Count
                );
                $TrackBody = str_replace(
                    'Track not present in AccurateRip database',
                    '<span class="badish">Track not present in AccurateRip database</span>',
                    $TrackBody
                );
                $TrackBody = preg_replace(
                    '/Accurately ripped( +)\(confidence ([0-9]+)\)( +)(\[[0-9A-F]{8}\])/i',
                    '<span class="good">Accurately ripped$1(confidence $2)$3$4</span>',
                    $TrackBody,
                    -1,
                    $Count
                );
                $TrackBody = preg_replace(
                    "/Cannot be verified as accurate +\(.*/i",
                    '<span class="badish">$0</span>',
                    $TrackBody,
                    -1,
                    $Count
                );
                //xld ar
                $TrackBody = preg_replace_callback(
                    '/AccurateRip signature( +): ([0-9A-F]{8})\n(.*?)(Accurately ripped\!?)' .
                        '( +\(A?R?\d?,? ?confidence )([0-9]+\))/i',
                    [
                        $this,
                        'arXld'
                    ],
                    $TrackBody,
                    -1,
                    $Count
                );
                $TrackBody = preg_replace(
                    '/AccurateRip signature( +): ([0-9A-F]{8})\n(.*?)(Rip may not be accurate\.?)(.*?)/i',
                    "<span class=\"log4\">AccurateRip signature$1: <span class=\"badish\">$2</span></span>\n" .
                        "$3<span class=\"badish\">$4$5</span>",
                    $TrackBody,
                    -1,
                    $Count
                );
                $TrackBody = preg_replace(
                    '/(Rip may not be accurate\.?)(.*?)/i',
                    "<span class=\"badish\">$1$2</span>",
                    $TrackBody,
                    -1,
                    $Count
                );
                $TrackBody = preg_replace(
                    '/AccurateRip signature( +): ([0-9A-F]{8})\n(.*?)' .
                        '(Track not present in AccurateRip database\.?)(.*?)/i',
                    "<span class=\"log4\">AccurateRip signature$1: <span class=\"badish\">$2</span></span>\n" .
                        "$3<span class=\"badish\">$4$5</span>",
                    $TrackBody,
                    -1,
                    $Count
                );
                $TrackBody = preg_replace(
                    "/\(matched[ \w]+;\n *calculated[ \w]+;\n[ \w]+signature[ \w:]+\)/i",
                    "<span class=\"goodish\">$0</span>",
                    $TrackBody,
                    -1,
                    $Count
                );
                //ar track + conf
                preg_match('/Accurately ripped\!? +\(A?R?\d?,? ?confidence ([0-9]+)\)/i', $TrackBody, $matches);
                if ($matches) {
                    $this->ARTracks[$TrackNumber] = $matches[1];
                } else {
                    $this->ARTracks[$TrackNumber] = 0;
                } //no match - no boost
                $TrackBody = str_replace('Copy finished', '<span class="log3">Copy finished</span>', $TrackBody);
                $TrackBody = preg_replace('/Copy OK/i', '<span class="good">Copy OK</span>', $TrackBody, -1, $Count);
                $Tracks[$TrackNumber] = array(
                    'number' => $TrackNumber,
                    'spaces' => $Spaces,
                    'text' => $TrackBody,
                    'decreasescore' => $this->DecreaseScoreTrack,
                    'bad' => $this->BadTrack
                );
                $FormattedTrackListing .= "\n" . $TrackBody;
                $this->Tracks[$LogArrayKey][$TrackNumber] = $Tracks[$TrackNumber];
            }
            unset($Tracks);
            $Log = str_replace($TrackListing, $FormattedTrackListing, $Log);
            $Log = str_replace('<br>', "\n", $Log);
            //xld all tracks statistics
            $Log = preg_replace('/( +)?(All tracks *)\n/i', "$1<span class=\"log5\">$2</span>\n", $Log, 1);
            $Log = preg_replace('/( +)(Statistics *)\n/i', "$1<span class=\"log5\">$2</span>\n", $Log, 1);
            $Log = preg_replace_callback('/(Read error)( +:) (\d+)/i', array(
                $this,
                'xldAllStat'
            ), $Log, 1);
            $Log = preg_replace_callback('/(Skipped \(treated as error\))( +:) (\d+)/i', array(
                $this,
                'xldAllStat'
            ), $Log, 1);
            $Log = preg_replace_callback('/(Jitter error \(maybe fixed\))( +:) (\d+)/i', array(
                $this,
                'xldAllStat'
            ), $Log, 1);
            $Log = preg_replace_callback('/(Edge jitter error \(maybe fixed\))( +:) (\d+)/i', array(
                $this,
                'xldAllStat'
            ), $Log, 1);
            $Log = preg_replace_callback('/(Atom jitter error \(maybe fixed\))( +:) (\d+)/i', array(
                $this,
                'xldAllStat'
            ), $Log, 1);
            $Log = preg_replace_callback('/(Drift error \(maybe fixed\))( +:) (\d+)/i', array(
                $this,
                'xldAllStat'
            ), $Log, 1);
            $Log = preg_replace_callback('/(Dropped bytes error \(maybe fixed\))( +:) (\d+)/i', array(
                $this,
                'xldAllStat'
            ), $Log, 1);
            $Log = preg_replace_callback('/(Duplicated bytes error \(maybe fixed\))( +:) (\d+)/i', array(
                $this,
                'xldAllStat'
            ), $Log, 1);
            $Log = preg_replace_callback('/(Retry sector count)( +:) (\d+)/i', array(
                $this,
                'xldAllStat'
            ), $Log, 1);
            $Log = preg_replace_callback('/(Damaged sector count)( +:) (\d+)/i', array(
                $this,
                'xldAllStat'
            ), $Log, 1);
            //end xld all tracks statistics
            $this->logs[$LogArrayKey] = $Log;
            $this->checkTracks($LogArrayKey);

            if ($this->NonSecureMode) { #non-secure mode
                $this->account($this->NonSecureMode . ' mode was used', 20);
            }

            $this->ARTracks   = array();
            $this->ARSummary     = array();
            $this->SecureMode   = true;
            $this->NonSecureMode = null;
        } //end log loop

        $FinalTracks = [];
        foreach ($this->Tracks as $LogArrayKey => $Tracks) {
            foreach ($Tracks as $Number => $Track) {
                $FinalTracks[$Number] = $Track;
            }
        }
        foreach ($FinalTracks as $Track) {
            if ($Track['decreasescore']) {
                $this->Score -= $Track['decreasescore'];
            }
            if (count($Track['bad']) > 0) {
                $this->Details = array_merge($this->Details, $Track['bad']);
            }
        }
        unset($FinalTracks);
        unset($this->Tracks);

        $this->log = implode($this->logs);
        if (strlen($this->log) === 0) {
            $this->Score = 0;
            $this->account('Unrecognized log file! Feel free to report for manual review.');
        }

        if ($this->Combined) {
            array_unshift($this->Details, "Combined Log (" . $this->Combined . ")");
        } //combined log msg
    }

    // Callback functions
    private function drive($Matches)
    {
        $DriveName = trim($Matches[2]);
        if ($DriveName === '(null) (null) (revision (null))') {
            $this->account('Null drive used', 20);
            return "<span class=\"log5\">Used Drive$Matches[1]</span>: <span class=\"bad\">$Matches[2]</span>";
        }
        if (in_array($DriveName, $this->FakeDrives)) {
            $this->account('Virtual drive used: ' . $Matches[2], 20, false, false, false);
            return "<span class=\"log5\">Used Drive$Matches[1]</span>: <span class=\"bad\">$Matches[2]</span>";
        }

        $this->getDrives($DriveName);

        if (count($this->Drives) > 0) {
            $Class          = 'good';
            $this->DriveFound = true;
        } else {
            $Class = 'badish';
            $Matches[2] .= ' (not found in database)';
        }
        return "<span class=\"log5\">Used Drive$Matches[1]</span>: <span class=\"$Class\">$Matches[2]</span>";
    }

    private function getDrives($DriveName)
    {
        // Necessary transformations to get what the drives report themselves to match up into
        // what is from the AccurateRIP DB
        $DriveName = str_replace('JLMS', 'Lite-ON', $DriveName);
        $DriveName = preg_replace('/TSSTcorp(BD|CD|DVD)/', 'TSSTcorp \1', $DriveName);
        $DriveName = preg_replace('/HL-DT-ST(BD|CD|DVD)/', 'HL-DT-ST \1', $DriveName);
        $DriveName = str_replace('HL-DT-ST', 'LG Electronics', $DriveName);
        $DriveName = str_replace(array('Matshita', 'MATSHITA'), 'Panasonic', $DriveName);
        $DriveName = preg_replace('/\s+-\s/', ' ', $DriveName);
        $DriveName = preg_replace('/\s+/', ' ', $DriveName);
        $DriveName = preg_replace('/\(revision [a-zA-Z0-9\.\,\-]*\)/', '', $DriveName);
        $DriveName = preg_replace('/ Adapter.*$/', '', $DriveName);
        $DriveName = trim(strtolower($DriveName));

        $MatchedDrives = [];
        for ($i = 0; $i < LOGCHECKER_LEVENSTEIN_DISTANCE + 1; $i++) {
            $MatchedDrives[$i] = ['drives' => [], 'offsets' => []];
        }

        foreach ($this->AllDrives as [$Drive, $Offset]) {
            $Distance = levenshtein($Drive, $DriveName);
            if ($Distance < LOGCHECKER_LEVENSTEIN_DISTANCE + 1) {
                $MatchedDrives[$Distance]['drives'][] = $Drive;
                $MatchedDrives[$Distance]['offsets'][] = (string) $Offset;
            }
        }

        foreach ($MatchedDrives as $Match) {
            if (count($Match['drives']) > 0) {
                $this->Drives = $Match['drives'];
                $this->Offsets = $Match['offsets'];
                break;
            }
        }
    }

    private function mediaTypeXld($Matches)
    {
        // Pressed CD
        if (trim($Matches[2]) == "Pressed CD") {
            $Class = 'good';
        } else { // CD-R etc.; not necessarily "bad" (e.g. commercial CD-R)
            $Class = 'badish';
            $this->account('Not a pressed cd', false, false, true, true);
        }
        return "<span class=\"log5\">Media type$Matches[1]</span>: <span class=\"$Class\">$Matches[2]</span>";
    }

    private function readMode($Matches)
    {
        if ($Matches[2] == 'Secure') {
            $Class = 'good';
        } else {
            $this->SecureMode   = false;
            $this->NonSecureMode = $Matches[2];
            $Class             = 'bad';
        }
        $Str = '<span class="log5">Read mode' . $Matches[1] . '</span>: ' .
            '<span class="' . $Class . '">' . $Matches[2] . '</span>';
        if ($Matches[3]) {
            $Str .= '<span class="log4">' . $Matches[3] . '</span>';
        }
        return $Str;
    }

    private function cdparanoiaModeXld($Matches)
    {
        if (substr($Matches[2], 0, 3) == 'YES') {
            $Class = 'good';
        } else {
            $this->SecureMode = false;
            $Class          = 'bad';
        }
        return '<span class="log5">Use cdparanoia mode' . $Matches[1] . '</span>: ' .
            '<span class="' . $Class . '">' . $Matches[2] . '</span>';
    }

    private function ripperModeXld($Matches)
    {
        if (substr($Matches[2], 0, 10) == 'CDParanoia') {
            $Class = 'good';
        } elseif ($Matches[2] == "XLD Secure Ripper") {
            $Class               = 'good';
            $this->XLDSecureRipper = true;
        } else {
            $this->SecureMode = false;
            $Class          = 'bad';
        }
        return '<span class="log5">Ripper mode' . $Matches[1] . '</span>: ' .
            '<span class="' . $Class . '">' . $Matches[2] . '</span>';
    }

    private function arXld($Matches)
    {
        if (strpos(strtolower($Matches[4]), 'accurately ripped') != -1) {
            $conf = substr($Matches[6], 0, -1);
            if ((int) $conf < 2) {
                $Class = 'goodish';
            } else {
                $Class = 'good';
            }
        } else {
            $Class = 'badish';
        }
        return "<span class=\"log4\">AccurateRip signature$Matches[1]: " .
            "<span class=\"$Class\">$Matches[2]</span></span>\n" .
            "$Matches[3]<span class=\"$Class\">$Matches[4]$Matches[5]$Matches[6]</span>";
    }

    private function arSummaryConfXld($Matches)
    {
        if (strtolower(trim($Matches[2])) == 'ok') {
            if ($Matches[3] < 2) {
                $Class = 'goodish';
            } else {
                $Class = 'good';
            }
        } else {
            $Class = 'badish';
        }
        return "$Matches[1]<span class =\"$Class\">" . substr($Matches[0], strlen($Matches[1])) . "</span>";
    }

    private function arSummaryConf($Matches)
    {
        if ($Matches[3] < 2) {
            $Class                      = 'goodish';
            $this->ARSummary['goodish'][] = $Matches[3];
        } else {
            $Class                   = 'good';
            $this->ARSummary['good'][] = $Matches[3];
        }
        return "<span class =\"$Class\">$Matches[0]</span>";
    }

    private function maxRetryCount($Matches)
    {
        if ($Matches[2] >= 10) {
            $Class = 'goodish';
        } else {
            $Class = 'badish';
            $this->account('Low "max retry count" (potentially bad setting)');
        }
        return '<span class="log5">Max retry count' . $Matches[1] . '</span>: ' .
            '<span class="' . $Class . '">' . $Matches[2] . '</span>';
    }

    private function accurateStream($Matches)
    {
        if ($Matches[2] == 'Yes') {
            $Class = 'good';
        } else {
            $Class = 'bad';
            $this->account('"Utilize accurate stream" should be yes', 20);
        }
        return '<span class="log5">Utilize accurate stream' . $Matches[1] . '</span>: ' .
            '<span class="' . $Class . '">' . $Matches[2] . '</span>';
    }

    private function accurateStreamEacPre9($Matches)
    {
        if (strtolower($Matches[1]) != 'no ') {
            $Class = 'good';
        } else {
            $Class = 'bad';
            $this->account('"accurate stream" should be yes', 20);
        }
        return ', <span class="' . $Class . '">' . $Matches[1] . 'accurate stream</span>';
    }

    private function defeatAudioCache($Matches)
    {
        if ($Matches[2] == 'Yes') {
            $Class = 'good';
        } else {
            $Class = 'bad';
            $this->account('"Defeat audio cache" should be yes', 10);
        }
        return '<span class="log5">Defeat audio cache' . $Matches[1] . '</span>: ' .
            '<span class="' . $Class . '">' . $Matches[2] . '</span>';
    }

    private function defeatAudioCacheEacPre99($Matches)
    {
        if (strtolower($Matches[1]) != 'no ') {
            $Class = 'good';
        } else {
            $Class = 'bad';
            $this->account('Audio cache not disabled', 10);
        }
        return ' <span class="' . $Class . '">' . $Matches[1] . 'disable cache</span>';
    }

    private function defeatAudioCacheXld($Matches)
    {
        if (substr($Matches[2], 0, 2) == 'OK' || substr($Matches[2], 0, 3) == 'YES') {
            $Class = 'good';
        } else {
            $Class = 'bad';
            $this->account('"Disable audio cache" should be yes/ok', 10);
        }
        return '<span class="log5">Disable audio cache' . $Matches[1] . '</span>: ' .
            '<span class="' . $Class . '">' . $Matches[2] . '</span>';
    }

    private function c2Pointers($Matches)
    {
        if (strtolower($Matches[2]) == 'yes') {
            $Class = 'bad';
            $this->account('C2 pointers were used', 10);
        } else {
            $Class = 'good';
        }
        return '<span class="log5">Make use of C2 pointers' . $Matches[1] . '</span>: ' .
            '<span class="' . $Class . '">' . $Matches[2] . '</span>';
    }

    private function c2PointersEacPre99($Matches)
    {
        if (strtolower($Matches[1]) == 'no ') {
            $Class = 'good';
        } else {
            $Class = 'bad';
            $this->account('C2 pointers were used', 10);
        }
        return 'with <span class="' . $Class . '">' . $Matches[1] . 'C2</span>';
    }

    private function readOffset($Matches)
    {
        if ($this->DriveFound == true) {
            if (in_array($Matches[2], $this->Offsets)) {
                $Class = 'good';
            } else {
                $Class = 'bad';
                $Msg = 'Incorrect read offset for drive. Correct offsets are: ' . implode(', ', $this->Offsets) .
                    ' (Checked against the following drive(s): ' . implode(', ', $this->Drives) . ')';
                $this->account($Msg, 5, false, false, false);
            }
        } else {
            if ($Matches[2] == 0) {
                $Class = 'bad';
                $Msg = 'The drive was not found in the database, so we cannot determine the correct read offset. ' .
                    'However, the read offset in this case was 0, which is almost never correct. As such, we are ' .
                    'assuming that the offset is incorrect';
                $this->account($Msg, 5, false, false, false);
            } else {
                $Class = 'badish';
            }
        }
        return '<span class="log5">Read offset correction' . $Matches[1] . '</span>: ' .
            '<span class="' . $Class . '">' . $Matches[2] . '</span>';
    }

    private function fillOffsetSamples($Matches)
    {
        if ($Matches[2] == 'Yes') {
            $Class = 'good';
        } else {
            $Class = 'bad';
            $this->account('Does not fill up missing offset samples with silence', 5, false, false, false);
        }
        return '<span class="log5">Fill up missing offset samples with silence' . $Matches[1] .
            '</span>: <span class="' . $Class . '">' . $Matches[2] . '</span>';
    }

    private function deleteSilentBlocks($Matches)
    {
        if ($Matches[3] == 'Yes') {
            $Class = 'bad';
            $this->account('Deletes leading and trailing silent blocks', 5, false, false, false);
        } else {
            $Class = 'good';
        }
        return '<span class="log5">Delete leading and trailing silent blocks' .
            $Matches[1] . $Matches[2] . '</span>: <span class="' . $Class . '">' . $Matches[3] . '</span>';
    }

    private function nullSamples($Matches)
    {
        if ($Matches[2] == 'Yes') {
            $Class = 'good';
        } else {
            $Class = 'bad';
            $this->account('Null samples should be used in CRC calculations', 5);
        }
        return '<span class="log5">Null samples used in CRC calculations' . $Matches[1] . '</span>: ' .
            '<span class="' . $Class . '">' . $Matches[2] . '</span>';
    }

    private function normalizeEac($Matches)
    {
        $this->account('Normalization should be not be active', 100);
        return '<span class="log5">Normalize to' . $Matches[1] . '</span>: ' .
            '<span class="bad">' . $Matches[2] . '</span>';
    }

    private function gapHandling($Matches)
    {
        if (strpos($Matches[2], 'Not detected') !== false) {
            $Class = 'bad';
            $this->account('Gap handling was not detected', 10);
        } elseif (strpos($Matches[2], 'Appended to next track') !== false) {
            $Class = 'bad';
            $this->account('Gap handling should be appended to previous track', 10);
        } elseif (strpos($Matches[2], 'Left out') !== false) {
            $Class = 'bad';
            $this->account('Gap handling should be appended to previous track', 10);
        } elseif (strpos($Matches[2], 'Appended to previous track') !== false) {
            $Class = 'good';
        } else {
            $Class = 'goodish';
        }
        return '<span class="log5">Gap handling' . $Matches[1] . '</span>: ' .
            '<span class="' . $Class . '">' . $Matches[2] . '</span>';
    }

    private function gapHandlingXld($Matches)
    {
        if (strpos(strtolower($Matches[2]), 'not') !== false) { //?
            $Class = 'bad';
            $this->account('Incorrect gap handling', 10, false, false, false);
        } elseif (
            strpos(strtolower($Matches[2]), 'analyzed') !== false
            && strpos(strtolower($Matches[2]), 'appended') !== false
        ) {
            $Class = 'good';
        } else {
            $Class = 'badish';
            $this->account('Incomplete gap handling', 10, false, false, false);
        }
        return '<span class="log5">Gap status' . $Matches[1] . '</span>: ' .
            '<span class="' . $Class . '">' . $Matches[2] . '</span>';
    }

    private function addId3Tag($Matches)
    {
        if ($Matches[2] == 'Yes') {
            $Class = 'badish';
            $this->account(
                'ID3 tags should not be added to FLAC files - they are mainly for MP3 files. ' .
                    'FLACs should have vorbis comments for tags instead.',
                1
            );
        } else {
            $Class = 'good';
        }
        return '<span class="log5">Add ID3 tag' . $Matches[1] . '</span>: <span class="' .
            $Class . '">' . $Matches[2] . '</span>';
    }

    private function testCopy($Matches)
    {
        if ($this->ripper == "EAC") {
            if ($Matches[1] == $Matches[3]) {
                $Class = 'good';
            } else {
                $Class = 'bad';
                $this->accountTrack("CRC mismatch: $Matches[1] and $Matches[3]", 30);
                if (!$this->SecureMode) {
                    $this->DecreaseScoreTrack += 20;
                    $this->BadTrack[] = 'Rip ' . (($this->Combined) ? " (" . $this->CurrLog . ") " : '') . 'was not ' .
                        'done in Secure mode, and experienced CRC mismatches (-20 points)';
                    $this->SecureMode = true;
                }
            }
            return "<span class=\"log4\">Test CRC <span class=\"$Class\">$Matches[1]</span></span>\n" .
                "$Matches[2]<span class=\"log4\">Copy CRC <span class=\"$Class\">$Matches[3]</span></span>";
        } elseif ($this->ripper == "XLD") {
            if ($Matches[2] == $Matches[5]) {
                $Class = 'good';
            } else {
                $Class = 'bad';
                $this->accountTrack("CRC mismatch: $Matches[2] and $Matches[5]", 30);
                if (!$this->SecureMode) {
                    $this->DecreaseScoreTrack += 20;
                    $this->BadTrack[] = 'Rip ' . (($this->Combined) ? " (" . $this->CurrLog . ") " : '') . 'was not ' .
                        'done with Secure Ripper / in CDParanoia mode, and experienced CRC mismatches (-20 points)';
                    $this->SecureMode = true;
                }
            }
            return "<span class=\"log4\">CRC32 hash (test run)$Matches[1] " .
            "<span class=\"$Class\">$Matches[2]</span></span>\n$Matches[3] " .
            "<span class=\"log4\">CRC32 hash$Matches[4] <span class=\"$Class\">$Matches[5]</span></span>";
        }
    }

    private function xldAllStat($Matches)
    {
        $Text = $Matches[1] . $Matches[2];
        if (
            strtolower($Matches[1]) == 'read error'
            || strtolower($Matches[1]) == 'skipped (treated as error)'
            || strtolower($Matches[1]) == 'inconsistency in error sectors'
            || strtolower($Matches[1]) == 'damaged sector count'
        ) {
            if ($Matches[3] == 0) {
                $Class = 'good';
            } else {
                $Class = 'bad';
            }
            return '<span class="log4">' . $Text . '</span> <span class="' . $Class . '">' . $Matches[3] . '</span>';
        }
        if (
            strtolower($Matches[1]) == 'retry sector count'
            || strtolower($Matches[1]) == 'jitter error (maybe fixed)'
            || strtolower($Matches[1]) == 'edge jitter error (maybe fixed)'
            || strtolower($Matches[1]) == 'atom jitter error (maybe fixed)'
            || strtolower($Matches[1]) == 'drift error (maybe fixed)'
            || strtolower($Matches[1]) == 'dropped bytes error (maybe fixed)'
            || strtolower($Matches[1]) == 'duplicated bytes error (maybe fixed)'
        ) {
            if ($Matches[3] == 0) {
                $Class = 'goodish';
            } else {
                $Class = 'badish';
            }
            return '<span class="log4">' . $Text . '</span> <span class="' . $Class . '">' . $Matches[3] . '</span>';
        }
    }

    private function xldStat($Matches)
    {
        $Text = $Matches[1] . $Matches[2];
        if (strtolower($Matches[1]) == 'read error') {
            if ($Matches[3] == 0) {
                $Class = 'good';
            } else {
                $Class = 'bad';
                $err   = ($Matches[3] > 10) ? 10 : $Matches[3]; //max.
                $this->accountTrack('Read error' . ($Matches[3] == 1 ? '' : 's') . ' detected', $err);
            }
            return '<span class="log4">' . $Text . '</span> <span class="' . $Class . '">' . $Matches[3] . '</span>';
        }
        if (strtolower($Matches[1]) == 'skipped (treated as error)') {
            if ($Matches[3] == 0) {
                $Class = 'good';
            } else {
                $Class = 'bad';
                $err   = ($Matches[3] > 10) ? 10 : $Matches[3]; //max.
                $this->accountTrack('Skipped error' . ($Matches[3] == 1 ? '' : 's') . ' detected', $err);
            }
            return '<span class="log4">' . $Text . '</span> <span class="' . $Class . '">' . $Matches[3] . '</span>';
        }
        if (strtolower($Matches[1]) == 'inconsistency in error sectors') {
            if ($Matches[3] == 0) {
                $Class = 'good';
            } else {
                $Class = 'bad';
                $err   = ($Matches[3] > 10) ? 10 : $Matches[3]; //max.
                $this->accountTrack(
                    'Inconsistenc' . (($Matches[3] == 1) ? 'y' : 'ies') . ' in error sectors detected',
                    $err
                );
            }
            return '<span class="log4">' . $Text . '</span> <span class="' . $Class . '">' . $Matches[3] . '</span>';
        }
        if (strtolower($Matches[1]) == 'damaged sector count') { //xld secure ripper
            if ($Matches[3] == 0) {
                $Class = 'good';
            } else {
                $Class = 'bad';
                $err   = ($Matches[3] > 10) ? 10 : $Matches[3]; //max.
                $this->accountTrack('Damaged sector count of ' . ($Matches[3]), $err);
            }
            return '<span class="log4">' . $Text . '</span> <span class="' . $Class . '">' . $Matches[3] . '</span>';
        }
        if (
            strtolower($Matches[1]) == 'retry sector count'
            || strtolower($Matches[1]) == 'jitter error (maybe fixed)'
            || strtolower($Matches[1]) == 'edge jitter error (maybe fixed)'
            || strtolower($Matches[1]) == 'atom jitter error (maybe fixed)'
            || strtolower($Matches[1]) == 'drift error (maybe fixed)'
            || strtolower($Matches[1]) == 'dropped bytes error (maybe fixed)'
            || strtolower($Matches[1]) == 'duplicated bytes error (maybe fixed)'
        ) {
            if ($Matches[3] == 0) {
                $Class = 'goodish';
            } else {
                $Class = 'badish';
            }
            return '<span class="log4">' . $Text . '</span> <span class="' . $Class . '">' . $Matches[3] . '</span>';
        }
    }

    private function toc($Matches)
    {
        return "$Matches[1]<span class=\"log4\">$Matches[2]</span>$Matches[3]<strong>|</strong>" .
        "$Matches[4]<span class=\"log1\">$Matches[5]</span>$Matches[7]<strong>|</strong>" .
        "$Matches[8]<span class=\"log1\">$Matches[9]</span>$Matches[11]<strong>|</strong>" .
        "$Matches[12]<span class=\"log1\">$Matches[13]</span>$Matches[14]<strong>|</strong>" .
        "$Matches[15]<span class=\"log1\">$Matches[16]</span>$Matches[17]" . "\n";
    }

    private function checkTracks($LogArrayKey)
    {
        if (count($this->Tracks[$LogArrayKey]) === 0) { //no tracks
            unset($this->Details);
            if ($this->Combined) {
                $this->Details[] = "Combined Log (" . $this->Combined . ")";
                $this->Details[] = "Invalid log (" . $this->CurrLog . "), no tracks!";
            } else {
                $this->Details[] = "Invalid log, no tracks!";
            }
            $this->Score = 0;
        }
    }

    private function account(
        $Msg,
        $Decrease = false,
        $Score = false,
        $InclCombined = false,
        $Notice = false
    ) {
        $DecreaseScore = $SetScore = false;
        $Append2       = '';
        $Append1       = ($InclCombined) ? (($this->Combined) ? " (" . $this->CurrLog . ")" : '') : '';
        $Prepend       = ($Notice) ? '[Notice] ' : '';
        if ($Decrease) {
            $DecreaseScore = true;
            $Append2       = ($Decrease > 0) ? ' (-' . $Decrease . ' point' . ($Decrease == 1 ? '' : 's') . ')' : '';
        } elseif ($Score || $Score === 0) {
            $SetScore = true;
            $Decrease = 100 - $Score;
            $Append2  = ($Decrease > 0) ? ' (-' . $Decrease . ' point' . ($Decrease == 1 ? '' : 's') . ')' : '';
        }
        if (!in_array($Prepend . $Msg . $Append1 . $Append2, $this->Details)) {
            $this->Details[] = $Prepend . $Msg . $Append1 . $Append2;
            if ($DecreaseScore) {
                $this->Score -= $Decrease;
            }
            if ($SetScore) {
                $this->Score = $Score;
            }
        }
    }

    private function accountTrack($Msg, $Decrease = false)
    {
        $tn  = (intval($this->TrackNumber) < 10) ? '0' . intval($this->TrackNumber) : $this->TrackNumber;
        $Append = '';
        if ($Decrease) {
            $this->DecreaseScoreTrack += $Decrease;
            $Append = ' (-' . $Decrease . ' point' . ($Decrease == 1 ? '' : 's') . ')';
        }
        $Prepend          = 'Track ' . $tn . (($this->Combined) ? " (" . $this->CurrLog . ")" : '') . ': ';
        $this->BadTrack[] = $Prepend . $Msg . $Append;
    }

    public function getRipper()
    {
        return $this->ripper;
    }

    public function getRipperVersion()
    {
        return $this->ripperVersion;
    }

    public function getScore(): int
    {
        return $this->Score;
    }

    public function getDetails(): array
    {
        return $this->Details;
    }

    public function getChecksumState(): string
    {
        return $this->checksumStatus;
    }

    public function getLanguage(): string
    {
        return $this->language;
    }

    public function isCombinedLog(): bool
    {
        return !is_null($this->Combined) && $this->Combined > 0;
    }

    public static function getAcceptValues(): string
    {
        return ".txt,.TXT,.log,.LOG";
    }

    public static function getLogcheckerVersion(): string
    {
        $composer = json_decode(
            file_get_contents(implode(DIRECTORY_SEPARATOR, [__DIR__, '..', 'composer.json'])),
            true
        );
        return $composer['version'];
    }
}
