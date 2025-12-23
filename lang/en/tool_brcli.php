<?php
/**
 * admin tool brcli
 * Backup & restore command line interface
 * @package admin
 * @subpackage tool
 * @author Paulo Júnior <pauloa.junior@ufla.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
$string['pluginname'] = 'Backup and Restore Command-Line Interface';
$string['unknowoption'] = 'Unknow option: {$a}';
$string['noadminaccount'] = 'Error: No admin account was found!';
$string['directoryerror'] = 'Error: Destination directory does not exists or not writable!';
$string['nocategory'] = 'Error: No category was found!';
$string['performingbck'] = 'Performing backup of the {$a} course...';
$string['performingres'] = 'Restoring backup of the {$a} course...';
$string['operationdone'] = 'Done!';
$string['invalidbackupfile'] = 'Invalid backup file: {$a}';
$string['helpoptionbck'] = 
'Perform backup of Moodle courses.

You can backup:
- all courses in the Moodle instance, or
- only courses from a specific category.

Options:
--all                       Backup ALL courses (except frontpage).
--categoryid=INTEGER        Category ID for backup (ignored when --all is used).
--destination=STRING        Path where backup files will be stored.
--users=0|1                 Include users in backup (default: 0).
--anonymize=0|1             Anonymize user data (only applies when --users=1).
-h, --help                  Print out this help.

Notes:
- By default, backups are created WITHOUT users.
- --anonymize has effect only when --users=1.
- When using --all, --categoryid is not required.

Examples:

Backup all courses without users:
    php admin/tool/brcli/backup.php --all --destination=/moodle/backup/

Backup all courses with anonymized users:
    php admin/tool/brcli/backup.php --all --destination=/moodle/backup/ --users=1 --anonymize=1

Backup courses from a specific category:
    php admin/tool/brcli/backup.php --categoryid=3 --destination=/moodle/backup/

Backup category courses with users:
    php admin/tool/brcli/backup.php --categoryid=3 --destination=/moodle/backup/ --users=1
';
$string['helpoptionres'] = 
'Restore all backup files belong to a specific folder.

Options:
--categoryid=INTEGER        Category ID where the backup must be restored.
--source=STRING             Path where the backup files (.mbz) are. 
-h, --help                  Print out this help.

Example:
    sudo -u www-data /usr/bin/php admin/tool/brcli/restore.php --categoryid=1 --source=/moodle/backup/
';
