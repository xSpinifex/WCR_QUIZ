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
        created datetime DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY  (id),
        UNIQUE KEY login (login)
    ) $charset;";

    $sql2 = "CREATE TABLE $results_table (
        id bigint(20) NOT NULL AUTO_INCREMENT,
        participant_id mediumint(9) NOT NULL,
        score float NOT NULL,
        start_time datetime NOT NULL,
        end_time datetime NOT NULL,
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
}
add_action('plugins_loaded', 'wcrq_maybe_update_participants_table');

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
}
add_action('admin_init', 'wcrq_register_settings');

function wcrq_admin_menu() {
    add_menu_page('WCR Quiz', 'WCR Quiz', 'manage_options', 'wcrq', 'wcrq_settings_page_html', 'dashicons-welcome-learn-more', 20);
    add_submenu_page('wcrq', __('Ustawienia quizu', 'wcrq'), __('Ustawienia quizu', 'wcrq'), 'manage_options', 'wcrq', 'wcrq_settings_page_html');
    add_submenu_page('wcrq', __('Pytania quizu', 'wcrq'), __('Pytania', 'wcrq'), 'manage_options', 'wcrq_questions', 'wcrq_questions_page_html');
    add_submenu_page('wcrq', __('Rejestracje', 'wcrq'), __('Rejestracje', 'wcrq'), 'manage_options', 'wcrq_registrations', 'wcrq_registrations_page_html');
    add_submenu_page('wcrq', __('Wyniki', 'wcrq'), __('Wyniki', 'wcrq'), 'manage_options', 'wcrq_results', 'wcrq_results_page_html');
}
add_action('admin_menu', 'wcrq_admin_menu');

function wcrq_get_polish_timezone() {
    try {
        return new DateTimeZone('Europe/Warsaw');
    } catch (Exception $e) {
        return wp_timezone();
    }
}

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

    $questions = [];

    if (!empty($input['questions']) && is_array($input['questions'])) {
        foreach ($input['questions'] as $question) {
            $prepared = wcrq_prepare_question_data($question);
            if ($prepared) {
                $questions[] = $prepared;
            }
        }
    } elseif (!empty($input['questions']) && is_string($input['questions'])) {
        $raw = wp_unslash($input['questions']);
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            foreach ($decoded as $question) {
                $prepared = wcrq_prepare_question_data($question, false);
                if ($prepared) {
                    $questions[] = $prepared;
                }
            }
        }
    }

    if (empty($questions) && !empty($input['questions_nojs']) && is_array($input['questions_nojs'])) {
        foreach ($input['questions_nojs'] as $question) {
            $prepared = wcrq_prepare_question_data($question);
            if ($prepared) {
                $questions[] = $prepared;
            }
        }
    }

    $output['questions'] = $questions;

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

function wcrq_get_blank_question() {
    return [
        'question' => '',
        'answers' => ['', '', '', ''],
        'correct' => 0,
        'image' => '',
    ];
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

function wcrq_questions_page_html() {
    if (!current_user_can('manage_options')) {
        return;
    }
    ?>
    <div class="wrap">
        <h1><?php esc_html_e('Pytania quizu', 'wcrq'); ?></h1>
        <p class="description"><?php esc_html_e('Każde pytanie będzie wyświetlane w osobnej zakładce.', 'wcrq'); ?></p>
        <form action="options.php" method="post">
            <?php
            settings_fields('wcrq_settings');
            wcrq_field_questions();
            ?>
            <?php submit_button(); ?>
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

function wcrq_results_page_html() {
    if (!current_user_can('manage_options')) {
        return;
    }
    global $wpdb;
    $pt = $wpdb->prefix . 'wcrq_participants';
    $rt = $wpdb->prefix . 'wcrq_results';
    $rows = $wpdb->get_results("SELECT p.name, p.email, r.score, r.start_time, r.end_time FROM $rt r JOIN $pt p ON r.participant_id = p.id ORDER BY r.end_time DESC");
    echo '<div class="wrap"><h1>' . esc_html(__('Wyniki', 'wcrq')) . '</h1>';
    if ($rows) {
        echo '<table class="widefat"><thead><tr><th>' . __('Imię', 'wcrq') . '</th><th>Email</th><th>' . __('Wynik', 'wcrq') . '</th><th>' . __('Start', 'wcrq') . '</th><th>' . __('Koniec', 'wcrq') . '</th></tr></thead><tbody>';
        foreach ($rows as $r) {
            echo '<tr><td>' . esc_html($r->name) . '</td><td>' . esc_html($r->email) . '</td><td>' . esc_html($r->score) . '%</td><td>' . esc_html($r->start_time) . '</td><td>' . esc_html($r->end_time) . '</td></tr>';
        }
        echo '</tbody></table>';
    } else {
        echo '<p>' . __('Brak wyników.', 'wcrq') . '</p>';
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

function wcrq_render_question_fieldset($index, $question, $is_template = false) {
    $index_attr = $is_template ? '__index__' : intval($index);
    $number = $is_template ? '{{number}}' : intval($index) + 1;
    $name_prefix_actual = 'wcrq_settings[questions][' . $index_attr . ']';
    $name_prefix_template = 'wcrq_settings[questions][__index__]';
    $answers = isset($question['answers']) && is_array($question['answers']) ? $question['answers'] : ['', '', '', ''];
    $answers = array_pad($answers, 4, '');
    $correct = isset($question['correct']) ? intval($question['correct']) : 0;
    $classes = ['wcrq-question-item'];
    if ($is_template) {
        $classes[] = 'wcrq-question-item-template';
    }

    echo '<fieldset class="' . esc_attr(implode(' ', $classes)) . '" data-index="' . esc_attr($index_attr) . '">';
    echo '<legend>' . esc_html__('Pytanie', 'wcrq') . ' <span class="wcrq-question-number">' . esc_html($number) . '</span></legend>';

    echo '<div class="wcrq-question-fields">';
    echo '<p class="wcrq-field"><label>' . esc_html__('Treść pytania', 'wcrq') . '<br />';
    echo '<input type="text" class="regular-text wcrq-q" name="' . esc_attr($name_prefix_actual . '[question]') . '" value="' . esc_attr($question['question'] ?? '') . '" data-name-template="' . esc_attr($name_prefix_template . '[question]') . '" />';
    echo '</label></p>';

    echo '<div class="wcrq-field wcrq-field-image">';
    echo '<label>' . esc_html__('Adres obrazka (opcjonalnie)', 'wcrq') . '<br />';
    echo '<input type="url" class="regular-text wcrq-img" name="' . esc_attr($name_prefix_actual . '[image]') . '" value="' . esc_attr($question['image'] ?? '') . '" data-name-template="' . esc_attr($name_prefix_template . '[image]') . '" />';
    echo '</label>';
    echo '<div class="wcrq-image-preview">';
    if (!empty($question['image'])) {
        echo '<img src="' . esc_url($question['image']) . '" alt="" />';
    }
    echo '</div>';
    echo '<p class="wcrq-image-actions">';
    echo '<button type="button" class="button wcrq-select-image">' . esc_html__('Wybierz grafikę', 'wcrq') . '</button> ';
    echo '<button type="button" class="button wcrq-remove-image">' . esc_html__('Usuń grafikę', 'wcrq') . '</button>';
    echo '</p>';
    echo '</div>';
    echo '</div>';

    echo '<div class="wcrq-answers">';
    echo '<p><strong>' . esc_html__('Odpowiedzi', 'wcrq') . '</strong></p>';
    for ($a = 0; $a < 4; $a++) {
        $answer_value = esc_attr($answers[$a]);
        echo '<p class="wcrq-answer-row"><label>';
        echo '<input type="radio" class="wcrq-correct" name="' . esc_attr($name_prefix_actual . '[correct]') . '" value="' . $a . '" data-name-template="' . esc_attr($name_prefix_template . '[correct]') . '"' . checked($correct, $a, false) . ' /> ';
        echo '<input type="text" class="regular-text wcrq-a" name="' . esc_attr($name_prefix_actual . '[answers][' . $a . ']') . '" value="' . $answer_value . '" data-name-template="' . esc_attr($name_prefix_template . '[answers][' . $a . ']') . '" />';
        echo '</label></p>';
    }
    echo '</div>';

    echo '<p class="wcrq-question-actions">';
    echo '<button type="button" class="button wcrq-preview">' . esc_html__('Podgląd', 'wcrq') . '</button> ';
    echo '<button type="button" class="button button-link-delete wcrq-remove">' . esc_html__('Usuń pytanie', 'wcrq') . '</button>';
    echo '</p>';
    echo '<div class="wcrq-preview-area" style="display:none;"></div>';
    echo '</fieldset>';
}

function wcrq_render_fallback_question($idx, $question) {
    $question_text = esc_attr($question['question'] ?? '');
    $answers = isset($question['answers']) && is_array($question['answers']) ? $question['answers'] : ['', '', '', ''];
    $answers = array_pad($answers, 4, '');
    $image = esc_attr($question['image'] ?? '');
    $correct = isset($question['correct']) ? intval($question['correct']) : 0;

    echo '<fieldset class="wcrq-question-fallback">';
    echo '<legend>' . sprintf(esc_html__('Pytanie %d', 'wcrq'), intval($idx) + 1) . '</legend>';
    echo '<p><label>' . esc_html__('Treść pytania', 'wcrq') . '<br /><input type="text" name="wcrq_settings[questions_nojs][' . intval($idx) . '][question]" value="' . $question_text . '" class="regular-text" /></label></p>';
    echo '<p><label>' . esc_html__('Adres obrazka (opcjonalnie)', 'wcrq') . '<br /><input type="url" name="wcrq_settings[questions_nojs][' . intval($idx) . '][image]" value="' . $image . '" class="regular-text" /></label></p>';
    echo '<div class="wcrq-fallback-answers">';
    echo '<p><strong>' . esc_html__('Odpowiedzi', 'wcrq') . '</strong></p>';
    for ($a = 0; $a < 4; $a++) {
        $answer_value = esc_attr($answers[$a]);
        $radio_name = 'wcrq_settings[questions_nojs][' . intval($idx) . '][correct]';
        $answer_name = 'wcrq_settings[questions_nojs][' . intval($idx) . '][answers][' . $a . ']';
        echo '<p><label><input type="radio" name="' . esc_attr($radio_name) . '" value="' . $a . '"' . checked($correct, $a, false) . ' /> <input type="text" name="' . esc_attr($answer_name) . '" value="' . $answer_value . '" class="regular-text" /></label></p>';
    }
    echo '</div>';
    echo '</fieldset>';
}

function wcrq_field_questions() {
    $questions = wcrq_get_saved_questions();
    if (empty($questions)) {
        $questions = [wcrq_get_blank_question()];
    }

    echo '<div id="wcrq-questions-app" class="wcrq-questions-app">';
    echo '<p class="description">' . esc_html__('Dodaj pytania i odpowiedzi korzystając z edytora poniżej. Każde pytanie posiada cztery możliwe odpowiedzi.', 'wcrq') . '</p>';
    echo '<div class="wcrq-question-list">';
    foreach ($questions as $idx => $question) {
        wcrq_render_question_fieldset($idx, $question);
    }
    echo '</div>';
    echo '<p><button type="button" class="button button-primary" id="wcrq_add_question">' . esc_html__('Dodaj pytanie', 'wcrq') . '</button></p>';
    echo '</div>';

    $template_question = wcrq_get_blank_question();
    ob_start();
    wcrq_render_question_fieldset('__index__', $template_question, true);
    $template_markup = ob_get_clean();
    echo '<template id="wcrq-question-template">' . $template_markup . '</template>';

    $fallback_questions = $questions;
    $fallback_questions[] = wcrq_get_blank_question();

    echo '<div class="wcrq-questions-fallback">';
    echo '<p class="description">' . esc_html__('Jeżeli edytor wizualny nie jest widoczny, możesz uzupełnić pytania w poniższym formularzu.', 'wcrq') . '</p>';
    foreach ($fallback_questions as $idx => $question) {
        wcrq_render_fallback_question($idx, $question);
    }
    echo '</div>';
}

function wcrq_field_show_results() {
    $options = get_option('wcrq_settings');
    $checked = isset($options['show_results']) ? (bool)$options['show_results'] : false;
    echo '<input type="checkbox" name="wcrq_settings[show_results]" value="1"' . checked(1, $checked, false) . ' />';
}

function wcrq_admin_scripts($hook) {
    if ($hook === 'wcrq_page_wcrq_questions') {
        // Media is required for adding images to questions
        wp_enqueue_media();
        wp_enqueue_style(
            'wcrq-questions-admin',
            plugins_url('assets/css/admin.css', __FILE__),
            [],
            '0.1'
        );
        wp_enqueue_script(
            'wcrq-questions-builder',
            plugins_url('assets/js/questions-builder.js', __FILE__),
            ['jquery', 'wp-i18n'],
            '0.4',
            true
        );
        if (function_exists('wp_set_script_translations')) {
            wp_set_script_translations('wcrq-questions-builder', 'wcrq');
        }
    }
}
add_action('admin_enqueue_scripts', 'wcrq_admin_scripts');

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
    $now = current_time('timestamp');

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
    if (empty($_SESSION['wcrq_participant'])) {
        return wcrq_login_form();
    }

    global $wpdb;
    $ptable = $wpdb->prefix . 'wcrq_participants';
    $blocked = $wpdb->get_var($wpdb->prepare("SELECT blocked FROM $ptable WHERE id = %d", $_SESSION['wcrq_participant']));
    if ($blocked) {
        return '<p>' . __('Twoje konto zostało zablokowane.', 'wcrq') . '</p>';
    }

    $allow_navigation = !empty($options['allow_navigation']);

    // Check if quiz started
    if (empty($_SESSION['wcrq_started'])) {
        if (!empty($_POST['wcrq_start'])) {
            $_SESSION['wcrq_started'] = current_time('timestamp');
        } else {
            $pre_quiz = !empty($options['pre_quiz_text']) ? wpautop(wp_kses_post($options['pre_quiz_text'])) : '';
            $warning = '';
            if (!$allow_navigation) {
                $warning = '<p class="wcrq-navigation-warning">' . esc_html__('Cofanie pytań jest niedozwolone', 'wcrq') . '</p>';
            }
            return '<div class="wcrq-pre-quiz">' . $pre_quiz . $warning . '<form method="post" class="wcrq-start"><p><button type="submit" name="wcrq_start" value="1">' . __('Rozpocznij quiz', 'wcrq') . '</button></p></form></div>';
        }
    }

    // Handle submission
    if (!empty($_POST['wcrq_quiz_nonce']) && wp_verify_nonce($_POST['wcrq_quiz_nonce'], 'wcrq_quiz')) {
        return wcrq_handle_quiz_submit();
    }

    // Display quiz
    if (!empty($_SESSION['wcrq_questions']) && is_array($_SESSION['wcrq_questions'])) {
        $questions = $_SESSION['wcrq_questions'];
    } else {
        $questions = wcrq_get_saved_questions();
        if (empty($questions)) {
            return '<p>' . __('Brak skonfigurowanych pytań.', 'wcrq') . '</p>';
        }

        if (!empty($options['randomize_questions'])) {
            shuffle($questions);
        }

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

        $_SESSION['wcrq_questions'] = $questions;
    }

    wp_enqueue_script('wcrq-quiz', plugins_url('assets/js/quiz.js', __FILE__), [], '0.2', true);
    $out = '<form method="post" class="wcrq-quiz wcrq-no-js" data-duration="' . intval($remaining) . '" data-allow-navigation="' . ($allow_navigation ? '1' : '0') . '">';
    $out .= wp_nonce_field('wcrq_quiz', 'wcrq_quiz_nonce', true, false);

    $out .= '<div class="wcrq-question-tabs" role="tablist">';
    foreach ($questions as $idx => $q) {
        $out .= '<button type="button" class="wcrq-question-tab" data-index="' . intval($idx) . '" role="tab">' . sprintf(__('Pytanie %d', 'wcrq'), $idx + 1) . '</button>';
    }
    $out .= '</div>';

    foreach ($questions as $idx => $q) {
        $out .= '<div class="wcrq-question" data-index="' . intval($idx) . '" role="tabpanel">';
        $out .= '<p class="wcrq-question-title">' . esc_html($q['question']) . '</p>';
        if (!empty($q['image'])) {
            $out .= '<p><img src="' . esc_url($q['image']) . '" alt="" class="wcrq-question-image" /></p>';
        }
        foreach ($q['answers'] as $a_idx => $answer) {
            $name = 'q' . $idx;
            $out .= '<label class="wcrq-answer"><input type="radio" name="' . esc_attr($name) . '" value="' . intval($a_idx) . '"> ' . esc_html($answer) . '</label>';
        }
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

function wcrq_login_form($message = '') {
    $out = '';
    if ($message) {
        $out .= '<p>' . esc_html($message) . '</p>';
    }
    $out .= '<form method="post" class="wcrq-login">'
        . '<p><label>Login<br /><input type="text" name="wcrq_login" required></label></p>'
        . '<p><label>' . __('Hasło', 'wcrq') . '<br /><input type="password" name="wcrq_pass" required></label></p>'
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
                echo wcrq_login_form(__('Twoje konto zostało zablokowane.', 'wcrq'));
                return false;
            }
            $_SESSION['wcrq_participant'] = $row->id;
            return true;
        } else {
            echo wcrq_login_form(__('Nieprawidłowy login lub hasło.', 'wcrq'));
            return false;
        }
    }
    return null;
}
add_action('template_redirect', 'wcrq_quiz_shortcode_process_login');

function wcrq_handle_quiz_submit() {
    if (empty($_SESSION['wcrq_questions']) || empty($_SESSION['wcrq_participant'])) {
        return '<p>' . __('Sesja wygasła.', 'wcrq') . '</p>';
    }
    $options = get_option('wcrq_settings');
    $end = !empty($options['end_time']) ? strtotime($options['end_time']) : 0;
    if ($end && current_time('timestamp') > $end) {
        $text = !empty($options['post_quiz_text']) ? wpautop(wp_kses_post($options['post_quiz_text'])) : '<p>' . esc_html__('Czas na quiz się skończył.', 'wcrq') . '</p>';
        return $text;
    }

    $questions = $_SESSION['wcrq_questions'];
    $answers = [];
    $correct = 0;
    foreach ($questions as $idx => $q) {
        $given = isset($_POST['q' . $idx]) ? intval($_POST['q' . $idx]) : -1;
        $answers[$idx] = $given;
        if ($given === intval($q['correct'])) {
            $correct++;
        }
    }
    $score = count($questions) ? round($correct / count($questions) * 100, 2) : 0;

    global $wpdb;
    $table = $wpdb->prefix . 'wcrq_results';
    $wpdb->insert($table, [
        'participant_id' => $_SESSION['wcrq_participant'],
        'score' => $score,
        'start_time' => date('Y-m-d H:i:s', $_SESSION['wcrq_started']),
        'end_time' => current_time('mysql'),
        'answers' => maybe_serialize($answers)
    ]);

    unset($_SESSION['wcrq_questions']);
    unset($_SESSION['wcrq_started']);

    $options = get_option('wcrq_settings');
    $message = '';
    if (!empty($options['show_results'])) {
        $message .= '<p>' . sprintf(__('Twój wynik: %s%%', 'wcrq'), $score) . '</p>';
    }
    if (!empty($options['post_quiz_text'])) {
        $message .= wpautop(wp_kses_post($options['post_quiz_text']));
    }
    if (!$message) {
        $message = '<p>' . __('Twoje odpowiedzi zostały zapisane.', 'wcrq') . '</p>';
    }
    return $message;
}

