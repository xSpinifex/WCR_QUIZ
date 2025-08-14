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

// Activation: create DB tables
function wcrq_activate() {
    global $wpdb;
    $charset = $wpdb->get_charset_collate();
    $participants_table = $wpdb->prefix . 'wcrq_participants';
    $results_table = $wpdb->prefix . 'wcrq_results';

    $sql1 = "CREATE TABLE $participants_table (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
        name varchar(100) NOT NULL,
        email varchar(100) NOT NULL,
        login varchar(60) NOT NULL,
        password varchar(255) NOT NULL,
        approved tinyint(1) NOT NULL DEFAULT 0,
        code varchar(20) DEFAULT NULL,
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

// Settings page
function wcrq_register_settings() {
    register_setting('wcrq_settings', 'wcrq_settings');

    add_settings_section('wcrq_main', __('Ustawienia quizu', 'wcrq'), '__return_false', 'wcrq');

    add_settings_field('start_time', __('Czas rozpoczęcia', 'wcrq'), 'wcrq_field_start_time', 'wcrq', 'wcrq_main');
    add_settings_field('duration', __('Czas trwania (minuty)', 'wcrq'), 'wcrq_field_duration', 'wcrq', 'wcrq_main');
    add_settings_field('questions', __('Pytania', 'wcrq'), 'wcrq_field_questions', 'wcrq', 'wcrq_main');
    add_settings_field('show_results', __('Pokaż wynik użytkownikowi', 'wcrq'), 'wcrq_field_show_results', 'wcrq', 'wcrq_main');
}
add_action('admin_init', 'wcrq_register_settings');

function wcrq_admin_menu() {
    add_menu_page('WCR Quiz', 'WCR Quiz', 'manage_options', 'wcrq', 'wcrq_settings_page_html', 'dashicons-welcome-learn-more', 20);
    add_submenu_page('wcrq', __('Ustawienia quizu', 'wcrq'), __('Ustawienia quizu', 'wcrq'), 'manage_options', 'wcrq', 'wcrq_settings_page_html');
    add_submenu_page('wcrq', __('Rejestracje', 'wcrq'), __('Rejestracje', 'wcrq'), 'manage_options', 'wcrq_registrations', 'wcrq_registrations_page_html');
    add_submenu_page('wcrq', __('Wyniki', 'wcrq'), __('Wyniki', 'wcrq'), 'manage_options', 'wcrq_results', 'wcrq_results_page_html');
}
add_action('admin_menu', 'wcrq_admin_menu');

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
    if (!empty($_GET['approve']) && check_admin_referer('wcrq_approve_participant')) {
        $id = intval($_GET['approve']);
        $code = wp_generate_password(6, false);
        $wpdb->update($table, ['approved' => 1, 'code' => $code], ['id' => $id]);
        $participant = $wpdb->get_row($wpdb->prepare("SELECT email FROM $table WHERE id = %d", $id));
        if ($participant) {
            wp_mail($participant->email, __('Kod dostępu do quizu', 'wcrq'), sprintf(__('Twój kod: %s', 'wcrq'), $code));
        }
        echo '<div class="updated"><p>' . __('Użytkownik zaakceptowany.', 'wcrq') . '</p></div>';
    }
    $rows = $wpdb->get_results("SELECT * FROM $table ORDER BY created DESC");
    echo '<div class="wrap"><h1>' . esc_html(__('Rejestracje', 'wcrq')) . '</h1>';
    if ($rows) {
        echo '<table class="widefat"><thead><tr><th>' . __('Imię', 'wcrq') . '</th><th>Email</th><th>Login</th><th>' . __('Status', 'wcrq') . '</th><th>' . __('Kod', 'wcrq') . '</th><th>' . __('Akcja', 'wcrq') . '</th></tr></thead><tbody>';
        foreach ($rows as $r) {
            echo '<tr><td>' . esc_html($r->name) . '</td><td>' . esc_html($r->email) . '</td><td>' . esc_html($r->login) . '</td><td>' . ($r->approved ? __('Zaakceptowany', 'wcrq') : __('Oczekuje', 'wcrq')) . '</td><td>' . esc_html($r->code) . '</td><td>';
            if (!$r->approved) {
                $url = wp_nonce_url(admin_url('admin.php?page=wcrq_registrations&approve=' . $r->id), 'wcrq_approve_participant');
                echo '<a class="button" href="' . esc_url($url) . '">' . __('Akceptuj', 'wcrq') . '</a>';
            }
            echo '</td></tr>';
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
}

function wcrq_field_duration() {
    $options = get_option('wcrq_settings');
    $value = isset($options['duration']) ? intval($options['duration']) : 30;
    echo '<input type="number" name="wcrq_settings[duration]" value="' . $value . '" min="1" />';
}

function wcrq_field_questions() {
    $options = get_option('wcrq_settings');
    $value = isset($options['questions']) ? esc_attr($options['questions']) : '[]';
    echo '<div id="wcrq-questions-builder"></div>';
    echo '<input type="hidden" id="wcrq_questions_input" name="wcrq_settings[questions]" value="' . $value . '" />';
}

function wcrq_field_show_results() {
    $options = get_option('wcrq_settings');
    $checked = isset($options['show_results']) ? (bool)$options['show_results'] : false;
    echo '<input type="checkbox" name="wcrq_settings[show_results]" value="1"' . checked(1, $checked, false) . ' />';
}

function wcrq_admin_scripts($hook) {
    if (isset($_GET['page']) && $_GET['page'] === 'wcrq') {
        wp_enqueue_script('wcrq-questions-builder', plugins_url('assets/js/questions-builder.js', __FILE__), ['jquery'], '0.1', true);
    }
}
add_action('admin_enqueue_scripts', 'wcrq_admin_scripts');

// Registration shortcode
function wcrq_registration_shortcode() {
    $output = '';
    if (!empty($_POST['wcrq_reg_nonce']) && wp_verify_nonce($_POST['wcrq_reg_nonce'], 'wcrq_reg')) {
        global $wpdb;
        $table = $wpdb->prefix . 'wcrq_participants';
        $name = sanitize_text_field($_POST['wcrq_name'] ?? '');
        $email = sanitize_email($_POST['wcrq_email'] ?? '');
        $login = sanitize_user($_POST['wcrq_login'] ?? '');
        $password = sanitize_text_field($_POST['wcrq_password'] ?? '');
        if ($name && $email && $login && $password) {
            $wpdb->insert($table, [
                'name' => $name,
                'email' => $email,
                'login' => $login,
                'password' => wp_hash_password($password),
                'approved' => 0,
                'code' => null
            ]);
            $output .= '<p>' . __('Rejestracja wysłana. Poczekaj na akceptację.', 'wcrq') . '</p>';
        } else {
            $output .= '<p>' . __('Wszystkie pola są wymagane.', 'wcrq') . '</p>';
        }
    }
    $output .= '<form method="post" class="wcrq-registration">'
        . wp_nonce_field('wcrq_reg', 'wcrq_reg_nonce', true, false)
        . '<p><label>' . __('Imię', 'wcrq') . '<br /><input type="text" name="wcrq_name" required></label></p>'
        . '<p><label>Email<br /><input type="email" name="wcrq_email" required></label></p>'
        . '<p><label>Login<br /><input type="text" name="wcrq_login" required></label></p>'
        . '<p><label>' . __('Hasło', 'wcrq') . '<br /><input type="password" name="wcrq_password" required></label></p>'
        . '<p><button type="submit">' . __('Zarejestruj się', 'wcrq') . '</button></p>'
        . '</form>';
    return $output;
}
add_shortcode('wcr_registration', 'wcrq_registration_shortcode');

// Quiz shortcode
function wcrq_quiz_shortcode() {
    $options = get_option('wcrq_settings');
    $start_time = isset($options['start_time']) ? strtotime($options['start_time']) : 0;
    $duration = isset($options['duration']) ? intval($options['duration']) : 30;
    $end_time = $start_time + $duration * 60;
    $now = current_time('timestamp');

    if ($now < $start_time) {
        $remaining = $start_time - $now;
        return '<p>' . sprintf(__('Quiz rozpocznie się za %s minut.', 'wcrq'), ceil($remaining/60)) . '</p>';
    }
    if ($now > $end_time) {
        return '<p>' . __('Czas na quiz się skończył.', 'wcrq') . '</p>';
    }
    $remaining = $end_time - $now;

    // Check login
    if (empty($_SESSION['wcrq_participant'])) {
        return wcrq_login_form();
    }

    // Check if quiz started
    if (empty($_SESSION['wcrq_started'])) {
        if (!empty($_POST['wcrq_start'])) {
            $_SESSION['wcrq_started'] = time();
        } else {
            return '<form method="post"><p><button type="submit" name="wcrq_start" value="1">' . __('Rozpocznij quiz', 'wcrq') . '</button></p></form>';
        }
    }

    // Handle submission
    if (!empty($_POST['wcrq_quiz_nonce']) && wp_verify_nonce($_POST['wcrq_quiz_nonce'], 'wcrq_quiz')) {
        return wcrq_handle_quiz_submit();
    }

    // Display quiz
    $questions = json_decode($options['questions'], true);
    if (!$questions) {
        return '<p>' . __('Brak skonfigurowanych pytań.', 'wcrq') . '</p>';
    }
    shuffle($questions);
    foreach ($questions as &$q) {
        if (isset($q['answers']) && is_array($q['answers'])) {
            $answers = $q['answers'];
            $keys = array_keys($answers);
            shuffle($keys);
            $shuffled = [];
            foreach ($keys as $k) {
                $shuffled[] = $answers[$k];
            }
            $q['answers'] = $shuffled;
        }
    }
    $_SESSION['wcrq_questions'] = $questions;

    wp_enqueue_script('wcrq-quiz', plugins_url('assets/js/quiz.js', __FILE__), [], '0.1', true);
    $out = '<form method="post" class="wcrq-quiz" data-duration="' . intval($remaining) . '">' . wp_nonce_field('wcrq_quiz', 'wcrq_quiz_nonce', true, false);
    foreach ($questions as $idx => $q) {
        $out .= '<div class="wcrq-question"><p>' . esc_html($q['question']) . '</p>';
        foreach ($q['answers'] as $a_idx => $answer) {
            $name = 'q' . $idx;
            $out .= '<label><input type="radio" name="' . esc_attr($name) . '" value="' . $a_idx . '"> ' . esc_html($answer) . '</label><br />';
        }
        $out .= '</div>';
    }
    $out .= '<p><button type="submit">' . __('Zakończ', 'wcrq') . '</button></p></form>';
    return $out;
}
add_shortcode('wcr_quiz', 'wcrq_quiz_shortcode');

function wcrq_login_form($message = '') {
    $out = '';
    if ($message) {
        $out .= '<p>' . esc_html($message) . '</p>';
    }
    $out .= '<form method="post" class="wcrq-login">'
        . '<p><label>' . __('Kod dostępu', 'wcrq') . '<br /><input type="text" name="wcrq_code" required></label></p>'
        . '<p><button type="submit" name="wcrq_do_login" value="1">' . __('Wejdź', 'wcrq') . '</button></p>'
        . '</form>';
    return $out;
}

function wcrq_quiz_shortcode_process_login() {
    if (!empty($_POST['wcrq_do_login'])) {
        global $wpdb;
        $table = $wpdb->prefix . 'wcrq_participants';
        $code = sanitize_text_field($_POST['wcrq_code'] ?? '');
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE code = %s AND approved = 1", $code));
        if ($row) {
            $_SESSION['wcrq_participant'] = $row->id;
            return true;
        } else {
            echo wcrq_login_form(__('Nieprawidłowy kod.', 'wcrq'));
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
    $end = isset($options['start_time']) ? strtotime($options['start_time']) + intval($options['duration']) * 60 : time();
    if (current_time('timestamp') > $end) {
        return '<p>' . __('Czas na quiz się skończył.', 'wcrq') . '</p>';
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
    if (!empty($options['show_results'])) {
        return '<p>' . sprintf(__('Twój wynik: %s%%', 'wcrq'), $score) . '</p>';
    }
    return '<p>' . __('Twoje odpowiedzi zostały zapisane.', 'wcrq') . '</p>';
}

