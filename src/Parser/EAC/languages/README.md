EAC Languages
=============

EAC has the ability to use translation files to support other languages. This affects both the
UI as well as the files that EAC outputs, which includes the log file from a rip. The JSON
files in this directory represent a stripped down version of the translation files that EAC uses,
including just the strings that the logs contain/use.

While EAC comes with several official languages that it supports, people have created their own
translation files for several more languages, as well as for some of the officially supported
ones.

We support the following languages:

* bg: Bulgarian
* cs: Czech
* de: German
* en: English
* es: Spanish
* fr: French
* it: Italian
* jp: Japanese
* jp99_5: Japanese (Alternate)
* nl: Dutch
* pl: Polish
* ru: Russian
* se: Swedish
* se_2: Swedish (Alternate)
* sk: Slovak
* sr: Serbian
* zh-Hans: Chinese (Simplified)

Within each JSON file, the keys represent the numeric key that EAC internally uses for each phrase,
and that points to the phrase itself. For each number in each file, there should be a corresponding
number within the `en.json` file. The `master.json` file contains a key that represents a filename,
and then points to the entry at key `1274`. Our logchecker loads this master file, iterates through
until it finds a matching entry, and then if that entry is not `en`, loads the related JSON file
and translates all strings it can from that language to English.

Important note: These strings should be roughly assembled from longest string to shortest or else you
risk the potential for certain string sequences to be messed up.
