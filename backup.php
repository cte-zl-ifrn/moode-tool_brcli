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
\core\session\manager::set_user($admin);

// Do we need to store backup somewhere else?
$dir = rtrim($options['destination'], '/');
if (empty($dir) || !file_exists($dir) || !is_dir($dir) || !is_writable($dir)) {
    cli_error(get_string('directoryerror', 'tool_brcli'));
}

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
$amount_of_courses = count($courses);

$index = 1;

$final_export = [];

// Prepara o handler fora do loop
$custom_field_handler = \core_customfield\handler::get_handler('core_course', 'course');

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
    $course_data = (array) $course;

    $all_fields_data = $custom_field_handler->get_instance_data($course->id);

    $grouped_fields = [];

    foreach ($all_fields_data as $d) {
        $field = $d->get_field();
        $category = $field->get_category();
        
        $cat_name = $category->get('name');
        $field_shortname = $field->get('shortname');
        $field_type = $field->get('type'); // date, text, select, checkbox, etc.
        
        // 1. Pegamos o valor bruto do banco de dados (sem processamento)
        $raw_value = $d->get_value();
        
        // 2. Tentamos pegar o valor formatado (pode vir vazio dependendo do tipo)
        $formatted_value = $d->export_value();

        // Inicializa a categoria se não existir
        if (!isset($grouped_fields[$cat_name])) {
            $grouped_fields[$cat_name] = [];
        }

        // Para garantir que não percamos nada, vamos salvar um objeto com tudo
        $grouped_fields[$cat_name][$field_shortname] = [
            'raw'       => $raw_value,       // O dado real (ID, Timestamp, 0 ou 1)
            'formatted' => $formatted_value, // O dado visual
            'type'      => $field_type       // O tipo do campo para você saber tratar depois
        ];
    }
    
    $course_data['custom_fields'] = $grouped_fields;
    
    
    $context = context_course::instance($course->id);

    $all_role_users = get_role_users(
        null, 
        $context, 
        false, 
        'u.*, r.shortname as roleshortname, r.name as rolename'
    );

    $staff_map = [];

    foreach ($all_role_users as $u) {
        // FILTRO: Ignora se o papel for estudante
        if ($u->roleshortname === 'student') {
            continue;
        }

        // Remove a senha (hash) por segurança, mesmo sendo "excesso",
        // nunca é bom ter hash de senha em arquivo texto.
        unset($u->password);

        // O usuário já existe no array temporário? (Caso ele tenha 2 papéis, ex: tutor e professor)
        if (!isset($staff_map[$u->id])) {
            // Cria a base do usuário convertendo o objeto todo para array
            $user_base = (array) $u;
            
            // Remove os campos "soltos" de role da raiz do objeto do usuário para organizar melhor
            unset($user_base['roleshortname']);
            unset($user_base['rolename']);

            // Inicializa array de roles
            $user_base['roles_in_course'] = [];

            $staff_map[$u->id] = $user_base;
        }

        // Adiciona o papel atual à lista de papéis desse usuário
        $staff_map[$u->id]['roles_in_course'][] = [
            'shortname' => $u->roleshortname, // ex: editingteacher
            'name'      => $u->rolename       // ex: Professor
        ];
    }

    // Adiciona a lista de staff ao objeto do curso
    // array_values reseta as chaves para ficar uma lista perfeita no JSON [{}, {}]
    $course_data['staff_members'] = array_values($staff_map);
    
    // Informações extras do backup (opcional, mas útil para vincular o json ao arquivo .mbz)
    $course_data['backup_file'] = $filename;

    // 3. Acumula no array final
    $final_export[] = $course_data;

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

$final_json_filename = 'backup_summary_' . date('Ymd_His') . '.json';

$json_content = json_encode($final_export, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);

if ($json_content === false) {
    mtrace("Erro ao gerar JSON: " . json_last_error_msg());
} else {
    file_put_contents($dir . '/' . $final_json_filename, $json_content);
    mtrace("Arquivo JSON consolidado salvo em: " . $final_json_filename);
}

mtrace(get_string('operationdone', 'tool_brcli'));

exit(0);