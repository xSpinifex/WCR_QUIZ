<?php
/*
Plugin Name: WCR Quiz
Description: Simple quiz plugin with registration and scheduled start.
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

    add_settings_section('wcrq_main', __('Quiz settings', 'wcrq'), '__return_false', 'wcrq');

    add_settings_field('start_time', __('Start time', 'wcrq'), 'wcrq_field_start_time', 'wcrq', 'wcrq_main');
    add_settings_field('duration', __('Duration (minutes)', 'wcrq'), 'wcrq_field_duration', 'wcrq', 'wcrq_main');
    add_settings_field('questions', __('Questions JSON', 'wcrq'), 'wcrq_field_questions', 'wcrq', 'wcrq_main');
    add_settings_field('show_results', __('Show result to user', 'wcrq'), 'wcrq_field_show_results', 'wcrq', 'wcrq_main');
}
add_action('admin_init', 'wcrq_register_settings');

function wcrq_settings_page() {
    add_menu_page('WCR Quiz', 'WCR Quiz', 'manage_options', 'wcrq', 'wcrq_settings_page_html', 'dashicons-welcome-learn-more', 20);
}
add_action('admin_menu', 'wcrq_settings_page');

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
    $value = isset($options['questions']) ? esc_textarea($options['questions']) : '';
    echo '<textarea name="wcrq_settings[questions]" rows="10" cols="50" class="large-text code">' . $value . '</textarea>';
    echo '<p class="description">' . __('Enter questions as JSON array. Each question: {"question":"text","answers":["A","B","C","D"],"correct":1}', 'wcrq') . '</p>';
}

function wcrq_field_show_results() {
    $options = get_option('wcrq_settings');
    $checked = isset($options['show_results']) ? (bool)$options['show_results'] : false;
    echo '<input type="checkbox" name="wcrq_settings[show_results]" value="1"' . checked(1, $checked, false) . ' />';
}

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
                'password' => wp_hash_password($password)
            ]);
            $output .= '<p>' . __('Registration complete. Keep your login and password.', 'wcrq') . '</p>';
        } else {
            $output .= '<p>' . __('All fields required.', 'wcrq') . '</p>';
        }
    }
    $output .= '<form method="post" class="wcrq-registration">'
        . wp_nonce_field('wcrq_reg', 'wcrq_reg_nonce', true, false)
        . '<p><label>' . __('Name', 'wcrq') . '<br /><input type="text" name="wcrq_name" required></label></p>'
        . '<p><label>' . __('Email', 'wcrq') . '<br /><input type="email" name="wcrq_email" required></label></p>'
        . '<p><label>' . __('Login', 'wcrq') . '<br /><input type="text" name="wcrq_login" required></label></p>'
        . '<p><label>' . __('Password', 'wcrq') . '<br /><input type="password" name="wcrq_password" required></label></p>'
        . '<p><button type="submit">' . __('Register', 'wcrq') . '</button></p>'
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
        return '<p>' . sprintf(__('Quiz will start in %s minutes.', 'wcrq'), ceil($remaining/60)) . '</p>';
    }
    if ($now > $end_time) {
        return '<p>' . __('Quiz time has ended.', 'wcrq') . '</p>';
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
            return '<form method="post"><p><button type="submit" name="wcrq_start" value="1">' . __('Start Quiz', 'wcrq') . '</button></p></form>';
        }
    }

    // Handle submission
    if (!empty($_POST['wcrq_quiz_nonce']) && wp_verify_nonce($_POST['wcrq_quiz_nonce'], 'wcrq_quiz')) {
        return wcrq_handle_quiz_submit();
    }

    // Display quiz
    $questions = json_decode($options['questions'], true);
    if (!$questions) {
        return '<p>' . __('No questions configured.', 'wcrq') . '</p>';
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
    $out .= '<p><button type="submit">' . __('Finish', 'wcrq') . '</button></p></form>';
    return $out;
}
add_shortcode('wcr_quiz', 'wcrq_quiz_shortcode');

function wcrq_login_form($message = '') {
    $out = '';
    if ($message) {
        $out .= '<p>' . esc_html($message) . '</p>';
    }
    $out .= '<form method="post" class="wcrq-login">'
        . '<p><label>' . __('Login', 'wcrq') . '<br /><input type="text" name="wcrq_login" required></label></p>'
        . '<p><label>' . __('Password', 'wcrq') . '<br /><input type="password" name="wcrq_password" required></label></p>'
        . '<p><button type="submit" name="wcrq_do_login" value="1">' . __('Log in', 'wcrq') . '</button></p>'
        . '</form>';
    return $out;
}

function wcrq_quiz_shortcode_process_login() {
    if (!empty($_POST['wcrq_do_login'])) {
        global $wpdb;
        $table = $wpdb->prefix . 'wcrq_participants';
        $login = sanitize_user($_POST['wcrq_login'] ?? '');
        $password = sanitize_text_field($_POST['wcrq_password'] ?? '');
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE login = %s", $login));
        if ($row && wp_check_password($password, $row->password)) {
            $_SESSION['wcrq_participant'] = $row->id;
            return true;
        } else {
            echo wcrq_login_form(__('Invalid credentials', 'wcrq'));
            return false;
        }
    }
    return null;
}
add_action('template_redirect', 'wcrq_quiz_shortcode_process_login');

function wcrq_handle_quiz_submit() {
    if (empty($_SESSION['wcrq_questions']) || empty($_SESSION['wcrq_participant'])) {
        return '<p>' . __('Session expired.', 'wcrq') . '</p>';
    }
    $options = get_option('wcrq_settings');
    $end = isset($options['start_time']) ? strtotime($options['start_time']) + intval($options['duration']) * 60 : time();
    if (current_time('timestamp') > $end) {
        return '<p>' . __('Quiz time has ended.', 'wcrq') . '</p>';
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
        return '<p>' . sprintf(__('Your score: %s%%', 'wcrq'), $score) . '</p>';
    }
    return '<p>' . __('Your answers have been submitted.', 'wcrq') . '</p>';
}

