<?php
/**
 * admin tool brcli
 * Backup & restore command line interface
 * @package admin
 * @subpackage tool
 * @author Paulo Júnior <pauloa.junior@ufla.br> based on /admin/cli/backup.php
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
define('CLI_SCRIPT', 1);

require(__DIR__.'/../../../config.php');
require_once($CFG->libdir.'/clilib.php');
require_once($CFG->dirroot . '/backup/util/includes/backup_includes.php');

// Now get cli options.
list($options, $unrecognized) = cli_get_params([
    'destination' => '',
    'users'       => 0,
    'export'      => 'all', // json | backup | all
    'help'        => false,
], ['h' => 'help']);

if ($unrecognized) {
    $unrecognized = implode("\n  ", $unrecognized);
    cli_error(get_string('unknowoption', 'tool_brcli', $unrecognized));
}

if ($options['help'] || !$options['destination']) {
    echo "Backup de todos os cursos do Moodle\n\n";
    echo "Opções:\n";
    echo "  --destination=PATH    Diretório onde salvar os backups (obrigatório)\n";
    echo "  --users=0|1           1=com usuários, 0=sem usuários (padrão: 0)\n";
    echo "  --export=all|json|backup  O que exportar (padrão: all)\n";
    echo "  -h, --help            Exibe esta ajuda\n\n";
    echo "Exemplo:\n";
    echo "  php backup.php --destination=/var/backups/moodle --users=1\n\n";
    die;
}

$admin = get_admin();
if (!$admin) {
    cli_error(get_string('noadminaccount', 'tool_brcli'));
}
\core\session\manager::set_user($admin);

// Verifica diretório de destino
$dir = rtrim($options['destination'], '/');
if (empty($dir) || !file_exists($dir) || !is_dir($dir) || !is_writable($dir)) {
    cli_error(get_string('directoryerror', 'tool_brcli'));
}

$dojson   = in_array($options['export'], ['json', 'all']);
$dobackup = in_array($options['export'], ['backup', 'all']);
$withusers = (bool) $options['users'];

// Busca todos os cursos exceto frontpage
$courses = $DB->get_records_select('course', 'id > 1');

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

    if ($plan->get_setting('logs')) {
        $plan->get_setting('logs')->set_value(0); 
    }
    
    if ($plan->get_setting('grade_histories')) {
        $plan->get_setting('grade_histories')->set_value(0);
    }

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
            'course_id'    => $course->id,
            'with_users'   => $withusers,
            'filename'     => $filename,
            'filesize'     => filesize($dir.'/'.$filename),
            'generated_at' => date(DATE_ATOM),
        ];
    }

    $bc->destroy();
    return null;
}


$state_file = $dir . '/backup_state_in_progress.json';
$processed_json = [];
$processed_backup = [];
// $processed_courses = [];
$courses_export = [];
$backups_export = [];

// Carrega estado anterior se existir
if (file_exists($state_file)) {
    $state_data = json_decode(file_get_contents($state_file), true);

    $processed_json = $state_data['processed_json'] ?? [];
    $processed_backup = $state_data['processed_backup'] ?? [];

    if ($dojson && isset($state_data['courses_data'])) {
        $courses_export = $state_data['courses_data'];
    }
    
    if ($dobackup && isset($state_data['backups_data'])) {
        $backups_export = $state_data['backups_data'];
    }

    mtrace(sprintf(
        'Retomando backup: %d JSONS e %d backups processados',
        count($processed_json),
        count($processed_backup)
    ));
}

$handler = \core_customfield\handler::get_handler('core_course', 'course');

$total = count($courses);
$counter = 0;

foreach ($courses as $cs) {
    $counter++;

    // Verifica se precisa processar este curso
    $need_json = $dojson && !in_array($cs->id, $processed_json);
    $need_backup = $dobackup && !in_array($cs->id, $processed_backup);

    // Pula cursos já processados
    if (!$need_json && !$need_backup) {
        mtrace(sprintf('[%d/%d] Pulando curso já processado: ID %d', $counter, $total, $cs->id));
        continue;
    }

    $percent = round(($counter / $total) * 100, 2);
    $course = get_course($cs->id);

    $operations = [];
    if ($need_json) $operations[] = 'JSON';
    if ($need_backup) $operations[] = 'BACKUP';
    
    mtrace(sprintf(
        '[%d/%d | %s%%] Processando curso: %s (ID: %d)',
        $counter,
        $total,
        $percent,
        $course->shortname,
        $course->id,
        implode(', ', $operations)
    ));

    try {
        // Exporta metadados JSON
        if ($need_json) {
            $course_data = export_course_metadata($course, $handler);
            $courses_export[] = $course_data;
            $processed_json[] = $cs->id;
        }

        // Gera backup
        if ($need_backup) {
            $backup = generate_course_backup($course, $withusers, $dir);
            
            if ($backup) {
                $backups_export[] = $backup;
                $processed_backup[] = $cs->id;
            }
        }
        
        // Salva estado a cada curso processado
        $state_to_save = [
            'last_updated' => date(DATE_ATOM),
            'processed_json' => $processed_json,
            'processed_backup' => $processed_backup,
            'total_courses' => $total
        ];

        // Salva dados parciais para retomada
        if ($dojson) {
            $state_to_save['courses_data'] = $courses_export;
        }

        if ($dobackup) {
            $state_to_save['backups_data'] = $backups_export;
        }

        file_put_contents(
            $state_file,
            json_encode($state_to_save, JSON_PRETTY_PRINT)
        );

    } catch (Exception $e) {
        mtrace('ERRO ao processar curso ' . $cs->id . ': ' . $e->getMessage());
        // Continua para o próximo curso mesmo em caso de erro
        continue;
    }
}

// Salva JSON de cursos
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

// Salva JSON de backups
if ($dobackup) {
    $filename = 'course_backups_' . date('Ymd_His') . '.json';

    file_put_contents(
        $dir . '/' . $filename,
        json_encode([
            'schema_version' => '1.0',
            'generated_at'   => date(DATE_ATOM),
            'with_users'     => $withusers,
            'backups'        => $backups_export
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
    );

    mtrace("JSON de backups salvo em {$filename}");
}

mtrace(get_string('operationdone', 'tool_brcli'));

exit(0);