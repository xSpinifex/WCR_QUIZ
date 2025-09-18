<?php
/*
Plugin Name: WCR Quiz
Description: Prosty quiz z rejestracją i panelem zarządzania.
Version: 0.1.0
Author: OpenAI Assistant
*/

if (!defined('ABSPATH')) {
    exit;
}

// Start session for quiz handling
function wcrq_maybe_start_session() {
    if (!session_id()) {
        session_start();
    }
}
add_action('init', 'wcrq_maybe_start_session');

function wcrq_current_timestamp() {
    return current_time('timestamp', true);
}

function wcrq_get_polish_timezone() {
    try {
        return new DateTimeZone('Europe/Warsaw');
    } catch (Exception $e) {
        return wp_timezone();
    }
}

function wcrq_reset_quiz_progress() {
    unset(
        $_SESSION['wcrq_questions'],
        $_SESSION['wcrq_started'],
        $_SESSION['wcrq_result_id'],
        $_SESSION['wcrq_saved_responses'],
        $_SESSION['wcrq_current_question']
    );
}

function wcrq_clear_participant_session($participant_id = 0, $keep_db_token = false) {
    wcrq_reset_quiz_progress();
    unset($_SESSION['wcrq_participant'], $_SESSION['wcrq_session_token']);

    if ($participant_id > 0 && !$keep_db_token) {
        global $wpdb;
        $table = $wpdb->prefix . 'wcrq_participants';
        $wpdb->update($table, [
            'active_session_token' => '',
            'active_session_started' => null,
        ], [
            'id' => intval($participant_id),
        ]);
    }
}

function wcrq_generate_session_token() {
    return wp_generate_password(64, false, false);
}

function wcrq_store_session_token($participant_id, $token) {
    global $wpdb;
    $table = $wpdb->prefix . 'wcrq_participants';
    $wpdb->update($table, [
        'active_session_token' => $token,
        'active_session_started' => wp_date('Y-m-d H:i:s', wcrq_current_timestamp(), wcrq_get_polish_timezone()),
    ], [
        'id' => intval($participant_id),
    ]);
}

function wcrq_get_participant_row($participant_id) {
    if ($participant_id <= 0) {
        return null;
    }

    global $wpdb;
    $table = $wpdb->prefix . 'wcrq_participants';

    return $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id = %d", $participant_id));
}

function wcrq_require_active_participant() {
    if (empty($_SESSION['wcrq_participant'])) {
        return new WP_Error('no_session', __('Sesja wygasła. Zaloguj się ponownie.', 'wcrq'));
    }

    $participant_id = intval($_SESSION['wcrq_participant']);
    $participant = wcrq_get_participant_row($participant_id);
    if (!$participant) {
        wcrq_clear_participant_session();
        return new WP_Error('no_session', __('Sesja wygasła. Zaloguj się ponownie.', 'wcrq'));
    }

    $session_token = isset($_SESSION['wcrq_session_token']) ? (string) $_SESSION['wcrq_session_token'] : '';
    $db_token = isset($participant->active_session_token) ? (string) $participant->active_session_token : '';

    $is_match = false;
    if ($session_token && $db_token) {
        if (function_exists('hash_equals')) {
            $is_match = hash_equals($db_token, $session_token);
        } else {
            $is_match = $db_token === $session_token && strlen($db_token) === strlen($session_token);
        }
    }

    if (!$is_match) {
        wcrq_clear_participant_session(intval($participant->id), true);
        return new WP_Error('session_conflict', __('Twoja sesja została zakończona, ponieważ zalogowano się na innym urządzeniu.', 'wcrq'));
    }

    return $participant;
}

// Enqueue frontend styles
function wcrq_enqueue_assets() {
    wp_enqueue_style('wcrq-style', plugins_url('assets/css/style.css', __FILE__), [], '0.1');
}
add_action('wp_enqueue_scripts', 'wcrq_enqueue_assets');

// Activation: create DB tables
function wcrq_activate() {
    global $wpdb;
    $charset = $wpdb->get_charset_collate();
    $participants_table = $wpdb->prefix . 'wcrq_participants';
    $results_table = $wpdb->prefix . 'wcrq_results';

    $sql1 = "CREATE TABLE $participants_table (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
        school varchar(150) NOT NULL,
        name varchar(100) NOT NULL,
        class varchar(50) NOT NULL,
        email varchar(100) NOT NULL,
        login varchar(60) NOT NULL,
        password varchar(255) NOT NULL,
        pass_plain varchar(255) NOT NULL,
        blocked tinyint(1) NOT NULL DEFAULT 0,
        active_session_token varchar(64) NOT NULL DEFAULT '',
        active_session_started datetime DEFAULT NULL,
        created datetime DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY  (id),
        UNIQUE KEY login (login)
    ) $charset;";

    $sql2 = "CREATE TABLE $results_table (
        id bigint(20) NOT NULL AUTO_INCREMENT,
        participant_id mediumint(9) NOT NULL,
        score float NOT NULL DEFAULT 0,
        start_time datetime NOT NULL,
        end_time datetime NOT NULL,
        duration_seconds int NOT NULL DEFAULT 0,
        violations int NOT NULL DEFAULT 0,
        is_completed tinyint(1) NOT NULL DEFAULT 0,
        answers longtext,
        PRIMARY KEY  (id)
    ) $charset;";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql1);
    dbDelta($sql2);
}
register_activation_hook(__FILE__, 'wcrq_activate');

function wcrq_maybe_update_participants_table() {
    global $wpdb;
    $table = $wpdb->prefix . 'wcrq_participants';
    $table_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table));
    if ($table_exists !== $table) {
        return;
    }
    $blocked_exists = $wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM $table LIKE %s", 'blocked'));
    if (!$blocked_exists) {
        $wpdb->query("ALTER TABLE $table ADD blocked tinyint(1) NOT NULL DEFAULT 0");
    }
    $pass_plain_exists = $wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM $table LIKE %s", 'pass_plain'));
    if (!$pass_plain_exists) {
        $wpdb->query("ALTER TABLE $table ADD pass_plain varchar(255) NOT NULL DEFAULT ''");
    }
    $session_token_exists = $wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM $table LIKE %s", 'active_session_token'));
    if (!$session_token_exists) {
        $wpdb->query("ALTER TABLE $table ADD active_session_token varchar(64) NOT NULL DEFAULT ''");
    }
    $session_started_exists = $wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM $table LIKE %s", 'active_session_started'));
    if (!$session_started_exists) {
        $wpdb->query("ALTER TABLE $table ADD active_session_started datetime DEFAULT NULL");
    }
}
add_action('plugins_loaded', 'wcrq_maybe_update_participants_table');

function wcrq_maybe_update_results_table() {
    global $wpdb;
    $table = $wpdb->prefix . 'wcrq_results';
    $table_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table));
    if ($table_exists !== $table) {
        return;
    }

    $columns = $wpdb->get_results("SHOW COLUMNS FROM $table", ARRAY_A);
    $column_names = wp_list_pluck($columns, 'Field');

    if (!in_array('duration_seconds', $column_names, true)) {
        $wpdb->query("ALTER TABLE $table ADD duration_seconds int NOT NULL DEFAULT 0 AFTER end_time");
    }
    if (!in_array('violations', $column_names, true)) {
        $wpdb->query("ALTER TABLE $table ADD violations int NOT NULL DEFAULT 0 AFTER duration_seconds");
    }
    if (!in_array('is_completed', $column_names, true)) {
        $wpdb->query("ALTER TABLE $table ADD is_completed tinyint(1) NOT NULL DEFAULT 0 AFTER violations");
    }
    $score_column = $wpdb->get_row($wpdb->prepare("SHOW COLUMNS FROM $table LIKE %s", 'score'), ARRAY_A);
    if ($score_column && (empty($score_column['Default']) || $score_column['Default'] === null)) {
        $wpdb->query("ALTER TABLE $table MODIFY score float NOT NULL DEFAULT 0");
    }
}
add_action('plugins_loaded', 'wcrq_maybe_update_results_table');

function wcrq_maybe_create_tables() {
    global $wpdb;
    $participants_table = $wpdb->prefix . 'wcrq_participants';
    $results_table = $wpdb->prefix . 'wcrq_results';
    $p_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $participants_table));
    $r_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $results_table));
    if ($p_exists !== $participants_table || $r_exists !== $results_table) {
        wcrq_activate();
    }
}
add_action('plugins_loaded', 'wcrq_maybe_create_tables');

// Settings page
function wcrq_register_settings() {
    register_setting('wcrq_settings', 'wcrq_settings', 'wcrq_sanitize_settings');

    add_settings_section('wcrq_main', __('Ustawienia quizu', 'wcrq'), '__return_false', 'wcrq');

    add_settings_field('start_time', __('Czas rozpoczęcia', 'wcrq'), 'wcrq_field_start_time', 'wcrq', 'wcrq_main');
    add_settings_field('end_time', __('Czas zakończenia', 'wcrq'), 'wcrq_field_end_time', 'wcrq', 'wcrq_main');
    add_settings_field('pre_start_text', __('Tekst przed rozpoczęciem', 'wcrq'), 'wcrq_field_pre_start_text', 'wcrq', 'wcrq_main');
    add_settings_field('pre_quiz_text', __('Tekst przed kliknięciem START', 'wcrq'), 'wcrq_field_pre_quiz_text', 'wcrq', 'wcrq_main');
    add_settings_field('post_quiz_text', __('Tekst po zakończeniu', 'wcrq'), 'wcrq_field_post_quiz_text', 'wcrq', 'wcrq_main');
    add_settings_field('randomize_questions', __('Losowa kolejność pytań', 'wcrq'), 'wcrq_field_randomize_questions', 'wcrq', 'wcrq_main');
    add_settings_field('allow_navigation', __('Zezwól na cofanie pytań', 'wcrq'), 'wcrq_field_allow_navigation', 'wcrq', 'wcrq_main');
    add_settings_field('show_results', __('Pokaż wynik użytkownikowi', 'wcrq'), 'wcrq_field_show_results', 'wcrq', 'wcrq_main');
    add_settings_field('show_violations_to_users', __('Pokazuj naruszenia uczestnikom', 'wcrq'), 'wcrq_field_show_violations_to_users', 'wcrq', 'wcrq_main');
}
add_action('admin_init', 'wcrq_register_settings');

function wcrq_admin_menu() {
    add_menu_page('WCR Quiz', 'WCR Quiz', 'manage_options', 'wcrq', 'wcrq_settings_page_html', 'dashicons-welcome-learn-more', 20);
    add_submenu_page('wcrq', __('Ustawienia quizu', 'wcrq'), __('Ustawienia quizu', 'wcrq'), 'manage_options', 'wcrq', 'wcrq_settings_page_html');
    add_submenu_page('wcrq', __('Pytania', 'wcrq'), __('Pytania', 'wcrq'), 'manage_options', 'wcrq_questions', 'wcrq_questions_page_html');
    add_submenu_page('wcrq', __('Rejestracje', 'wcrq'), __('Rejestracje', 'wcrq'), 'manage_options', 'wcrq_registrations', 'wcrq_registrations_page_html');
    add_submenu_page('wcrq', __('Wyniki', 'wcrq'), __('Wyniki', 'wcrq'), 'manage_options', 'wcrq_results', 'wcrq_results_page_html');
}
add_action('admin_menu', 'wcrq_admin_menu');

function wcrq_admin_enqueue_assets($hook_suffix) {
    if (strpos($hook_suffix, 'wcrq_results') === false) {
        return;
    }

    wp_enqueue_style('wcrq-admin-results', plugins_url('assets/css/results-admin.css', __FILE__), [], '0.1');
    wp_enqueue_script('wcrq-admin-results', plugins_url('assets/js/results.js', __FILE__), [], '0.1', true);
}
add_action('admin_enqueue_scripts', 'wcrq_admin_enqueue_assets');

function wcrq_parse_datetime_local($value) {
    if (empty($value)) {
        return 0;
    }

    $tz = wcrq_get_polish_timezone();
    $value = wp_unslash($value);

    $formats = ['Y-m-d\TH:i', DateTimeInterface::ATOM];
    foreach ($formats as $format) {
        $dt = DateTime::createFromFormat($format, $value, $tz);
        if ($dt instanceof DateTime) {
            return $dt->getTimestamp();
        }
    }

    $timestamp = strtotime($value);
    return $timestamp ? $timestamp : 0;
}

function wcrq_local_datetime_to_timestamp($datetime) {
    if (empty($datetime)) {
        return 0;
    }

    $tz = wcrq_get_polish_timezone();
    $dt = DateTime::createFromFormat('Y-m-d H:i:s', $datetime, $tz);
    if ($dt instanceof DateTime) {
        return $dt->getTimestamp();
    }

    $timestamp = strtotime($datetime);
    return $timestamp ? $timestamp : 0;
}

function wcrq_format_polish_datetime($timestamp) {
    $timestamp = intval($timestamp);
    if ($timestamp <= 0) {
        return '';
    }

    $tz = wcrq_get_polish_timezone();
    return wp_date('d.m.Y H:i', $timestamp, $tz);
}

function wcrq_sanitize_settings($input) {
    $output = get_option('wcrq_settings', []);

    $output['start_time'] = isset($input['start_time']) ? sanitize_text_field(wp_unslash($input['start_time'])) : '';
    $output['end_time'] = isset($input['end_time']) ? sanitize_text_field(wp_unslash($input['end_time'])) : '';
    $output['pre_start_text'] = isset($input['pre_start_text']) ? wp_kses_post(wp_unslash($input['pre_start_text'])) : '';
    $output['pre_quiz_text'] = isset($input['pre_quiz_text']) ? wp_kses_post(wp_unslash($input['pre_quiz_text'])) : '';
    $output['post_quiz_text'] = isset($input['post_quiz_text']) ? wp_kses_post(wp_unslash($input['post_quiz_text'])) : '';
    $output['randomize_questions'] = !empty($input['randomize_questions']) ? 1 : 0;
    $output['allow_navigation'] = !empty($input['allow_navigation']) ? 1 : 0;
    $output['show_results'] = !empty($input['show_results']) ? 1 : 0;
    $output['show_violations_to_users'] = !empty($input['show_violations_to_users']) ? 1 : 0;

    if (isset($input['questions'])) {
        $questions = $input['questions'];

        if (is_string($questions)) {
            $decoded = json_decode($questions, true);
            $questions = is_array($decoded) ? $decoded : [];
        }

        if (is_array($questions)) {
            $sanitized_questions = [];

            foreach ($questions as $question) {
                $prepared = wcrq_prepare_question_data($question, false);
                if ($prepared) {
                    $sanitized_questions[] = $prepared;
                }
            }

            $output['questions'] = $sanitized_questions;
        }
    }

    return $output;
}

function wcrq_prepare_question_data($question, $unslash = true) {
    if (!is_array($question)) {
        return null;
    }

    $question_value = $question['question'] ?? '';
    if ($unslash) {
        $question_value = wp_unslash($question_value);
    }
    $question_text = sanitize_text_field($question_value);

    $answers = [];

    if (!empty($question['answers']) && is_array($question['answers'])) {
        foreach ($question['answers'] as $answer) {
            if ($unslash) {
                $answer = wp_unslash($answer);
            }
            $answers[] = sanitize_text_field($answer);
        }
    }

    $answers = array_pad($answers, 4, '');

    $correct_value = $question['correct'] ?? 0;
    if ($unslash) {
        $correct_value = wp_unslash($correct_value);
    }
    $correct = intval($correct_value);
    if ($correct < 0 || $correct > 3) {
        $correct = 0;
    }

    $image_value = $question['image'] ?? '';
    if ($unslash) {
        $image_value = wp_unslash($image_value);
    }
    $image = esc_url_raw($image_value);

    $has_content = $question_text !== '' || array_filter($answers);
    if (!$has_content) {
        return null;
    }

    return [
        'question' => $question_text,
        'answers' => array_slice($answers, 0, 4),
        'correct' => $correct,
        'image' => $image,
    ];
}

function wcrq_format_duration($seconds) {
    $seconds = max(0, intval($seconds));
    $hours = floor($seconds / HOUR_IN_SECONDS);
    $minutes = floor(($seconds % HOUR_IN_SECONDS) / MINUTE_IN_SECONDS);
    $remaining = $seconds % MINUTE_IN_SECONDS;

    return sprintf('%02d:%02d:%02d', $hours, $minutes, $remaining);
}

function wcrq_extract_result_details($answers_value) {
    $details = [];
    $data = maybe_unserialize($answers_value);

    $questions = [];
    $responses = [];

    if (is_array($data)) {
        if (isset($data['questions']) && is_array($data['questions'])) {
            $questions = $data['questions'];
        }
        if (isset($data['responses']) && is_array($data['responses'])) {
            $responses = $data['responses'];
        } elseif (!$questions) {
            $responses = $data;
        }
    }

    if ($questions) {
        foreach ($questions as $index => $question) {
            $answers = isset($question['answers']) && is_array($question['answers']) ? $question['answers'] : [];
            $selected = isset($responses[$index]) ? intval($responses[$index]) : -1;
            $correct = isset($question['correct']) ? intval($question['correct']) : -1;

            $details[] = [
                'label' => sprintf(__('Pytanie %d', 'wcrq'), $index + 1),
                'question' => $question['question'] ?? '',
                'answers' => $answers,
                'selected' => $selected,
                'correct' => $correct,
                'is_correct' => $selected >= 0 && $selected === $correct,
                'image' => !empty($question['image']) ? esc_url_raw($question['image']) : '',
            ];
        }
    } elseif ($responses) {
        foreach ($responses as $index => $selected) {
            $details[] = [
                'label' => sprintf(__('Pytanie %d', 'wcrq'), $index + 1),
                'question' => '',
                'answers' => [],
                'selected' => intval($selected),
                'correct' => null,
                'is_correct' => null,
                'image' => '',
            ];
        }
    }

    return $details;
}

function wcrq_get_saved_questions() {
    $options = get_option('wcrq_settings', []);
    $stored = $options['questions'] ?? [];

    if (is_string($stored)) {
        $decoded = json_decode($stored, true);
        $stored = is_array($decoded) ? $decoded : [];
    }

    if (!is_array($stored)) {
        return [];
    }

    $questions = [];
    foreach ($stored as $question) {
        $prepared = wcrq_prepare_question_data($question, false);
        if ($prepared) {
            $questions[] = $prepared;
        }
    }

    return $questions;
}

function wcrq_prepare_questions_snapshot($questions) {
    $snapshot = [];
    foreach ($questions as $index => $question) {
        $snapshot[$index] = [
            'question' => $question['question'] ?? '',
            'answers' => isset($question['answers']) && is_array($question['answers']) ? array_values($question['answers']) : [],
            'correct' => isset($question['correct']) ? intval($question['correct']) : 0,
        ];
        if (!empty($question['image'])) {
            $snapshot[$index]['image'] = esc_url_raw($question['image']);
        }
    }

    return $snapshot;
}

function wcrq_prepare_questions_from_snapshot($snapshot) {
    if (!is_array($snapshot)) {
        return [];
    }

    $questions = [];
    foreach ($snapshot as $question) {
        $questions[] = [
            'question' => isset($question['question']) ? wp_kses_post($question['question']) : '',
            'answers' => isset($question['answers']) && is_array($question['answers']) ? array_values($question['answers']) : [],
            'correct' => isset($question['correct']) ? intval($question['correct']) : 0,
            'image' => !empty($question['image']) ? esc_url($question['image']) : '',
        ];
    }

    return $questions;
}

function wcrq_guess_current_question_index($questions, $responses) {
    if (!is_array($questions) || !$questions) {
        return 0;
    }

    $total = count($questions);
    if (!is_array($responses)) {
        return 0;
    }

    for ($i = 0; $i < $total; $i++) {
        if (!array_key_exists($i, $responses) || intval($responses[$i]) < 0) {
            return $i;
        }
    }

    return max(0, $total - 1);
}

function wcrq_get_active_result_row($participant_id) {
    global $wpdb;
    $table = $wpdb->prefix . 'wcrq_results';

    return $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM $table WHERE participant_id = %d AND is_completed = 0 ORDER BY id DESC LIMIT 1",
        $participant_id
    ));
}

function wcrq_get_latest_completed_result_row($participant_id) {
    global $wpdb;
    $table = $wpdb->prefix . 'wcrq_results';

    return $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM $table WHERE participant_id = %d AND is_completed = 1 ORDER BY end_time DESC, id DESC LIMIT 1",
        $participant_id
    ));
}

function wcrq_sync_active_result_session($participant_id) {
    $row = wcrq_get_active_result_row($participant_id);
    if (!$row) {
        wcrq_reset_quiz_progress();
        return null;
    }

    $_SESSION['wcrq_result_id'] = intval($row->id);

    $start_timestamp = wcrq_local_datetime_to_timestamp($row->start_time);
    if ($start_timestamp > 0) {
        $_SESSION['wcrq_started'] = $start_timestamp;
    }

    $answers = maybe_unserialize($row->answers);
    if (is_array($answers)) {
        if (!empty($answers['questions']) && is_array($answers['questions'])) {
            $_SESSION['wcrq_questions'] = wcrq_prepare_questions_from_snapshot($answers['questions']);
        }
        if (!empty($answers['responses']) && is_array($answers['responses'])) {
            $_SESSION['wcrq_saved_responses'] = array_map('intval', $answers['responses']);
        } else {
            $_SESSION['wcrq_saved_responses'] = [];
        }
        $current_index = isset($answers['current']) ? intval($answers['current']) : null;
    } else {
        $_SESSION['wcrq_saved_responses'] = [];
        $current_index = null;
    }

    $questions = isset($_SESSION['wcrq_questions']) && is_array($_SESSION['wcrq_questions']) ? $_SESSION['wcrq_questions'] : [];
    $responses = isset($_SESSION['wcrq_saved_responses']) && is_array($_SESSION['wcrq_saved_responses']) ? $_SESSION['wcrq_saved_responses'] : [];
    $question_count = count($questions);
    if ($question_count > 0) {
        if ($current_index === null) {
            $current_index = wcrq_guess_current_question_index($questions, $responses);
        }
        $current_index = max(0, min(intval($current_index), $question_count - 1));
        $_SESSION['wcrq_current_question'] = $current_index;
    } else {
        $_SESSION['wcrq_current_question'] = 0;
    }

    return $row;
}

function wcrq_finalize_result_row($row, $forced_end_timestamp = null) {
    if (!$row) {
        return;
    }

    $answers = maybe_unserialize($row->answers);
    if (!is_array($answers) || empty($answers['questions'])) {
        return;
    }

    $questions = wcrq_prepare_questions_from_snapshot($answers['questions']);
    if (!$questions) {
        return;
    }

    $responses = [];
    if (!empty($answers['responses']) && is_array($answers['responses'])) {
        $responses = array_map('intval', $answers['responses']);
    }

    $correct = 0;
    foreach ($questions as $index => $question) {
        $given = isset($responses[$index]) ? intval($responses[$index]) : -1;
        if ($given >= 0 && $given === intval($question['correct'])) {
            $correct++;
        }
    }

    $question_count = count($questions);
    $score = $question_count ? round($correct / $question_count * 100, 2) : 0;

    $start_timestamp = wcrq_local_datetime_to_timestamp($row->start_time);
    if ($start_timestamp <= 0) {
        $start_timestamp = wcrq_current_timestamp();
    }

    $end_timestamp = $forced_end_timestamp !== null ? intval($forced_end_timestamp) : wcrq_current_timestamp();
    if ($end_timestamp < $start_timestamp) {
        $end_timestamp = $start_timestamp;
    }

    $end_mysql = wp_date('Y-m-d H:i:s', $end_timestamp, wcrq_get_polish_timezone());
    $duration = max(0, $end_timestamp - $start_timestamp);

    global $wpdb;
    $table = $wpdb->prefix . 'wcrq_results';

    $wpdb->update($table, [
        'score' => $score,
        'end_time' => $end_mysql,
        'duration_seconds' => $duration,
        'is_completed' => 1,
        'answers' => maybe_serialize($answers),
    ], [
        'id' => intval($row->id),
    ]);
}

function wcrq_finalize_expired_results() {
    $options = get_option('wcrq_settings');
    $end_time = !empty($options['end_time']) ? wcrq_parse_datetime_local($options['end_time']) : 0;
    if (!$end_time) {
        return;
    }

    $now = wcrq_current_timestamp();
    if ($now <= $end_time) {
        return;
    }

    global $wpdb;
    $table = $wpdb->prefix . 'wcrq_results';
    $rows = $wpdb->get_results("SELECT * FROM $table WHERE is_completed = 0");
    if (!$rows) {
        return;
    }

    foreach ($rows as $row) {
        wcrq_finalize_result_row($row, $end_time);
    }
}
add_action('init', 'wcrq_finalize_expired_results');

function wcrq_ensure_result_record($questions) {
    $participant = wcrq_require_active_participant();
    if (is_wp_error($participant)) {
        return 0;
    }

    global $wpdb;
    $table = $wpdb->prefix . 'wcrq_results';
    $participant_id = intval($participant->id);
    $result_id = isset($_SESSION['wcrq_result_id']) ? intval($_SESSION['wcrq_result_id']) : 0;

    if ($result_id > 0) {
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id = %d AND participant_id = %d", $result_id, $participant_id));
        if ($row && intval($row->is_completed) === 0) {
            $answers = maybe_unserialize($row->answers);
            if (!is_array($answers)) {
                $answers = [];
            }
            $needs_update = false;
            if (empty($answers['questions'])) {
                $answers['questions'] = wcrq_prepare_questions_snapshot($questions);
                $needs_update = true;
            }
            if (!isset($answers['current'])) {
                $answers['current'] = isset($_SESSION['wcrq_current_question']) ? intval($_SESSION['wcrq_current_question']) : 0;
                $needs_update = true;
            }
            if ($needs_update) {
                $wpdb->update($table, ['answers' => maybe_serialize($answers)], ['id' => $result_id]);
            }
            return $result_id;
        }
    }

    $existing = wcrq_get_active_result_row($participant_id);
    if ($existing) {
        $_SESSION['wcrq_result_id'] = intval($existing->id);
        $answers = maybe_unserialize($existing->answers);
        if (!is_array($answers)) {
            $answers = [];
        }
        $needs_update = false;
        if (empty($answers['questions'])) {
            $answers['questions'] = wcrq_prepare_questions_snapshot($questions);
            $needs_update = true;
        }
        if (!isset($answers['current'])) {
            $answers['current'] = isset($_SESSION['wcrq_current_question']) ? intval($_SESSION['wcrq_current_question']) : 0;
            $needs_update = true;
        }
        if ($needs_update) {
            $wpdb->update($table, ['answers' => maybe_serialize($answers)], ['id' => intval($existing->id)]);
        }
        return intval($existing->id);
    }

    $start_timestamp = isset($_SESSION['wcrq_started']) ? intval($_SESSION['wcrq_started']) : wcrq_current_timestamp();
    $start_mysql = wp_date('Y-m-d H:i:s', $start_timestamp, wcrq_get_polish_timezone());

    $initial_answers = [
        'questions' => wcrq_prepare_questions_snapshot($questions),
        'responses' => [],
        'current' => 0,
    ];

    $wpdb->insert($table, [
        'participant_id' => $participant_id,
        'score' => 0,
        'start_time' => $start_mysql,
        'end_time' => $start_mysql,
        'duration_seconds' => 0,
        'violations' => 0,
        'is_completed' => 0,
        'answers' => maybe_serialize($initial_answers),
    ]);

    $result_id = intval($wpdb->insert_id);
    $_SESSION['wcrq_result_id'] = $result_id;

    return $result_id;
}

function wcrq_questions_page_html() {
    if (!current_user_can('manage_options')) {
        return;
    }

    $options = get_option('wcrq_settings', []);
    $stored = $options['questions'] ?? [];
    if (is_string($stored)) {
        $decoded = json_decode($stored, true);
        $stored = is_array($decoded) ? $decoded : [];
    }
    if (!is_array($stored)) {
        $stored = [];
    }

    $message = '';
    $message_class = 'updated';

    if (!empty($_POST['wcrq_questions_nonce']) && wp_verify_nonce($_POST['wcrq_questions_nonce'], 'wcrq_questions_action')) {
        $action = $_POST['wcrq_action'] ?? '';
        if ($action === 'add') {
            $raw_question = [
                'question' => $_POST['wcrq_question'] ?? '',
                'answers' => [
                    $_POST['wcrq_answer_0'] ?? '',
                    $_POST['wcrq_answer_1'] ?? '',
                    $_POST['wcrq_answer_2'] ?? '',
                    $_POST['wcrq_answer_3'] ?? '',
                ],
                'correct' => $_POST['wcrq_correct'] ?? 0,
                'image' => $_POST['wcrq_image'] ?? '',
            ];

            $prepared = wcrq_prepare_question_data($raw_question);

            if ($prepared) {
                $stored[] = $prepared;
                $options['questions'] = array_values($stored);
                update_option('wcrq_settings', $options);
                $message = __('Pytanie zostało dodane.', 'wcrq');
            } else {
                $message = __('Nie udało się dodać pytania. Upewnij się, że podałeś treść pytania i odpowiedzi.', 'wcrq');
                $message_class = 'error';
            }
        } elseif ($action === 'delete') {
            $index = isset($_POST['wcrq_question_index']) ? intval($_POST['wcrq_question_index']) : -1;
            if ($index >= 0 && $index < count($stored)) {
                array_splice($stored, $index, 1);
                $options['questions'] = array_values($stored);
                update_option('wcrq_settings', $options);
                $message = __('Pytanie zostało usunięte.', 'wcrq');
            } else {
                $message = __('Nie udało się usunąć pytania.', 'wcrq');
                $message_class = 'error';
            }
        }

        // Refresh stored data after modifications
        $options = get_option('wcrq_settings', []);
        $stored = $options['questions'] ?? [];
        if (is_string($stored)) {
            $decoded = json_decode($stored, true);
            $stored = is_array($decoded) ? $decoded : [];
        }
        if (!is_array($stored)) {
            $stored = [];
        }
    }

    $questions = [];
    foreach ($stored as $question) {
        $prepared = wcrq_prepare_question_data($question, false);
        if ($prepared) {
            $questions[] = $prepared;
        }
    }

    ?>
    <div class="wrap">
        <h1><?php esc_html_e('Pytania', 'wcrq'); ?></h1>
        <?php if ($message) : ?>
            <div class="<?php echo esc_attr($message_class); ?>">
                <p><?php echo esc_html($message); ?></p>
            </div>
        <?php endif; ?>

        <h2><?php esc_html_e('Dodaj nowe pytanie', 'wcrq'); ?></h2>
        <form method="post" action="<?php echo esc_url(admin_url('admin.php?page=wcrq_questions')); ?>">
            <?php wp_nonce_field('wcrq_questions_action', 'wcrq_questions_nonce'); ?>
            <input type="hidden" name="wcrq_action" value="add" />
            <table class="form-table">
                <tr>
                    <th scope="row"><label for="wcrq_question"><?php esc_html_e('Treść pytania', 'wcrq'); ?></label></th>
                    <td><textarea id="wcrq_question" name="wcrq_question" rows="3" cols="60" required></textarea></td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Odpowiedzi', 'wcrq'); ?></th>
                    <td>
                        <?php for ($i = 0; $i < 4; $i++) : ?>
                            <p>
                                <label>
                                    <input type="radio" name="wcrq_correct" value="<?php echo esc_attr($i); ?>" <?php checked(0, $i); ?> />
                                    <input type="text" name="wcrq_answer_<?php echo esc_attr($i); ?>" value="" class="regular-text" required />
                                    <span class="description"><?php esc_html_e('Zaznacz poprawną odpowiedź.', 'wcrq'); ?></span>
                                </label>
                            </p>
                        <?php endfor; ?>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="wcrq_image"><?php esc_html_e('URL obrazu (opcjonalnie)', 'wcrq'); ?></label></th>
                    <td><input type="url" id="wcrq_image" name="wcrq_image" value="" class="regular-text" /></td>
                </tr>
            </table>
            <?php submit_button(__('Dodaj pytanie', 'wcrq')); ?>
        </form>

        <h2><?php esc_html_e('Lista pytań', 'wcrq'); ?></h2>
        <?php if ($questions) : ?>
            <table class="widefat fixed striped">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Pytanie', 'wcrq'); ?></th>
                        <th><?php esc_html_e('Odpowiedzi', 'wcrq'); ?></th>
                        <th><?php esc_html_e('Poprawna', 'wcrq'); ?></th>
                        <th><?php esc_html_e('Akcje', 'wcrq'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($questions as $idx => $question) : ?>
                        <tr>
                            <td>
                                <strong><?php echo esc_html($question['question']); ?></strong>
                                <?php if (!empty($question['image'])) : ?>
                                    <p><a href="<?php echo esc_url($question['image']); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e('Podgląd obrazu', 'wcrq'); ?></a></p>
                                <?php endif; ?>
                            </td>
                            <td>
                                <ol>
                                    <?php foreach ($question['answers'] as $answer) : ?>
                                        <li><?php echo esc_html($answer); ?></li>
                                    <?php endforeach; ?>
                                </ol>
                            </td>
                            <td><?php echo esc_html($question['answers'][$question['correct']] ?? ''); ?></td>
                            <td>
                                <form method="post" action="<?php echo esc_url(admin_url('admin.php?page=wcrq_questions')); ?>" onsubmit="return confirm('<?php echo esc_js(__('Czy na pewno usunąć to pytanie?', 'wcrq')); ?>');">
                                    <?php wp_nonce_field('wcrq_questions_action', 'wcrq_questions_nonce'); ?>
                                    <input type="hidden" name="wcrq_action" value="delete" />
                                    <input type="hidden" name="wcrq_question_index" value="<?php echo esc_attr($idx); ?>" />
                                    <?php submit_button(__('Usuń', 'wcrq'), 'delete', 'submit', false); ?>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php else : ?>
            <p><?php esc_html_e('Brak pytań.', 'wcrq'); ?></p>
        <?php endif; ?>
    </div>
    <?php
}

function wcrq_settings_page_html() {
    if (!current_user_can('manage_options')) {
        return;
    }
    ?>
    <div class="wrap">
        <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
        <form action="options.php" method="post">
            <?php
            settings_fields('wcrq_settings');
            do_settings_sections('wcrq');
            submit_button();
            ?>
        </form>
    </div>
    <?php
}

function wcrq_registrations_page_html() {
    if (!current_user_can('manage_options')) {
        return;
    }
    global $wpdb;
    $table = $wpdb->prefix . 'wcrq_participants';
    $results_table = $wpdb->prefix . 'wcrq_results';

    // Delete all accounts
    if (!empty($_POST['wcrq_delete_all_nonce']) && wp_verify_nonce($_POST['wcrq_delete_all_nonce'], 'wcrq_delete_all')) {
        $pass = $_POST['wcrq_delete_all_pass'] ?? '';
        $user = wp_get_current_user();
        if (wp_check_password($pass, $user->user_pass, $user->ID)) {
            $wpdb->query("TRUNCATE TABLE $results_table");
            $wpdb->query("TRUNCATE TABLE $table");
            echo '<div class="updated"><p>' . __('Wszystkie konta zostały usunięte.', 'wcrq') . '</p></div>';
        } else {
            echo '<div class="error"><p>' . __('Błędne hasło.', 'wcrq') . '</p></div>';
        }
    }

    // Row actions
    if (!empty($_GET['wcrq_action']) && !empty($_GET['id'])) {
        $id = intval($_GET['id']);
        if (wp_verify_nonce($_GET['_wpnonce'] ?? '', 'wcrq_reg_action_' . $id)) {
            if ($_GET['wcrq_action'] === 'delete') {
                $wpdb->delete($results_table, ['participant_id' => $id]);
                $wpdb->delete($table, ['id' => $id]);
            } elseif ($_GET['wcrq_action'] === 'block') {
                $wpdb->update($table, ['blocked' => 1], ['id' => $id]);
            } elseif ($_GET['wcrq_action'] === 'unblock') {
                $wpdb->update($table, ['blocked' => 0], ['id' => $id]);
            }
        }
    }

    $rows = $wpdb->get_results("SELECT * FROM $table ORDER BY created DESC");
    echo '<div class="wrap"><h1>' . esc_html(__('Rejestracje', 'wcrq')) . '</h1>';
    echo '<form method="post" style="margin-bottom:20px;">' . wp_nonce_field('wcrq_delete_all', 'wcrq_delete_all_nonce', true, false)
        . '<input type="password" name="wcrq_delete_all_pass" placeholder="' . esc_attr__('Hasło', 'wcrq') . '" required /> '
        . '<button type="submit" class="button button-danger" onclick="return confirm(\'Usunąć wszystkie konta?\');">' . __('Usuń wszystkie konta', 'wcrq') . '</button></form>';
    if ($rows) {
        echo '<table class="wp-list-table widefat fixed striped"><thead><tr>'
            . '<th>' . __('Szkoła', 'wcrq') . '</th>'
            . '<th>' . __('Uczeń', 'wcrq') . '</th>'
            . '<th>' . __('Klasa', 'wcrq') . '</th>'
            . '<th>' . __('Email', 'wcrq') . '</th>'
            . '<th>' . __('Login', 'wcrq') . '</th>'
            . '<th>' . __('Hasło', 'wcrq') . '</th>'
            . '<th>' . __('Zarejestrowano', 'wcrq') . '</th>'
            . '<th>' . __('Akcje', 'wcrq') . '</th>'
            . '</tr></thead><tbody>';
        foreach ($rows as $r) {
            $time = mysql2date(get_option('date_format') . ' ' . get_option('time_format'), $r->created, true);
            $nonce = wp_create_nonce('wcrq_reg_action_' . $r->id);
            $actions = [];
            if ($r->blocked) {
                $actions[] = '<a href="' . esc_url(add_query_arg(['wcrq_action' => 'unblock', 'id' => $r->id, '_wpnonce' => $nonce])) . '">' . __('ODBLOKUJ', 'wcrq') . '</a>';
            } else {
                $actions[] = '<a href="' . esc_url(add_query_arg(['wcrq_action' => 'block', 'id' => $r->id, '_wpnonce' => $nonce])) . '">' . __('BLOKUJ', 'wcrq') . '</a>';
            }
            $actions[] = '<a href="' . esc_url(add_query_arg(['wcrq_action' => 'delete', 'id' => $r->id, '_wpnonce' => $nonce])) . '" onclick="return confirm(\'Usunąć?\');">' . __('USUŃ', 'wcrq') . '</a>';
            echo '<tr>'
                . '<td>' . esc_html($r->school) . '</td>'
                . '<td>' . esc_html($r->name) . '</td>'
                . '<td>' . esc_html($r->class) . '</td>'
                . '<td>' . esc_html($r->email) . '</td>'
                . '<td>' . esc_html($r->login) . '</td>'
                . '<td>' . esc_html($r->pass_plain) . '</td>'
                . '<td>' . esc_html($time) . '</td>'
                . '<td>' . implode(' | ', $actions) . '</td>'
                . '</tr>';
        }
        echo '</tbody></table>';
    } else {
        echo '<p>' . __('Brak zgłoszeń.', 'wcrq') . '</p>';
    }
    echo '</div>';
}

function wcrq_handle_results_actions() {
    if (!is_admin() || empty($_GET['page']) || $_GET['page'] !== 'wcrq_results') {
        return;
    }

    if (!current_user_can('manage_options')) {
        return;
    }

    global $wpdb;
    $results_table = $wpdb->prefix . 'wcrq_results';

    if (!empty($_GET['wcrq_result_action']) && !empty($_GET['result_id'])) {
        $result_id = intval($_GET['result_id']);
        $nonce = isset($_GET['_wpnonce']) ? $_GET['_wpnonce'] : '';
        $notice_key = 'invalid_nonce';

        if (wp_verify_nonce($nonce, 'wcrq_delete_result_' . $result_id)) {
            $deleted = $wpdb->delete($results_table, ['id' => $result_id]);
            $notice_key = $deleted ? 'deleted' : 'delete_failed';
        }

        $redirect_url = add_query_arg(
            [
                'page' => 'wcrq_results',
                'wcrq_notice' => $notice_key,
            ],
            admin_url('admin.php')
        );

        if (!headers_sent()) {
            wp_safe_redirect($redirect_url);
            exit;
        }

        $_GET['wcrq_notice'] = $notice_key;
        unset($_GET['wcrq_result_action'], $_GET['result_id'], $_GET['_wpnonce']);
    }
}
add_action('admin_init', 'wcrq_handle_results_actions');

function wcrq_results_page_html() {
    if (!current_user_can('manage_options')) {
        return;
    }

    global $wpdb;
    $results_table = $wpdb->prefix . 'wcrq_results';
    $notices = [];

    if (!empty($_POST['wcrq_clear_results_nonce']) && wp_verify_nonce($_POST['wcrq_clear_results_nonce'], 'wcrq_clear_results')) {
        $raw_confirmation = isset($_POST['wcrq_clear_results_confirm']) ? wp_unslash($_POST['wcrq_clear_results_confirm']) : '';
        if (!is_string($raw_confirmation)) {
            $raw_confirmation = '';
        }
        $confirmation = trim($raw_confirmation);
        $user = wp_get_current_user();
        $is_confirmed = false;

        if ($confirmation !== '') {
            if ($confirmation === '3' || $confirmation === '1+2=3') {
                $is_confirmed = true;
            } elseif ((int) preg_replace('/\D+/', '', $confirmation) === 3) {
                $is_confirmed = true;
            } elseif ($user && $user->exists() && wp_check_password($raw_confirmation, $user->user_pass, $user->ID)) {
                $is_confirmed = true;
            }
        }

        if ($is_confirmed) {
            $deleted = $wpdb->query("DELETE FROM $results_table");
            if ($deleted !== false) {
                $wpdb->query("ALTER TABLE $results_table AUTO_INCREMENT = 1");
                $notices[] = ['type' => 'success', 'text' => __('Wszystkie wyniki zostały usunięte.', 'wcrq')];
            } else {
                $notices[] = ['type' => 'error', 'text' => __('Nie udało się usunąć wyników.', 'wcrq')];
            }
        } else {
            $notices[] = ['type' => 'error', 'text' => __('Niepoprawne potwierdzenie. Wyniki nie zostały usunięte.', 'wcrq')];
        }
    } elseif (!empty($_POST['wcrq_clear_results_nonce'])) {
        $notices[] = ['type' => 'error', 'text' => __('Nieprawidłowy token operacji.', 'wcrq')];
    }

    $rows = wcrq_get_results_rows();

    if (!empty($_GET['wcrq_export']) && 'xlsx' === $_GET['wcrq_export']) {
        if (!empty($_GET['_wpnonce']) && wp_verify_nonce($_GET['_wpnonce'], 'wcrq_export_results')) {
            wcrq_export_results_excel($rows);
        } else {
            $notices[] = ['type' => 'error', 'text' => __('Nieprawidłowy token eksportu.', 'wcrq')];
        }
    }

    echo '<div class="wrap"><h1>' . esc_html(__('Wyniki', 'wcrq')) . '</h1>';

    if (!empty($_GET['wcrq_notice'])) {
        $notice_key = sanitize_key(wp_unslash($_GET['wcrq_notice']));
        switch ($notice_key) {
            case 'deleted':
                $notices[] = ['type' => 'success', 'text' => __('Wybrany wynik został usunięty.', 'wcrq')];
                break;
            case 'delete_failed':
                $notices[] = ['type' => 'error', 'text' => __('Nie udało się usunąć wyniku.', 'wcrq')];
                break;
            case 'invalid_nonce':
                $notices[] = ['type' => 'error', 'text' => __('Nieprawidłowy token usuwania wyniku.', 'wcrq')];
                break;
        }
    }

    foreach ($notices as $notice) {
        $class = $notice['type'] === 'success' ? 'notice notice-success' : 'notice notice-error';
        echo '<div class="' . esc_attr($class) . '"><p>' . esc_html($notice['text']) . '</p></div>';
    }

    $confirm_all_title = esc_attr__('Usuń wszystkie wyniki', 'wcrq');
    $confirm_all_message = esc_attr__('Aby usunąć wszystkie wyniki, wpisz wynik równania 1+2.', 'wcrq');
    $prompt_label = esc_attr__('Wynik równania 1+2', 'wcrq');
    $prompt_error = esc_attr__('Nieprawidłowy wynik. Spróbuj ponownie.', 'wcrq');
    echo '<form method="post" class="wcrq-results-clear" data-confirm-title="' . $confirm_all_title . '" data-confirm-message="' . $confirm_all_message . '" data-prompt-label="' . $prompt_label . '" data-prompt-error="' . $prompt_error . '">';
    wp_nonce_field('wcrq_clear_results', 'wcrq_clear_results_nonce');
    echo '<p>';
    echo '<input type="hidden" name="wcrq_clear_results_confirm" class="wcrq-results-clear__value" value="" /> ';
    echo '<span class="description wcrq-results-clear__description">' . esc_html__('Kliknij przycisk, aby potwierdzić usunięcie w dodatkowym oknie.', 'wcrq') . '</span> ';
    echo '<noscript><p class="noscript-wcrq-clear-warning">' . esc_html__('Aby usunąć wyniki, włącz obsługę JavaScript w przeglądarce.', 'wcrq') . '</p></noscript>';
    submit_button(__('Wyczyść wszystkie wyniki', 'wcrq'), 'delete', 'submit', false);
    echo '</p>';
    echo '</form>';

    if ($rows) {
        $export_url = wp_nonce_url(add_query_arg('wcrq_export', 'xlsx'), 'wcrq_export_results');
        echo '<p><a href="' . esc_url($export_url) . '" class="button button-primary">' . esc_html__('Eksportuj do Excela', 'wcrq') . '</a></p>';
        echo '<table class="widefat fixed striped"><thead><tr>'
            . '<th>' . esc_html__('Uczeń', 'wcrq') . '</th>'
            . '<th>' . esc_html__('Email', 'wcrq') . '</th>'
            . '<th>' . esc_html__('Szkoła', 'wcrq') . '</th>'
            . '<th>' . esc_html__('Klasa', 'wcrq') . '</th>'
            . '<th>' . esc_html__('Wynik', 'wcrq') . '</th>'
            . '<th>' . esc_html__('Czas', 'wcrq') . '</th>'
            . '<th>' . esc_html__('Naruszenia', 'wcrq') . '</th>'
            . '<th>' . esc_html__('Szczegóły', 'wcrq') . '</th>'
            . '<th>' . esc_html__('Akcje', 'wcrq') . '</th>'
            . '</tr></thead><tbody>';
        foreach ($rows as $r) {
            $duration_seconds = intval($r->duration_seconds);
            if ($duration_seconds <= 0) {
                $duration_seconds = max(0, wcrq_local_datetime_to_timestamp($r->end_time) - wcrq_local_datetime_to_timestamp($r->start_time));
            }
            $duration = wcrq_format_duration($duration_seconds);
            $details = wcrq_extract_result_details($r->answers);
            $start_display = mysql2date(get_option('date_format') . ' ' . get_option('time_format'), $r->start_time, true);
            $end_display = mysql2date(get_option('date_format') . ' ' . get_option('time_format'), $r->end_time, true);
            echo '<tr>'
                . '<td>' . esc_html($r->name) . '</td>'
                . '<td>' . esc_html($r->email) . '</td>'
                . '<td>' . esc_html($r->school) . '</td>'
                . '<td>' . esc_html($r->class) . '</td>'
                . '<td>' . esc_html(number_format_i18n($r->score, 2)) . '%</td>'
                . '<td>' . esc_html($duration) . '</td>'
                . '<td>' . esc_html(intval($r->violations)) . '</td>'
                . '<td>';
            if ($details) {
                echo '<details><summary>' . esc_html__('Pokaż odpowiedzi', 'wcrq') . '</summary>';
                echo '<p><strong>' . esc_html__('Czas startu:', 'wcrq') . '</strong> ' . esc_html($start_display) . '<br />';
                echo '<strong>' . esc_html__('Czas zakończenia:', 'wcrq') . '</strong> ' . esc_html($end_display) . '<br />';
                echo '<strong>' . esc_html__('Quiz ukończony:', 'wcrq') . '</strong> ' . esc_html($r->is_completed ? __('Tak', 'wcrq') : __('Nie', 'wcrq')) . '</p>';
                echo '<ol class="wcrq-result-details">';
                foreach ($details as $detail) {
                    $selected_text = ($detail['selected'] >= 0 && isset($detail['answers'][$detail['selected']])) ? $detail['answers'][$detail['selected']] : __('Brak odpowiedzi', 'wcrq');
                    $correct_text = ($detail['correct'] !== null && $detail['correct'] >= 0 && isset($detail['answers'][$detail['correct']])) ? $detail['answers'][$detail['correct']] : __('Brak danych', 'wcrq');
                    $status = '';
                    if ($detail['is_correct'] === true) {
                        $status = '<span class="wcrq-answer-status wcrq-answer-status--correct">' . esc_html__('Poprawna odpowiedź', 'wcrq') . '</span>';
                    } elseif ($detail['is_correct'] === false) {
                        $status = '<span class="wcrq-answer-status wcrq-answer-status--incorrect">' . esc_html__('Błędna odpowiedź', 'wcrq') . '</span>';
                    }
                    echo '<li>';
                    echo '<strong>' . esc_html($detail['label']) . '</strong>';
                    if (!empty($detail['question'])) {
                        echo '<div>' . esc_html($detail['question']) . '</div>';
                    }
                    if (!empty($detail['image'])) {
                        echo '<div><img src="' . esc_url($detail['image']) . '" alt="" class="wcrq-result-image" /></div>';
                    }
                    echo '<div>' . sprintf(esc_html__('Odpowiedź uczestnika: %s', 'wcrq'), esc_html($selected_text)) . '</div>';
                    echo '<div>' . sprintf(esc_html__('Prawidłowa odpowiedź: %s', 'wcrq'), esc_html($correct_text)) . '</div>';
                    if ($status) {
                        echo '<div>' . $status . '</div>';
                    }
                    echo '</li>';
                }
                echo '</ol></details>';
            } else {
                echo esc_html__('Brak danych.', 'wcrq');
            }
            $delete_url = wp_nonce_url(
                add_query_arg(
                    [
                        'page' => 'wcrq_results',
                        'wcrq_result_action' => 'delete',
                        'result_id' => intval($r->id),
                    ],
                    admin_url('admin.php')
                ),
                'wcrq_delete_result_' . intval($r->id)
            );
            echo '</td>';
            $delete_label = esc_html__('Usuń wynik', 'wcrq');
            $delete_title = esc_attr__('Usuń wynik', 'wcrq');
            $delete_message = esc_attr__('Czy na pewno usunąć ten wynik?', 'wcrq');
            echo '<td><a class="button button-secondary wcrq-result-delete" href="' . esc_url($delete_url) . '" data-confirm-title="' . $delete_title . '" data-confirm-message="' . $delete_message . '">' . $delete_label . '</a></td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
    } else {
        echo '<p>' . esc_html__('Brak wyników.', 'wcrq') . '</p>';
    }
    echo '</div>';
}

function wcrq_field_start_time() {
    $options = get_option('wcrq_settings');
    $value = isset($options['start_time']) ? esc_attr($options['start_time']) : '';
    echo '<input type="datetime-local" name="wcrq_settings[start_time]" value="' . $value . '" />';
    echo '<p class="description">' . esc_html__('Czas interpretowany jest według strefy czasowej: Europa/Warszawa.', 'wcrq') . '</p>';
}

function wcrq_field_end_time() {
    $options = get_option('wcrq_settings');
    $value = isset($options['end_time']) ? esc_attr($options['end_time']) : '';
    echo '<input type="datetime-local" name="wcrq_settings[end_time]" value="' . $value . '" />';
    echo '<p class="description">' . esc_html__('Czas interpretowany jest według strefy czasowej: Europa/Warszawa.', 'wcrq') . '</p>';
}

function wcrq_field_pre_start_text() {
    $options = get_option('wcrq_settings');
    $value = isset($options['pre_start_text']) ? $options['pre_start_text'] : '';
    echo '<textarea name="wcrq_settings[pre_start_text]" rows="5" cols="60">' . esc_textarea($value) . '</textarea>';
}

function wcrq_field_pre_quiz_text() {
    $options = get_option('wcrq_settings');
    $value = isset($options['pre_quiz_text']) ? $options['pre_quiz_text'] : '';
    echo '<textarea name="wcrq_settings[pre_quiz_text]" rows="5" cols="60">' . esc_textarea($value) . '</textarea>';
}

function wcrq_field_post_quiz_text() {
    $options = get_option('wcrq_settings');
    $value = isset($options['post_quiz_text']) ? $options['post_quiz_text'] : '';
    echo '<textarea name="wcrq_settings[post_quiz_text]" rows="5" cols="60">' . esc_textarea($value) . '</textarea>';
}

function wcrq_field_randomize_questions() {
    $options = get_option('wcrq_settings');
    $checked = !empty($options['randomize_questions']);
    echo '<label><input type="checkbox" name="wcrq_settings[randomize_questions]" value="1"' . checked(1, $checked, false) . ' /> ' . esc_html__('Włącz losową kolejność pytań.', 'wcrq') . '</label>';
}

function wcrq_field_allow_navigation() {
    $options = get_option('wcrq_settings');
    $checked = !empty($options['allow_navigation']);
    echo '<label><input type="checkbox" name="wcrq_settings[allow_navigation]" value="1"' . checked(1, $checked, false) . ' /> ' . esc_html__('Pozwól uczestnikom wracać do poprzednich pytań.', 'wcrq') . '</label>';
}

function wcrq_field_show_results() {
    $options = get_option('wcrq_settings');
    $checked = isset($options['show_results']) ? (bool)$options['show_results'] : false;
    echo '<input type="checkbox" name="wcrq_settings[show_results]" value="1"' . checked(1, $checked, false) . ' />';
}

function wcrq_field_show_violations_to_users() {
    $options = get_option('wcrq_settings');
    $checked = !empty($options['show_violations_to_users']);
    echo '<label><input type="checkbox" name="wcrq_settings[show_violations_to_users]" value="1"' . checked(1, $checked, false) . ' /> ' . esc_html__('Informuj uczestników o zarejestrowanych naruszeniach.', 'wcrq') . '</label>';
}

// Registration shortcode
function wcrq_registration_shortcode() {
    $output = '';
    wp_enqueue_script('wcrq-registration', plugins_url('assets/js/registration.js', __FILE__), [], '0.1', true);
    if (!empty($_POST['wcrq_reg_nonce']) && wp_verify_nonce($_POST['wcrq_reg_nonce'], 'wcrq_reg')) {
        $raw_school = $_POST['wcrq_school'] ?? '';
        $raw_name = $_POST['wcrq_name'] ?? '';
        $raw_class = $_POST['wcrq_class'] ?? '';
        $raw_email = $_POST['wcrq_email'] ?? '';
        $pattern = '/<[^>]*>|(SELECT|INSERT|DELETE|UPDATE|DROP|UNION|--)/i';
        if (preg_match($pattern, $raw_school . $raw_name . $raw_class . $raw_email)) {
            $output .= '<p>nie mozna psuć</p>';
        } else {
            global $wpdb;
            $table = $wpdb->prefix . 'wcrq_participants';
            $school = sanitize_text_field($raw_school);
            $name = sanitize_text_field($raw_name);
            $class = sanitize_text_field($raw_class);
            $email = sanitize_email($raw_email);
            $errors = [];
            if (mb_strlen($school) > 150) {
                $errors[] = __('Nazwa szkoły jest zbyt długa.', 'wcrq');
            }
            if (mb_strlen($name) > 100) {
                $errors[] = __('Imię i nazwisko jest zbyt długie.', 'wcrq');
            }
            if (mb_strlen($class) > 50) {
                $errors[] = __('Klasa jest zbyt długa.', 'wcrq');
            }
            if (mb_strlen($email) > 100) {
                $errors[] = __('Email jest zbyt długi.', 'wcrq');
            }
            if ($school && $name && $class && $email && empty($errors)) {
                $email_exists = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table WHERE email = %s", $email));
                if ($email_exists) {
                    $output .= '<p>' . __('Ten e-mail został już wykorzystany do rejestracji.', 'wcrq') . '</p>';
                } else {
                    // Generate unique login and password
                    do {
                        $login = strtolower(wp_generate_password(8, false));
                        $exists = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table WHERE login = %s", $login));
                    } while ($exists);
                    $password = wp_generate_password(8, false);
                    $inserted = $wpdb->insert($table, [
                        'school' => $school,
                        'name' => $name,
                        'class' => $class,
                        'email' => $email,
                        'login' => $login,
                        'password' => wp_hash_password($password),
                        'pass_plain' => $password,
                        'blocked' => 0
                    ]);

                    if ($inserted === false) {
                        $output .= '<p>' . __('Wystąpił błąd podczas rejestracji. Spróbuj ponownie później.', 'wcrq') . '</p>';
                        if (!empty($wpdb->last_error)) {
                            $output .= '<p><small>' . esc_html($wpdb->last_error) . '</small></p>';
                        }
                    } else {
                        $subject = __('Dane logowania do quizu', 'wcrq');
                        $body = sprintf(
                            __('Szanowny Użytkowniku,<br><br>z radością informujemy, że Twoje konto w WCR Quiz zostało pomyślnie utworzone.<br><br><strong>Dane logowania:</strong><br>• Login: %1$s<br>• Hasło: %2$s <br><br>W razie pytań lub problemów nasi konsultanci chętnie pomogą.<br><br>Z wyrazami szacunku,<br>Zespół WCR Quiz', 'wcrq'),
                            esc_html($login),
                            esc_html($password)
                        );
                        $headers = ['Content-Type: text/html; charset=UTF-8'];
                        wp_mail($email, $subject, $body, $headers);

                        $output .= '<p>' . __('Rejestracja zakończona sukcesem. Sprawdź e-mail.', 'wcrq') . '</p>';
                    }
                }
            } else {
                if ($errors) {
                    foreach ($errors as $err) {
                        $output .= '<p>' . esc_html($err) . '</p>';
                    }
                } else {
                    $output .= '<p>' . __('Wszystkie pola są wymagane.', 'wcrq') . '</p>';
                }
            }
        }
    }
    $output .= '<form method="post" class="wcrq-registration">'
        . wp_nonce_field('wcrq_reg', 'wcrq_reg_nonce', true, false)
        . '<p><label>' . __('Nazwa szkoły', 'wcrq') . '<br /><input type="text" name="wcrq_school" required maxlength="150"></label></p>'
        . '<p><label>' . __('Imię i nazwisko', 'wcrq') . '<br /><input type="text" name="wcrq_name" required maxlength="100"></label></p>'
        . '<p><label>' . __('Klasa', 'wcrq') . '<br /><input type="text" name="wcrq_class" required maxlength="50"></label></p>'
        . '<p><label>Email<br /><input type="email" name="wcrq_email" required maxlength="100"></label></p>'
        . '<p class="wcrq-info">' . __('Jeden adres mailowy może być wykorzystany tylko 1 raz. Po rejestracji otrzymasz dane identyfikacyjne do udziału w quizie.', 'wcrq') . '</p>'
        . '<p><button type="submit">' . __('Zarejestruj się', 'wcrq') . '</button></p>'
        . '</form>';
    return $output;
}
add_shortcode('wcr_registration', 'wcrq_registration_shortcode');

// Quiz shortcode
function wcrq_quiz_shortcode() {
    $options = get_option('wcrq_settings');
    $start_time = !empty($options['start_time']) ? wcrq_parse_datetime_local($options['start_time']) : 0;
    $end_time = !empty($options['end_time']) ? wcrq_parse_datetime_local($options['end_time']) : 0;
    $now = wcrq_current_timestamp();

    if ($start_time && $now < $start_time) {
        wp_enqueue_script('wcrq-countdown', plugins_url('assets/js/countdown.js', __FILE__), [], '0.1', true);
        $text = !empty($options['pre_start_text']) ? wpautop(wp_kses_post($options['pre_start_text'])) : '<p>' . esc_html__('Quiz jeszcze się nie rozpoczął.', 'wcrq') . '</p>';
        $remaining = max(0, $start_time - $now);
        $countdown = '<div class="wcrq-countdown" data-countdown-seconds="' . esc_attr($remaining) . '">' . esc_html__('Do rozpoczęcia pozostało:', 'wcrq') . ' <span class="wcrq-countdown-time">00:00:00</span></div>';
        $timezone_note = '<p class="wcrq-timezone-note">' . esc_html__('Czas liczony jest według strefy czasowej: Europa/Warszawa.', 'wcrq') . '</p>';
        return '<div class="wcrq-pre-quiz wcrq-pre-countdown">' . $text . $countdown . $timezone_note . '</div>';
    }

    if ($end_time && $now > $end_time) {
        $text = !empty($options['post_quiz_text']) ? wpautop(wp_kses_post($options['post_quiz_text'])) : '<p>' . esc_html__('Czas na quiz się skończył.', 'wcrq') . '</p>';
        return '<div class="wcrq-pre-quiz">' . $text . '</div>';
    }

    $remaining = $end_time ? max(0, $end_time - $now) : 0;

    // Check login
    $participant = wcrq_require_active_participant();
    if (is_wp_error($participant)) {
        return wcrq_login_form($participant->get_error_message());
    }
    global $wpdb;
    if (!empty($participant->blocked)) {
        wcrq_clear_participant_session(intval($participant->id));
        return '<p>' . __('Twoje konto zostało zablokowane.', 'wcrq') . '</p>';
    }

    $active_result = wcrq_sync_active_result_session($participant->id);
    if ($end_time && $active_result && $now > $end_time) {
        wcrq_finalize_result_row($active_result, $end_time);
        wcrq_reset_quiz_progress();
        $active_result = null;
    }

    if (!$active_result) {
        $completed_result = wcrq_get_latest_completed_result_row($participant->id);
        if ($completed_result) {
            wcrq_clear_participant_session(intval($participant->id));
            $message = '<div class="wcrq-quiz-completed">';
            $message .= '<p>' . esc_html__('Quiz został już przez Ciebie ukończony.', 'wcrq') . '</p>';
            $message .= wcrq_build_completion_message(floatval($completed_result->score));
            $message .= '</div>';
            return $message;
        }
    }

    $saved_responses = [];
    if (!empty($_SESSION['wcrq_saved_responses']) && is_array($_SESSION['wcrq_saved_responses'])) {
        $saved_responses = array_map('intval', $_SESSION['wcrq_saved_responses']);
    }

    $allow_navigation = !empty($options['allow_navigation']);
    if (!empty($_SESSION['wcrq_started'])) {
        $allow_navigation = false;
    }
    $show_violations_to_users = !empty($options['show_violations_to_users']);

    // Check if quiz started
    if (empty($_SESSION['wcrq_started'])) {
        if (!empty($_POST['wcrq_start'])) {
            $_SESSION['wcrq_started'] = wcrq_current_timestamp();
        } else {
            $pre_quiz = !empty($options['pre_quiz_text']) ? wpautop(wp_kses_post($options['pre_quiz_text'])) : '';
            $sections = '';
            $sections .= '<div class="wcrq-pre-quiz-section">';
            $sections .= '<p class="wcrq-pre-quiz-welcome">' . sprintf(esc_html__('Witaj %s!', 'wcrq'), esc_html($participant->name)) . '</p>';
            if ($pre_quiz) {
                $sections .= '<div class="wcrq-pre-quiz-text">' . $pre_quiz . '</div>';
            }
            $rules = [];
            if ($show_violations_to_users) {
                $rules[] = esc_html__('Opuszczanie quizu w trakcie jego trwania jest zabronione. Każde naruszenie zostanie zapisane, a wyjście ze strony quizu zostanie odnotowane jako naruszenie.', 'wcrq');
            }
            if (!$allow_navigation) {
                $rules[] = esc_html__('Nie można wracać do poprzednich pytań podczas rozwiązywania quizu.', 'wcrq');
            }
            if ($rules) {
                $sections .= '<div class="wcrq-pre-quiz-rules-block">';
                $sections .= '<h3 class="wcrq-pre-quiz-rules-title">' . esc_html__('Zasady podczas quizu:', 'wcrq') . '</h3>';
                $sections .= '<ul class="wcrq-pre-quiz-rules">';
                foreach ($rules as $rule) {
                    $sections .= '<li>' . $rule . '</li>';
                }
                $sections .= '</ul>';
                $sections .= '</div>';
            }
            $sections .= '</div>';

            return '<div class="wcrq-pre-quiz">' . $sections . '<form method="post" class="wcrq-start"><p><button type="submit" name="wcrq_start" value="1">' . __('Rozpocznij quiz', 'wcrq') . '</button></p></form></div>';
        }
    }

    // Handle submission
    if (!empty($_POST['wcrq_quiz_nonce']) && wp_verify_nonce($_POST['wcrq_quiz_nonce'], 'wcrq_quiz')) {
        return wcrq_handle_quiz_submit();
    }

    // Display quiz
    if (!empty($_SESSION['wcrq_questions']) && is_array($_SESSION['wcrq_questions'])) {
        $questions = $_SESSION['wcrq_questions'];
    } elseif ($active_result) {
        $answers = maybe_unserialize($active_result->answers);
        if (is_array($answers) && !empty($answers['questions'])) {
            $questions = wcrq_prepare_questions_from_snapshot($answers['questions']);
            $_SESSION['wcrq_questions'] = $questions;
        } else {
            $questions = wcrq_get_saved_questions();
        }
    } else {
        $questions = wcrq_get_saved_questions();
    }

    if (empty($questions)) {
        return '<p>' . __('Brak skonfigurowanych pytań.', 'wcrq') . '</p>';
    }

    if (empty($_SESSION['wcrq_result_id'])) {
        if (empty($active_result) && !empty($options['randomize_questions'])) {
            shuffle($questions);
            foreach ($questions as &$q) {
                if (isset($q['answers']) && is_array($q['answers'])) {
                    $answers = [];
                    foreach ($q['answers'] as $idx => $answer) {
                        $answers[] = [
                            'text' => $answer,
                            'original_index' => $idx,
                        ];
                    }
                    shuffle($answers);
                    $q['answers'] = array_map(function ($item) {
                        return $item['text'];
                    }, $answers);
                    foreach ($answers as $new_index => $answer_data) {
                        if (intval($q['correct']) === intval($answer_data['original_index'])) {
                            $q['correct'] = $new_index;
                            break;
                        }
                    }
                }
            }
            unset($q);
        }
        $_SESSION['wcrq_questions'] = $questions;
        $_SESSION['wcrq_saved_responses'] = [];
        $_SESSION['wcrq_current_question'] = 0;
        $saved_responses = [];
    }

    $question_count = count($questions);
    $current_question = isset($_SESSION['wcrq_current_question']) ? intval($_SESSION['wcrq_current_question']) : null;
    if ($question_count > 0) {
        if ($current_question === null) {
            $current_question = wcrq_guess_current_question_index($questions, $saved_responses);
        }
        $current_question = max(0, min($current_question, $question_count - 1));
        $_SESSION['wcrq_current_question'] = $current_question;
    } else {
        $current_question = 0;
        $_SESSION['wcrq_current_question'] = 0;
    }

    $result_id = wcrq_ensure_result_record($questions);

    $start_timestamp = isset($_SESSION['wcrq_started']) ? intval($_SESSION['wcrq_started']) : wcrq_current_timestamp();
    $start_display = wcrq_format_polish_datetime($start_timestamp);
    if ($start_display === '') {
        $start_display = __('Brak danych', 'wcrq');
    }
    $end_timestamp = $end_time ? intval($end_time) : 0;

    wp_enqueue_script('wcrq-quiz', plugins_url('assets/js/quiz.js', __FILE__), [], '0.6', true);
    wp_localize_script('wcrq-quiz', 'wcrqQuizData', [
        'ajaxUrl' => admin_url('admin-ajax.php'),
        'saveNonce' => wp_create_nonce('wcrq_save_answer'),
        'progressNonce' => wp_create_nonce('wcrq_save_progress'),
        'violationNonce' => wp_create_nonce('wcrq_log_violation'),
        'sessionNonce' => wp_create_nonce('wcrq_check_session'),
        'resultId' => $result_id,
        'violationMessage' => esc_html__('Opuszczanie strony jest zabronione. Każde naruszenie zostaje zapisane w wynikach.', 'wcrq'),
        'showViolationMessage' => $show_violations_to_users ? 1 : 0,
        'trackViolations' => 1,
        'needAnswerMessage' => esc_html__('Zaznacz odpowiedź, zanim przejdziesz do kolejnego pytania.', 'wcrq'),
        'sessionCheckInterval' => 15,
    ]);

    $out = '<form method="post" class="wcrq-quiz wcrq-no-js" data-duration="' . intval($remaining) . '" data-allow-navigation="' . ($allow_navigation ? '1' : '0') . '" data-start-timestamp="' . intval($start_timestamp) . '" data-end-timestamp="' . intval($end_timestamp) . '" data-server-now="' . intval($now) . '" data-current-question="' . intval($current_question) . '">';
    $out .= wp_nonce_field('wcrq_quiz', 'wcrq_quiz_nonce', true, false);
    if ($show_violations_to_users) {
        $out .= '<div class="wcrq-quiz-warning" aria-live="polite" hidden></div>';
    }

    $out .= '<div class="wcrq-timer-panel">';
    $out .= '<div class="wcrq-timer-row"><span class="wcrq-timer-label">' . esc_html__('Rozpoczęto:', 'wcrq') . '</span><span class="wcrq-timer-value wcrq-timer-start">' . esc_html($start_display) . '</span></div>';
    $remaining_text = $end_timestamp ? '00:00:00' : esc_html__('Bez limitu', 'wcrq');
    $out .= '<div class="wcrq-timer-row"><span class="wcrq-timer-label">' . esc_html__('Pozostały czas:', 'wcrq') . '</span><span class="wcrq-timer-value wcrq-timer-remaining">' . esc_html($remaining_text) . '</span></div>';
    $out .= '<div class="wcrq-timer-row"><span class="wcrq-timer-label">' . esc_html__('Czas, który upłynął:', 'wcrq') . '</span><span class="wcrq-timer-value wcrq-timer-elapsed">00:00:00</span></div>';
    $out .= '</div>';

    $out .= '<div class="wcrq-question-tabs" role="tablist">';
    foreach ($questions as $idx => $q) {
        $label = sprintf(__('Pytanie %d', 'wcrq'), $idx + 1);
        $out .= '<button type="button" class="wcrq-question-tab" data-index="' . intval($idx) . '" role="tab" aria-label="' . esc_attr($label) . '"><span aria-hidden="true">' . intval($idx + 1) . '</span><span class="screen-reader-text">' . esc_html($label) . '</span></button>';
    }
    $out .= '</div>';

    $required_message = esc_attr__('Zaznacz odpowiedź, zanim przejdziesz do kolejnego pytania.', 'wcrq');

    foreach ($questions as $idx => $q) {
        $selected_answer = isset($saved_responses[$idx]) ? intval($saved_responses[$idx]) : -1;
        $question_classes = 'wcrq-question';
        $data_attrs = ' data-index="' . intval($idx) . '" role="tabpanel" data-required-message="' . $required_message . '"';
        $out .= '<div class="' . $question_classes . '"' . $data_attrs . '>';
        $out .= '<p class="wcrq-question-title">' . esc_html($q['question']) . '</p>';
        if (!empty($q['image'])) {
            $out .= '<p><img src="' . esc_url($q['image']) . '" alt="" class="wcrq-question-image" /></p>';
        }
        foreach ($q['answers'] as $a_idx => $answer) {
            $name = 'q' . $idx;
            $checked = ($selected_answer >= 0 && $selected_answer === intval($a_idx)) ? ' checked' : '';
            $out .= '<label class="wcrq-answer"><input type="radio" name="' . esc_attr($name) . '" value="' . intval($a_idx) . '"' . $checked . '> ' . esc_html($answer) . '</label>';
        }
        $out .= '<p class="wcrq-question-error-message" aria-live="polite" hidden></p>';
        $out .= '</div>';
    }

    $out .= '<div class="wcrq-question-nav">';
    $out .= '<button type="button" class="wcrq-prev">' . esc_html__('Poprzednie pytanie', 'wcrq') . '</button>';
    $out .= '<button type="button" class="wcrq-next">' . esc_html__('Następne pytanie', 'wcrq') . '</button>';
    $out .= '<button type="submit" class="wcrq-submit">' . esc_html__('Zakończ', 'wcrq') . '</button>';
    $out .= '</div>';

    $out .= '</form>';
    return $out;
}
add_shortcode('wcr_quiz', 'wcrq_quiz_shortcode');

function wcrq_set_login_message($message) {
    if (!session_id()) {
        return;
    }

    if ($message) {
        $_SESSION['wcrq_login_message'] = (string) $message;
    } else {
        unset($_SESSION['wcrq_login_message']);
    }
}

function wcrq_take_login_message() {
    if (!session_id() || empty($_SESSION['wcrq_login_message'])) {
        return '';
    }

    $message = (string) $_SESSION['wcrq_login_message'];
    unset($_SESSION['wcrq_login_message']);

    return $message;
}

function wcrq_login_form($message = '') {
    $stored_message = wcrq_take_login_message();
    if ($stored_message !== '') {
        $message = $stored_message;
    }

    $out = '<form method="post" class="wcrq-login" autocomplete="off">';
    if ($message) {
        $out .= '<p class="wcrq-login-message" role="alert">' . esc_html($message) . '</p>';
    }
    $out .= '<p><label>Login<br /><input type="text" name="wcrq_login" required autocomplete="off" autocapitalize="none" autocorrect="off"></label></p>'
        . '<p><label>' . __('Hasło', 'wcrq') . '<br /><input type="password" name="wcrq_pass" required autocomplete="off"></label></p>'
        . '<p><button type="submit" name="wcrq_do_login" value="1">' . __('Wejdź', 'wcrq') . '</button></p>'
        . '</form>';
    return $out;
}

function wcrq_quiz_shortcode_process_login() {
    if (!empty($_POST['wcrq_do_login'])) {
        global $wpdb;
        $table = $wpdb->prefix . 'wcrq_participants';
        $login = sanitize_user($_POST['wcrq_login'] ?? '');
        $pass = $_POST['wcrq_pass'] ?? '';
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE login = %s", $login));
        if ($row && wp_check_password($pass, $row->password)) {
            if ($row->blocked) {
                wcrq_set_login_message(__('Twoje konto zostało zablokowane.', 'wcrq'));
                return false;
            }
            if (session_status() === PHP_SESSION_ACTIVE) {
                session_regenerate_id(true);
            }
            wcrq_reset_quiz_progress();
            unset($_SESSION['wcrq_participant'], $_SESSION['wcrq_session_token']);
            $_SESSION['wcrq_participant'] = intval($row->id);
            $token = wcrq_generate_session_token();
            $_SESSION['wcrq_session_token'] = $token;
            wcrq_store_session_token(intval($row->id), $token);
            wcrq_set_login_message('');
            return true;
        } else {
            wcrq_set_login_message(__('Nieprawidłowy login lub hasło.', 'wcrq'));
            return false;
        }
    }
    return null;
}
add_action('template_redirect', 'wcrq_quiz_shortcode_process_login');

function wcrq_build_completion_message($score) {
    $options = get_option('wcrq_settings');
    $message = '';

    if (!empty($options['show_results'])) {
        $message .= '<p>' . sprintf(__('Twój wynik: %s%%', 'wcrq'), number_format_i18n($score, 2)) . '</p>';
    }
    if (!empty($options['post_quiz_text'])) {
        $message .= wpautop(wp_kses_post($options['post_quiz_text']));
    }
    if (!$message) {
        $message = '<p>' . __('Twoje odpowiedzi zostały zapisane.', 'wcrq') . '</p>';
    }

    $redirect_url = function_exists('get_permalink') ? get_permalink() : '';
    if (!$redirect_url) {
        $redirect_url = home_url('/');
    }

    $message .= '<p class="wcrq-completion-actions"><a class="wcrq-completion-close-button" href="' . esc_url($redirect_url) . '">' . esc_html__('Zamknij', 'wcrq') . '</a></p>';

    return $message;
}

function wcrq_handle_quiz_submit() {
    $participant = wcrq_require_active_participant();
    if (is_wp_error($participant)) {
        return '<p>' . esc_html($participant->get_error_message()) . '</p>';
    }
    if (empty($_SESSION['wcrq_questions'])) {
        return '<p>' . __('Sesja wygasła.', 'wcrq') . '</p>';
    }
    $options = get_option('wcrq_settings');
    $end = !empty($options['end_time']) ? wcrq_parse_datetime_local($options['end_time']) : 0;
    if ($end && wcrq_current_timestamp() > $end) {
        $text = !empty($options['post_quiz_text']) ? wpautop(wp_kses_post($options['post_quiz_text'])) : '<p>' . esc_html__('Czas na quiz się skończył.', 'wcrq') . '</p>';
        return $text;
    }

    $questions = $_SESSION['wcrq_questions'];
    $participant_id = intval($participant->id);
    $result_id = isset($_SESSION['wcrq_result_id']) ? intval($_SESSION['wcrq_result_id']) : 0;

    global $wpdb;
    $table = $wpdb->prefix . 'wcrq_results';
    if ($result_id <= 0) {
        $result_id = wcrq_ensure_result_record($questions);
    }

    if ($result_id <= 0) {
        return '<p>' . __('Sesja wygasła.', 'wcrq') . '</p>';
    }

    $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id = %d AND participant_id = %d", $result_id, $participant_id));
    if (!$row) {
        return '<p>' . __('Sesja wygasła.', 'wcrq') . '</p>';
    }

    $stored_answers = maybe_unserialize($row->answers);
    if (!is_array($stored_answers)) {
        $stored_answers = [];
    }
    if (empty($stored_answers['questions'])) {
        $stored_answers['questions'] = wcrq_prepare_questions_snapshot($questions);
    }

    $responses = isset($stored_answers['responses']) && is_array($stored_answers['responses']) ? $stored_answers['responses'] : [];
    $correct = 0;
    foreach ($questions as $idx => $q) {
        $existing = isset($responses[$idx]) ? intval($responses[$idx]) : -1;
        $given = isset($_POST['q' . $idx]) ? intval($_POST['q' . $idx]) : -1;
        if ($existing >= 0) {
            $given = $existing;
        }
        if ($given < 0 && isset($_SESSION['wcrq_saved_responses']) && is_array($_SESSION['wcrq_saved_responses']) && isset($_SESSION['wcrq_saved_responses'][$idx])) {
            $given = intval($_SESSION['wcrq_saved_responses'][$idx]);
        }
        $responses[$idx] = $given;
        if ($given === intval($q['correct'])) {
            $correct++;
        }
    }

    $stored_answers['responses'] = $responses;
    $question_count = count($questions);
    $score = $question_count ? round($correct / $question_count * 100, 2) : 0;

    $now_timestamp = wcrq_current_timestamp();
    $end_mysql = wp_date('Y-m-d H:i:s', $now_timestamp, wcrq_get_polish_timezone());
    $start_timestamp = isset($_SESSION['wcrq_started']) ? intval($_SESSION['wcrq_started']) : wcrq_local_datetime_to_timestamp($row->start_time);
    $duration = max(0, $now_timestamp - intval($start_timestamp));

    $wpdb->update($table, [
        'score' => $score,
        'end_time' => $end_mysql,
        'duration_seconds' => $duration,
        'is_completed' => 1,
        'answers' => maybe_serialize($stored_answers),
    ], [
        'id' => $result_id,
    ]);

    wcrq_clear_participant_session($participant_id);

    return wcrq_build_completion_message($score);
}

function wcrq_ajax_check_session() {
    check_ajax_referer('wcrq_check_session', 'nonce');

    $participant = wcrq_require_active_participant();
    if (is_wp_error($participant)) {
        $code = $participant->get_error_code() === 'session_conflict' ? 409 : 403;
        wp_send_json_error(['message' => $participant->get_error_message()], $code);
    }

    $payload = [
        'active' => true,
    ];

    if (!empty($_SESSION['wcrq_result_id'])) {
        $payload['resultId'] = intval($_SESSION['wcrq_result_id']);
    }

    wp_send_json_success($payload);
}
add_action('wp_ajax_wcrq_check_session', 'wcrq_ajax_check_session');
add_action('wp_ajax_nopriv_wcrq_check_session', 'wcrq_ajax_check_session');

function wcrq_ajax_save_answer() {
    check_ajax_referer('wcrq_save_answer', 'nonce');

    $participant = wcrq_require_active_participant();
    if (is_wp_error($participant)) {
        $code = $participant->get_error_code() === 'session_conflict' ? 409 : 403;
        wp_send_json_error(['message' => $participant->get_error_message()], $code);
    }

    $question = isset($_POST['question']) ? intval($_POST['question']) : -1;
    $answer = isset($_POST['answer']) ? intval($_POST['answer']) : -1;
    $result_id = isset($_POST['resultId']) ? intval($_POST['resultId']) : (isset($_SESSION['wcrq_result_id']) ? intval($_SESSION['wcrq_result_id']) : 0);

    if ($question < 0 || $result_id <= 0) {
        wp_send_json_error(['message' => __('Nieprawidłowe dane.', 'wcrq')], 400);
    }

    global $wpdb;
    $table = $wpdb->prefix . 'wcrq_results';
    $participant_id = intval($participant->id);
    $row = $wpdb->get_row($wpdb->prepare("SELECT answers, start_time, is_completed FROM $table WHERE id = %d AND participant_id = %d", $result_id, $participant_id));

    if (!$row) {
        wp_send_json_error(['message' => __('Wynik nie został odnaleziony.', 'wcrq')], 404);
    }

    if (!empty($row->is_completed)) {
        wp_send_json_error(['message' => __('Quiz został już zakończony.', 'wcrq')], 409);
    }

    $answers = maybe_unserialize($row->answers);
    if (!is_array($answers)) {
        $answers = [];
    }
    if (empty($answers['responses']) || !is_array($answers['responses'])) {
        $answers['responses'] = [];
    }
    if (empty($answers['questions']) || !is_array($answers['questions']) || !isset($answers['questions'][$question])) {
        wp_send_json_error(['message' => __('Nieprawidłowe pytanie.', 'wcrq')], 400);
    }

    if ($answer < 0) {
        wp_send_json_error(['message' => __('Nieprawidłowa odpowiedź.', 'wcrq')], 400);
    }

    $question_data = $answers['questions'][$question];
    $possible_answers = isset($question_data['answers']) && is_array($question_data['answers']) ? $question_data['answers'] : [];
    if (!array_key_exists($answer, $possible_answers)) {
        wp_send_json_error(['message' => __('Nieprawidłowa odpowiedź.', 'wcrq')], 400);
    }

    $answers['responses'][$question] = $answer;
    if (!isset($answers['current'])) {
        $answers['current'] = isset($_SESSION['wcrq_current_question']) ? intval($_SESSION['wcrq_current_question']) : $question;
    }

    $now_timestamp = wcrq_current_timestamp();
    $duration = max(0, $now_timestamp - wcrq_local_datetime_to_timestamp($row->start_time));

    $wpdb->update($table, [
        'answers' => maybe_serialize($answers),
        'end_time' => wp_date('Y-m-d H:i:s', $now_timestamp, wcrq_get_polish_timezone()),
        'duration_seconds' => $duration,
    ], [
        'id' => $result_id,
    ]);

    if (!isset($_SESSION['wcrq_saved_responses']) || !is_array($_SESSION['wcrq_saved_responses'])) {
        $_SESSION['wcrq_saved_responses'] = [];
    }
    $_SESSION['wcrq_saved_responses'][$question] = $answer;

    wp_send_json_success(['answer' => $answer]);
}
add_action('wp_ajax_wcrq_save_answer', 'wcrq_ajax_save_answer');
add_action('wp_ajax_nopriv_wcrq_save_answer', 'wcrq_ajax_save_answer');

function wcrq_ajax_save_progress() {
    check_ajax_referer('wcrq_save_progress', 'nonce');

    $participant = wcrq_require_active_participant();
    if (is_wp_error($participant)) {
        $code = $participant->get_error_code() === 'session_conflict' ? 409 : 403;
        wp_send_json_error(['message' => $participant->get_error_message()], $code);
    }

    $current = isset($_POST['current']) ? intval($_POST['current']) : -1;
    $result_id = isset($_POST['resultId']) ? intval($_POST['resultId']) : (isset($_SESSION['wcrq_result_id']) ? intval($_SESSION['wcrq_result_id']) : 0);

    if ($current < 0) {
        $current = 0;
    }

    if ($result_id <= 0) {
        wp_send_json_error(['message' => __('Nieprawidłowe dane.', 'wcrq')], 400);
    }

    global $wpdb;
    $table = $wpdb->prefix . 'wcrq_results';
    $participant_id = intval($participant->id);
    $row = $wpdb->get_row($wpdb->prepare("SELECT answers, start_time, is_completed FROM $table WHERE id = %d AND participant_id = %d", $result_id, $participant_id));

    if (!$row) {
        wp_send_json_error(['message' => __('Wynik nie został odnaleziony.', 'wcrq')], 404);
    }

    if (!empty($row->is_completed)) {
        wp_send_json_error(['message' => __('Quiz został już zakończony.', 'wcrq')], 409);
    }

    $answers = maybe_unserialize($row->answers);
    if (!is_array($answers)) {
        $answers = [];
    }

    $question_count = 0;
    if (!empty($answers['questions']) && is_array($answers['questions'])) {
        $question_count = count($answers['questions']);
    } elseif (!empty($_SESSION['wcrq_questions']) && is_array($_SESSION['wcrq_questions'])) {
        $question_count = count($_SESSION['wcrq_questions']);
    }

    if ($question_count > 0) {
        $current = max(0, min($current, $question_count - 1));
    } else {
        $current = 0;
    }

    $answers['current'] = $current;

    $now_timestamp = wcrq_current_timestamp();
    $duration = max(0, $now_timestamp - wcrq_local_datetime_to_timestamp($row->start_time));

    $wpdb->update($table, [
        'answers' => maybe_serialize($answers),
        'end_time' => wp_date('Y-m-d H:i:s', $now_timestamp, wcrq_get_polish_timezone()),
        'duration_seconds' => $duration,
    ], [
        'id' => $result_id,
    ]);

    $_SESSION['wcrq_current_question'] = $current;

    wp_send_json_success(['current' => $current]);
}
add_action('wp_ajax_wcrq_save_progress', 'wcrq_ajax_save_progress');
add_action('wp_ajax_nopriv_wcrq_save_progress', 'wcrq_ajax_save_progress');

function wcrq_ajax_log_violation() {
    check_ajax_referer('wcrq_log_violation', 'nonce');

    $participant = wcrq_require_active_participant();
    if (is_wp_error($participant)) {
        $code = $participant->get_error_code() === 'session_conflict' ? 409 : 403;
        wp_send_json_error(['message' => $participant->get_error_message()], $code);
    }

    if (empty($_SESSION['wcrq_result_id'])) {
        wp_send_json_error(['message' => __('Brak aktywnej sesji.', 'wcrq')], 403);
    }

    $result_id = intval($_SESSION['wcrq_result_id']);
    global $wpdb;
    $table = $wpdb->prefix . 'wcrq_results';
    $participant_id = intval($participant->id);
    $row = $wpdb->get_row($wpdb->prepare("SELECT violations FROM $table WHERE id = %d AND participant_id = %d", $result_id, $participant_id));
    if (!$row) {
        wp_send_json_error(['message' => __('Wynik nie został odnaleziony.', 'wcrq')], 404);
    }

    $count = intval($row->violations) + 1;
    $wpdb->update($table, ['violations' => $count], ['id' => $result_id]);

    wp_send_json_success(['count' => $count]);
}
add_action('wp_ajax_wcrq_log_violation', 'wcrq_ajax_log_violation');
add_action('wp_ajax_nopriv_wcrq_log_violation', 'wcrq_ajax_log_violation');

function wcrq_get_results_rows() {
    global $wpdb;
    $pt = $wpdb->prefix . 'wcrq_participants';
    $rt = $wpdb->prefix . 'wcrq_results';

    return $wpdb->get_results("SELECT r.*, p.name, p.email, p.class, p.school FROM $rt r JOIN $pt p ON r.participant_id = p.id ORDER BY r.score DESC, r.duration_seconds ASC, r.end_time DESC");
}

function wcrq_export_results_csv($rows) {
    $filename = 'wcr-quiz-wyniki-' . wp_date('Y-m-d-His') . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=' . $filename);
    $output = fopen('php://output', 'w');
    fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF));
    fputcsv($output, [
        __('Imię i nazwisko', 'wcrq'),
        __('Email', 'wcrq'),
        __('Szkoła', 'wcrq'),
        __('Klasa', 'wcrq'),
        __('Wynik (%)', 'wcrq'),
        __('Czas (HH:MM:SS)', 'wcrq'),
        __('Naruszenia', 'wcrq'),
        __('Ukończono', 'wcrq'),
        __('Start', 'wcrq'),
        __('Koniec', 'wcrq'),
        __('Szczegóły odpowiedzi', 'wcrq'),
    ], ';');

    foreach ($rows as $row) {
        $duration_seconds = intval($row->duration_seconds);
        if ($duration_seconds <= 0) {
            $duration_seconds = max(0, wcrq_local_datetime_to_timestamp($row->end_time) - wcrq_local_datetime_to_timestamp($row->start_time));
        }
        $details = wcrq_extract_result_details($row->answers);
        $detail_strings = [];
        foreach ($details as $detail) {
            $selected_text = ($detail['selected'] >= 0 && isset($detail['answers'][$detail['selected']])) ? $detail['answers'][$detail['selected']] : __('Brak odpowiedzi', 'wcrq');
            $correct_text = ($detail['correct'] !== null && $detail['correct'] >= 0 && isset($detail['answers'][$detail['correct']])) ? $detail['answers'][$detail['correct']] : __('Brak danych', 'wcrq');
            $detail_strings[] = $detail['label'] . ': ' . $selected_text . ' / ' . sprintf(__('Poprawna: %s', 'wcrq'), $correct_text);
        }

        fputcsv($output, [
            $row->name,
            $row->email,
            $row->school,
            $row->class,
            number_format_i18n($row->score, 2),
            wcrq_format_duration($duration_seconds),
            intval($row->violations),
            $row->is_completed ? __('Tak', 'wcrq') : __('Nie', 'wcrq'),
            $row->start_time,
            $row->end_time,
            implode(' | ', $detail_strings),
        ], ';');
    }

    fclose($output);
    exit;
}

function wcrq_export_results_excel($rows) {
    if (!class_exists('PhpOffice\\PhpSpreadsheet\\Spreadsheet')) {
        $autoload = plugin_dir_path(__FILE__) . 'vendor/autoload.php';
        if (file_exists($autoload)) {
            require_once $autoload;
        }
    }

    if (!class_exists('PhpOffice\\PhpSpreadsheet\\Spreadsheet')) {
        wp_die(__('Biblioteka do eksportu wyników nie jest dostępna.', 'wcrq'));
    }

    $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle(__('Wyniki', 'wcrq'));

    $headers = [
        __('Imię i nazwisko', 'wcrq'),
        __('Email', 'wcrq'),
        __('Szkoła', 'wcrq'),
        __('Klasa', 'wcrq'),
        __('Wynik (%)', 'wcrq'),
        __('Czas (HH:MM:SS)', 'wcrq'),
        __('Naruszenia', 'wcrq'),
        __('Ukończono', 'wcrq'),
        __('Start', 'wcrq'),
        __('Koniec', 'wcrq'),
        __('Szczegóły odpowiedzi', 'wcrq'),
    ];

    foreach ($headers as $index => $label) {
        $sheet->setCellValueByColumnAndRow($index + 1, 1, $label);
    }

    $rowNumber = 2;
    foreach ($rows as $row) {
        $duration_seconds = intval($row->duration_seconds);
        if ($duration_seconds <= 0) {
            $duration_seconds = max(0, wcrq_local_datetime_to_timestamp($row->end_time) - wcrq_local_datetime_to_timestamp($row->start_time));
        }

        $details = wcrq_extract_result_details($row->answers);
        $detail_strings = [];
        foreach ($details as $detail) {
            $selected_text = ($detail['selected'] >= 0 && isset($detail['answers'][$detail['selected']])) ? $detail['answers'][$detail['selected']] : __('Brak odpowiedzi', 'wcrq');
            $correct_text = ($detail['correct'] !== null && $detail['correct'] >= 0 && isset($detail['answers'][$detail['correct']])) ? $detail['answers'][$detail['correct']] : __('Brak danych', 'wcrq');
            $detail_strings[] = $detail['label'] . ': ' . $selected_text . ' / ' . sprintf(__('Poprawna: %s', 'wcrq'), $correct_text);
        }

        $sheet->setCellValueByColumnAndRow(1, $rowNumber, $row->name);
        $sheet->setCellValueByColumnAndRow(2, $rowNumber, $row->email);
        $sheet->setCellValueByColumnAndRow(3, $rowNumber, $row->school);
        $sheet->setCellValueByColumnAndRow(4, $rowNumber, $row->class);
        $sheet->setCellValueByColumnAndRow(5, $rowNumber, number_format_i18n($row->score, 2));
        $sheet->setCellValueByColumnAndRow(6, $rowNumber, wcrq_format_duration($duration_seconds));
        $sheet->setCellValueByColumnAndRow(7, $rowNumber, intval($row->violations));
        $sheet->setCellValueByColumnAndRow(8, $rowNumber, $row->is_completed ? __('Tak', 'wcrq') : __('Nie', 'wcrq'));
        $sheet->setCellValueByColumnAndRow(9, $rowNumber, $row->start_time);
        $sheet->setCellValueByColumnAndRow(10, $rowNumber, $row->end_time);
        $sheet->setCellValueByColumnAndRow(11, $rowNumber, implode("\n", $detail_strings));

        $rowNumber++;
    }

    $filename = 'wcr-quiz-wyniki-' . wp_date('Y-m-d-His') . '.xlsx';
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: max-age=0');

    $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
    $writer->save('php://output');
    exit;
}

