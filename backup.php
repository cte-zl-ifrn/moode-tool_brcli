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
list($options, $unrecognized) = cli_get_params([
    'categoryid'   => false,
    'destination'  => '',
    'users'        => 0,
    'anonymize'    => 0,
    'all'          => false,
    'no-recursive' => false,
    'export'       => 'all', // json | backup | all
    'help'         => false,
], ['h' => 'help']);

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
\core\session\manager::set_user($admin);

// Do we need to store backup somewhere else?
$dir = rtrim($options['destination'], '/');
if (empty($dir) || !file_exists($dir) || !is_dir($dir) || !is_writable($dir)) {
    cli_error(get_string('directoryerror', 'tool_brcli'));
}

$dojson   = in_array($options['export'], ['json', 'all']);
$dobackup = in_array($options['export'], ['backup', 'all']);

if (!$options['all']) {
    
    // Get category (throws exception if not exists).
    try {
        $category = core_course_category::get(
            $options['categoryid'],
            IGNORE_MISSING,
            true // inclui categorias ocultas
        );
    } catch (Exception $e) {
        cli_error(get_string('nocategory', 'tool_brcli'));
    }

    // Recursive by default, unless --no-recursive is set.
    $recursive = !$options['no-recursive'];

    // Get courses from category and subcategories (recursive).
    $courses = $category->get_courses([
        'recursive' => $recursive,
        'visible'   => false
    ]);
}

if ($options['all']) {
    // Get ALL courses except frontpage.
    $courses = $DB->get_records_select('course', 'id > 1');
}

function export_course_metadata(stdClass $course, \core_customfield\handler $handler): array {
    global $DB;

    $course_data = (array) $course;

    // ---------- CUSTOM FIELDS ----------
    $grouped_fields = [];

    $definitions = $handler->get_fields();
    $instance_data = $handler->get_instance_data($course->id);

    foreach ($definitions as $field) {
        $cat = $field->get_category();
        $catid = $cat->get('id');

        if (!isset($grouped_fields[$catid])) {
            $grouped_fields[$catid] = [
                'id' => $catid,
                'name' => $cat->get('name'),
                'fields' => []
            ];
        }

        $data = $instance_data[$field->get('id')] ?? null;

        $raw = $data ? $data->get_value() : null;
        $formatted = $data ? $data->export_value() : null;

        if (is_object($formatted) || is_array($formatted)) {
            $formatted = json_decode(json_encode($formatted), true);
        }

        $grouped_fields[$catid]['fields'][] = [
            'id'        => $field->get('id'),
            'shortname' => $field->get('shortname'),
            'type'      => $field->get('type'),
            'raw'       => $raw,
            'formatted' => $formatted,
        ];
    }

    $course_data['custom_fields'] = array_values($grouped_fields);

    // ---------- STAFF ----------
    $context = context_course::instance($course->id);
    $users = get_role_users(null, $context, false,
        'u.*, r.shortname as roleshortname, r.name as rolename');

    $staff = [];

    foreach ($users as $u) {
        if ($u->roleshortname === 'student') {
            continue;
        }

        unset($u->password);

        if (!isset($staff[$u->id])) {
            $staff[$u->id] = (array) $u;
            unset($staff[$u->id]['roleshortname'], $staff[$u->id]['rolename']);
            $staff[$u->id]['roles_in_course'] = [];
        }

        $staff[$u->id]['roles_in_course'][] = [
            'shortname' => $u->roleshortname,
            'name' => $u->rolename
        ];
    }

    $course_data['staff_members'] = array_values($staff);

    return $course_data;
}


function generate_course_backup(stdClass $course, bool $withusers, string $dir): ?array {
    global $admin;

    $bc = new backup_controller(
        backup::TYPE_1COURSE,
        $course->id,
        backup::FORMAT_MOODLE,
        backup::INTERACTIVE_NO,
        backup::MODE_GENERAL,
        $admin->id
    );

    $plan = $bc->get_plan();
    $plan->get_setting('users')->set_value($withusers);
    $plan->get_setting('anonymize')->set_value(!$withusers);

    $filename = backup_plan_dbops::get_default_backup_filename(
        $bc->get_format(),
        $bc->get_type(),
        $bc->get_id(),
        $withusers,
        !$withusers
    );

    $plan->get_setting('filename')->set_value($filename);

    $bc->execute_plan();
    $results = $bc->get_results();
    $file = $results['backup_destination'] ?? null;

    if ($file && $file->copy_content_to($dir.'/'.$filename)) {
        $file->delete();
        $bc->destroy();

        return [
            'with_users'   => $withusers,
            'filename'     => $filename,
            'filesize'     => filesize($dir.'/'.$filename),
            'generated_at' => date(DATE_ATOM),
        ];
    }

    $bc->destroy();
    return null;
}


$courses_export = [];
$backups_export = [];

$final_export = [];
$handler = \core_customfield\handler::get_handler('core_course', 'course');

foreach ($courses as $cs) {
    $course = get_course($cs->id);
    mtrace("Processando curso: {$course->shortname}");

    $course_data = [];

    if ($dojson) {
        $course_data = export_course_metadata($course, $handler);
        $courses_export[] = $course_data;
    }

    if ($dobackup) {

        // 1️⃣ Backup COM usuários (já gerado)
        $backup_users = generate_course_backup($course, true, $dir);

        if ($backup_users && !empty($backup_users['filename'])) {

            // Registra backup COM usuários
            $backup_users['course_id'] = $course->id;
            $backups_export[] = $backup_users;

            // 2️⃣ Deriva o filename SEM usuários
            $filename_users = $backup_users['filename'];

            // garante que termina com .mbz
            if (str_ends_with($filename_users, '.mbz')) {
                $filename_nu = str_replace('.mbz', '-nu.mbz', $filename_users);
            } else {
                $filename_nu = $filename_users . '-nu';
            }

            // 3️⃣ Registra backup SEM usuários (sem gerar arquivo)
            $backups_export[] = [
                'with_users'   => false,
                'filename'     => $filename_nu,
                'generated_at' => date(DATE_ATOM),
                'course_id'    => $course->id,
            ];
        }

        // foreach ([false, true] as $withusers) {
        //     $backup = generate_course_backup($course, $withusers, $dir);

        //     if ($backup) {
        //         // 🔑 CHAVE ESTRANGEIRA
        //         $backup['course_id'] = $course->id;

        //         $backups_export[] = $backup;
        //     }
        // }
    }
}

if ($dojson) {
    $filename = 'courses_' . date('Ymd_His') . '.json';
    file_put_contents(
        $dir . '/' . $filename,
        json_encode([
            'schema_version' => '1.0',
            'generated_at'   => date(DATE_ATOM),
            'courses'        => $courses_export
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
    );

    mtrace("JSON de cursos salvo em {$filename}");
}

if ($dobackup) {
    $filename = 'course_backups_' . date('Ymd_His') . '.json';

    file_put_contents(
        $dir . '/' . $filename,
        json_encode([
            'schema_version' => '1.0',
            'generated_at'   => date(DATE_ATOM),
            'backups'        => $backups_export
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
    );

    mtrace("JSON de backups salvo em {$filename}");
}

mtrace(get_string('operationdone', 'tool_brcli'));

exit(0);