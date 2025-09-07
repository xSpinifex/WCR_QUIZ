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
    $rows = $wpdb->get_results("SELECT * FROM $table ORDER BY created DESC");
    echo '<div class="wrap"><h1>' . esc_html(__('Rejestracje', 'wcrq')) . '</h1>';
    if ($rows) {
        echo '<table class="wp-list-table widefat fixed striped"><thead><tr>'
            . '<th>' . __('Szkoła', 'wcrq') . '</th>'
            . '<th>' . __('Uczeń', 'wcrq') . '</th>'
            . '<th>' . __('Klasa', 'wcrq') . '</th>'
            . '<th>' . __('Email', 'wcrq') . '</th>'
            . '<th>' . __('Login', 'wcrq') . '</th>'
            . '<th>' . __('Hasło', 'wcrq') . '</th>'
            . '<th>' . __('Zarejestrowano', 'wcrq') . '</th>'
            . '</tr></thead><tbody>';
        foreach ($rows as $r) {
            echo '<tr>'
                . '<td>' . esc_html($r->school) . '</td>'
                . '<td>' . esc_html($r->name) . '</td>'
                . '<td>' . esc_html($r->class) . '</td>'
                . '<td>' . esc_html($r->email) . '</td>'
                . '<td>' . esc_html($r->login) . '</td>'
                . '<td>' . esc_html($r->pass_plain) . '</td>'
                . '<td>' . esc_html($r->created) . '</td>'
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
        $school = sanitize_text_field($_POST['wcrq_school'] ?? '');
        $name = sanitize_text_field($_POST['wcrq_name'] ?? '');
        $class = sanitize_text_field($_POST['wcrq_class'] ?? '');
        $email = sanitize_email($_POST['wcrq_email'] ?? '');
        if ($school && $name && $class && $email) {
            // Generate unique login and password
            do {
                $login = strtolower(wp_generate_password(8, false));
                $exists = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table WHERE login = %s", $login));
            } while ($exists);
            $password = wp_generate_password(8, false);
            $wpdb->insert($table, [
                'school' => $school,
                'name' => $name,
                'class' => $class,
                'email' => $email,
                'login' => $login,
                'password' => wp_hash_password($password),
                'pass_plain' => $password
            ]);

            $subject = __('Dane logowania do quizu', 'wcrq');
            $body = sprintf(
                __('Szanowny Użytkowniku,<br><br>z radością informujemy, że Twoje konto w WCR Quiz zostało pomyślnie utworzone.<br><br><strong>Dane logowania:</strong><br>• Login: %1$s<br>• Hasło: %2$s (po zalogowaniu zalecamy jego zmianę)<br><br>W razie pytań lub problemów nasi konsultanci chętnie pomogą.<br><br>Z wyrazami szacunku,<br>Zespół WCR Quiz', 'wcrq'),
                esc_html($login),
                esc_html($password)
            );
            $headers = ['Content-Type: text/html; charset=UTF-8'];
            wp_mail($email, $subject, $body, $headers);

            $output .= '<p>' . __('Rejestracja zakończona sukcesem. Sprawdź e-mail.', 'wcrq') . '</p>';
        } else {
            $output .= '<p>' . __('Wszystkie pola są wymagane.', 'wcrq') . '</p>';
        }
    }
    $output .= '<form method="post" class="wcrq-registration">'
        . wp_nonce_field('wcrq_reg', 'wcrq_reg_nonce', true, false)
        . '<p><label>' . __('Nazwa szkoły', 'wcrq') . '<br /><input type="text" name="wcrq_school" required></label></p>'
        . '<p><label>' . __('Imię i nazwisko', 'wcrq') . '<br /><input type="text" name="wcrq_name" required></label></p>'
        . '<p><label>' . __('Klasa', 'wcrq') . '<br /><input type="text" name="wcrq_class" required></label></p>'
        . '<p><label>Email<br /><input type="email" name="wcrq_email" required></label></p>'
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

