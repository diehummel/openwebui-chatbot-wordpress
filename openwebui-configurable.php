<?php
/**
 * Plugin Name: OpenWebUI Chatbot for WordPress
 * Description: Ein einfacher Chatbot, der mit OpenWebUI integriert ist.
 * Version: 1.0.0
 * Author: diehummel
 */

// Verhindere direkten Zugriff
if (!defined('ABSPATH')) {
    exit;
}

// Plugin-Initialisierung
class OpenWebUI_Chatbot {
    private $openwebui_url;
    private $api_key;

    public function __construct() {
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'settings_init'));
        add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'));
        add_shortcode('openwebui_chatbot', array($this, 'chatbot_shortcode'));
        add_action('wp_ajax_openwebui_chat', array($this, 'handle_chat_ajax'));
        add_action('wp_ajax_nopriv_openwebui_chat', array($this, 'handle_chat_ajax'));
        $this->openwebui_url = get_option('openwebui_url', 'http://localhost:3000');
        $this->api_key = get_option('openwebui_api_key', '');
    }

    // Admin-Menü hinzufügen
    public function add_admin_menu() {
        add_options_page(
            'OpenWebUI Chatbot Einstellungen',
            'OpenWebUI Chatbot',
            'manage_options',
            'openwebui-chatbot',
            array($this, 'options_page')
        );
    }

    // Settings initialisieren (erweitert um API-Key-Feld)
    public function settings_init() {
        register_setting('openwebui_chatbot', 'openwebui_url');
        register_setting('openwebui_chatbot', 'openwebui_api_key');

        add_settings_section(
            'openwebui_section',
            'OpenWebUI Konfiguration',
            null,
            'openwebui-chatbot'
        );

        add_settings_field(
            'openwebui_url',
            'OpenWebUI URL',
            array($this, 'url_field'),
            'openwebui-chatbot',
            'openwebui_section'
        );

        // Neues Feld für API Key
        add_settings_field(
            'openwebui_api_key',
            'OpenWebUI API Key (für Authentifizierung)',
            array($this, 'api_key_field'),
            'openwebui-chatbot',
            'openwebui_section'
        );
    }

    // URL-Feld rendern
    public function url_field() {
        $value = get_option('openwebui_url', 'http://localhost:3000');
        echo '<input type="url" name="openwebui_url" value="' . esc_attr($value) . '" class="regular-text" />';
        echo '<p class="description">Die Basis-URL deiner OpenWebUI-Instanz (z. B. http://localhost:3000).</p>';
    }

    // Neues API-Key-Feld rendern (sicher maskiert)
    public function api_key_field() {
        $value = get_option('openwebui_api_key', '');
        echo '<input type="password" name="openwebui_api_key" value="' . esc_attr($value) . '" class="regular-text" />';
        echo '<p class="description">Dein OpenWebUI API-Key für sichere Authentifizierung. Erhältlich in OpenWebUI unter Einstellungen > API. Leer lassen, wenn keine Auth benötigt.</p>';
    }

    // Options-Seite rendern
    public function options_page() {
        ?>
        <form action="options.php" method="post">
            <h2>OpenWebUI Chatbot Einstellungen</h2>
            <?php
            settings_fields('openwebui_chatbot');
            do_settings_sections('openwebui-chatbot');
            submit_button();
            ?>
        </form>
        <?php
    }

    // Scripts enqueuen (JS für Chat, mit API-Key)
    public function enqueue_scripts() {
        wp_enqueue_script('openwebui-chat-js', plugin_dir_url(__FILE__) . 'chat.js', array('jquery'), '1.1', true);
        wp_localize_script('openwebui-chat-js', 'openwebui_ajax', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('openwebui_chat_nonce'),
            'api_key' => $this->api_key  // Key für JS verfügbar machen (nur wenn eingeloggt oder via Option)
        ));
        wp_enqueue_style('openwebui-chat-css', plugin_dir_url(__FILE__) . 'chat.css', array(), '1.1');
    }

    // Shortcode für Chatbot
    public function chatbot_shortcode($atts) {
        $atts = shortcode_atts(array('width' => '400px', 'height' => '500px'), $atts);
        ob_start();
        ?>
        <div id="openwebui-chatbot" style="width: <?php echo esc_attr($atts['width']); ?>; height: <?php echo esc_attr($atts['height']); ?>; border: 1px solid #ccc; padding: 10px;">
            <div id="chat-messages"></div>
            <input type="text" id="chat-input" placeholder="Deine Nachricht..." style="width: 100%; margin-top: 10px;" />
            <button id="chat-send">Senden</button>
        </div>
        <script>
            jQuery(document).ready(function($) {
                $('#chat-send').click(function() {
                    var message = $('#chat-input').val();
                    if (message) {
                        $('#chat-messages').append('<div><strong>Du:</strong> ' + message + '</div>');
                        $.post(openwebui_ajax.ajax_url, {
                            action: 'openwebui_chat',
                            message: message,
                            nonce: openwebui_ajax.nonce
                        }, function(response) {
                            if (response.success) {
                                $('#chat-messages').append('<div><strong>Bot:</strong> ' + response.data + '</div>');
                            } else {
                                $('#chat-messages').append('<div><strong>Fehler:</strong> ' + response.data + '</div>');
                            }
                        });
                        $('#chat-input').val('');
                    }
                });
            });
        </script>
        <?php
        return ob_get_clean();
    }

    // AJAX-Handler (erweitert um API-Key-Auth)
    public function handle_chat_ajax() {
        check_ajax_referer('openwebui_chat_nonce', 'nonce');

        if (empty($this->api_key)) {
            wp_send_json_error('API-Key nicht konfiguriert. Bitte im Admin-Panel einstellen.');
        }

        $message = sanitize_text_field($_POST['message']);
        $endpoint = $this->openwebui_url . '/api/chat/completions';  // Beispiel-Endpoint; passe an OpenWebUI API an

        $response = wp_remote_post($endpoint, array(
            'headers' => array(
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . $this->api_key  // Auth mit API-Key
            ),
            'body' => json_encode(array(
                'messages' => array(
                    array('role' => 'user', 'content' => $message)
                ),
                'model' => 'gpt-3.5-turbo'  // Oder dein Modell
            )),
            'timeout' => 30
        ));

        if (is_wp_error($response)) {
            wp_send_json_error('Verbindungsfehler: ' . $response->get_error_message());
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (isset($data['choices'][0]['message']['content'])) {
            wp_send_json_success($data['choices'][0]['message']['content']);
        } else {
            wp_send_json_error('Ungültige API-Antwort oder falscher Key.');
        }
    }
}

// Plugin starten
new OpenWebUI_Chatbot();
?>
