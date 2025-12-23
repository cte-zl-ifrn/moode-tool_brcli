<?php
/**
 * admin tool brcli
 * Backup & restore command line interface
 * @package admin
 * @subpackage tool
 * @author Paulo Júnior <pauloa.junior@ufla.br> based on /admin/cli/backup.php
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
define('CLI_SCRIPT', 1);

require(__DIR__.'/../../../config.php');
require_once($CFG->libdir.'/clilib.php');
require_once($CFG->dirroot . '/backup/util/includes/backup_includes.php');

// Now get cli options.
list($options, $unrecognized) = cli_get_params(array(
    'categoryid' => false,
    'destination' => '',
    'users' => 0,
    'anonymize' => 0,
    'all' => false,
    'no-recursive' => false,
    'help' => false,
    ), array('h' => 'help'));

if ($unrecognized) {
    $unrecognized = implode("\n  ", $unrecognized);
    cli_error(get_string('unknowoption', 'tool_brcli', $unrecognized));
}

if ($options['help'] || (!$options['all'] && !$options['categoryid']) || !$options['destination']) {
    echo get_string('helpoptionbck', 'tool_brcli');
    die;
}

$admin = get_admin();
if (!$admin) {
    cli_error(get_string('noadminaccount', 'tool_brcli'));
}

// Do we need to store backup somewhere else?
$dir = rtrim($options['destination'], '/');
if (empty($dir) || !file_exists($dir) || !is_dir($dir) || !is_writable($dir)) {
    cli_error(get_string('directoryerror', 'tool_brcli'));
}

if (!$options['all']) {
    
    // Get category (throws exception if not exists).
    try {
        $category = core_course_category::get($options['categoryid']);
    } catch (Exception $e) {
        cli_error(get_string('nocategory', 'tool_brcli'));
    }

    // Recursive by default, unless --no-recursive is set.
    $recursive = !$options['no-recursive'];

    // Get courses from category and subcategories (recursive).
    $courses = $category->get_courses([
        'recursive' => $recursive
    ]);
}

if ($options['all']) {
    // Get ALL courses except frontpage.
    $courses = $DB->get_records_select('course', 'id > 1');
}
$amount_of_courses = count($courses);

$index = 1;

foreach ($courses as $cs) {
    $bc = new backup_controller(backup::TYPE_1COURSE, $cs->id, backup::FORMAT_MOODLE,
                                backup::INTERACTIVE_YES, backup::MODE_GENERAL, $admin->id);

    $settings = $bc->get_plan()->get_settings();

    if (isset($settings['users'])) {
        $settings['users']->set_value((bool)$options['users']);
    }

    if (isset($settings['anonymize'])) {
        $settings['anonymize']->set_value((bool)$options['anonymize']);
    }
    
    
    mtrace(get_string('performingbck', 'tool_brcli', $index . '/' . $amount_of_courses));

    // Set the default filename.
    $format = $bc->get_format();
    $type = $bc->get_type();
    $id = $bc->get_id();
    $users = $bc->get_plan()->get_setting('users')->get_value();
    $anonymised = $bc->get_plan()->get_setting('anonymize')->get_value();
    $filename = backup_plan_dbops::get_default_backup_filename($format, $type, $id, $users, $anonymised);
    $bc->get_plan()->get_setting('filename')->set_value($filename);

    // Execution.
    $bc->finish_ui();
    $bc->execute_plan();
    $results = $bc->get_results();
    $file = $results['backup_destination']; // May be empty if file already moved to target location.

    // Gather metadata info
    $course = get_course($cs->id);
    $category = core_course_category::get($course->category);
    $modinfo = get_fast_modinfo($course);

    // count modules by type
    $usedmods = [];
    foreach ($modinfo->get_cms() as $cm) {
        $usedmods[$cm->modname] = true;
    }

    // count roles
    $context = context_course::instance($course->id);
    $rolecounts = get_role_users(null, $context, false);
    $roles = [];
    foreach ($rolecounts as $u) {
        $roles[$u->roleshortname] = isset($roles[$u->roleshortname]) ? $roles[$u->roleshortname] + 1 : 1;
    }

    // build metadata array
    $metadata = [
        'id' => $course->id,
        'fullname' => $course->fullname,
        'shortname' => $course->shortname,
        'idnumber' => $course->idnumber,
        'category' => $category->name,
        'categoryid' => $category->id,
        'groups_count' => count(groups_get_all_groups($course->id)),
        'groupings_count' => count(groups_get_all_groupings($course->id)),
        'roles' => $roles,
        'format' => $course->format,
        'modules' => array_keys($usedmods),
        'backup_date' => date('c'),
        'mbz_file' => $filename
    ];

    // write metadata JSON
    file_put_contents($dir . '/' . $filename . '.json', json_encode($metadata, JSON_PRETTY_PRINT));

    // Do we need to store backup somewhere else?
    if ($file) {
        if ($file->copy_content_to($dir.'/'.$filename)) {
            $file->delete();
        } else {
            mtrace(get_string('directoryerror', 'tool_brcli'));
        }
    }
    $bc->destroy();
    $index = $index + 1;
}
mtrace(get_string('operationdone', 'tool_brcli'));

exit(0);