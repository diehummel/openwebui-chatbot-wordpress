<?php
/**
 * Plugin Name: OpenWebUI Chatbot (Konfigurierbar)
 * Description: KI-Chatbot mit OpenWebUI – Host, Port, Modell einstellbar!
 * Version: 1.0.0
 * Author: diehummel
 */

if (!defined('ABSPATH')) exit;

define('OWC_URL', plugin_dir_url(__FILE__));
define('OWC_PATH', plugin_dir_path(__FILE__));

require_once OWC_PATH . 'includes/admin.php';
require_once OWC_PATH . 'includes/frontend.php';

add_action('wp_enqueue_scripts', function () {
    if (is_admin()) return;
    wp_enqueue_script('owc-js', OWC_URL . 'assets/chat.js', ['jquery'], '1.1', true);
    wp_enqueue_style('owc-css', OWC_URL . 'assets/style.css', [], '1.1');
    wp_localize_script('owc-js', 'owc', [
        'ajax' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('owc'),
        'welcome' => nl2br(esc_html(get_option('owc_welcome', "Hallo! Ich bin dein KI-Assistent.\nFrag mich alles über diese Website!")))
    ]);
});

add_action('wp_footer', function () {
    if (is_admin()) return; ?>
    <div id="owc-bubble">Chat</div>
    <div id="owc-chat" class="closed">
        <div id="owc-header">OpenWebUI Bot <span id="owc-close">X</span></div>
        <div id="owc-messages"></div>
        <div id="owc-input">
            <input type="text" id="owc-text" placeholder="Deine Frage…">
            <button id="owc-send">Send</button>
        </div>
    </div>
<?php });
