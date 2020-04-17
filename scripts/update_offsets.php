#!/usr/bin/env php
<?php

/*
This script downloads all drives and offsets from Accuraterip's website, and then
applies various bits of normalization to be used within the Logchecker
*/

$replacements = [
    ['16X DVD- - ROM', '16X DVD-ROM'],
    ['HL)DP-ST', 'HL-DP-ST'],
    ['FREECOM_', 'FREECOM'],
    ['Generic_', 'GENERIC']
];


$ch = curl_init();

curl_setopt($ch, CURLOPT_URL, 'http://www.accuraterip.com/driveoffsets.htm');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
$doc = new DOMDocument();
$doc->loadHTML(curl_exec($ch), LIBXML_NOWARNING | LIBXML_NOERROR);
curl_close($ch);
print("Loaded AccurateRip Drive Website\n\n");

$item = $doc->getElementsByTagName('table')->item(1);
if ($item === null) {
    die();
}

$drives = [];
$count = $item->childNodes->count();
for ($i = 2; $i < $count; $i++) {
    $childNode = $item->childNodes->item($i);
    if ($childNode === null) {
        continue;
    }
    if ($childNode->nodeName !== 'tr') {
        continue;
    }

    $drive = array_map('trim', explode("\n", ltrim(trim($childNode->textContent), "- ")));
    if (count($drive) !== 4) {
        continue;
    }
    $drive[1] = (int) $drive[1];
    $drive[3] = (int) $drive[3];

    if ($drive[1] === '[Purged]' || $drive[3] <= 50) {
        continue;
    }

    foreach ($replacements as [$old, $new]) {
        $drive[0] = str_replace($old, $new, $drive[0]);
    }
    $drive[0] = strtolower($drive[0]);
    $drive[0] = preg_replace('/ +- +/', ' ', $drive[0]);
    $drive[0] = preg_replace('/ +/', ' ', $drive[0]);

    //print("[" . implode(', ', array_values($drive)) . "]\n");

    $drives[] = $drive;
}
file_put_contents(implode(DIRECTORY_SEPARATOR, [__DIR__, '..', 'src', 'resources', 'drives.json']), json_encode($drives));
print("Updating " . count($drives) . " drives\n");
/*
$drives = [];
for ($i = 1; $i < $rows->length; $i++) {
    $row = $rows->item($i);
    if ($row->childNodes->length > 4 && $row->childNodes->item(3)->nodeValue !== '[Purged]') {
        $drive = trim($row->childNodes->item(1)->nodeValue, '- ');
        foreach ($replacements as $replacement) {
            $drive = str_replace($replacement[0], $replacement[1], $drive);
        }
        $drive = strtolower($drive);
        $drive = preg_replace('/ +- +/', ' ', $drive);
        $drive = preg_replace('/ +/', ' ', $drive);


        $offset = ltrim(trim($row->childNodes->item(3)->nodeValue), '+');
        $offsets[] = $drive.','.$offset;
    }
}

file_put_contents(__DIR__.'/../src/offsets.txt', implode("\n", $offsets));

print("Updating ".count($offsets)." drives\n");
*/
