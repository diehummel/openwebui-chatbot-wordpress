<?php
if (!defined('ABSPATH')) exit;

add_action('admin_menu', function () {
    add_options_page('OpenWebUI Chatbot', 'OpenWebUI Bot', 'manage_options', 'owc', 'owc_admin_page');
});

function owc_admin_page() {
    if (isset($_POST['save'])) {
        update_option('owc_protocol', sanitize_text_field($_POST['protocol']));
        update_option('owc_host', sanitize_text_field($_POST['host']));
        update_option('owc_port', sanitize_text_field($_POST['port']));
        update_option('owc_model', sanitize_text_field($_POST['model']));
        update_option('owc_welcome', wp_kses_post($_POST['welcome']));
        update_option('owc_bot_name', sanitize_text_field($_POST['bot_name']));
        update_option('owc_api_key', sanitize_text_field($_POST['api_key']));
        echo '<div class="notice notice-success"><p>Einstellungen gespeichert!</p></div>';
    }

    if (isset($_POST['crawl'])) {
        $count = owc_crawl();
        echo '<div class="notice notice-success"><p>' . $count . ' Seiten NEU gecrawlt!</p></div>';
    }

    // === LEERE Standardwerte ===
    $protocol = get_option('owc_protocol', 'https://');
    $host     = get_option('owc_host', '');
    $port     = get_option('owc_port', '');
    $model    = get_option('owc_model', '');
    $welcome  = get_option('owc_welcome', "Hallo! Ich bin dein KI-Assistent.\nFrag mich alles über diese Website!");
    $bot_name = get_option('owc_bot_name', 'KI-Assistent');
    $api_key  = get_option('owc_api_key', '');

    ?>
    <div class="wrap">
        <h1>OpenWebUI Chatbot – Einstellungen</h1>
        <form method="post">
            <table class="form-table">
                <tr>
                    <th>Protokoll</th>
                    <td>
                        <select name="protocol">
                            <option value="https://" <?= selected($protocol, 'https://', false) ?>>https://</option>
                            <option value="http://" <?= selected($protocol, 'http://', false) ?>>http://</option>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th>Host</th>
                    <td><input type="text" name="host" value="<?= esc_attr($host) ?>" class="regular-text" placeholder="z.B. chat.deine-domain.com"></td>
                </tr>
                <tr>
                    <th>Port</th>
                    <td><input type="text" name="port" value="<?= esc_attr($port) ?>" class="small-text" placeholder="z.B. 3000 oder 443"></td>
                </tr>
                <tr>
                    <th>Modell</th>
                    <td><input type="text" name="model" value="<?= esc_attr($model) ?>" class="regular-text" placeholder="z.B. llama3:latest"></td>
                </tr>

                <tr>
                    <th>Bot-Name</th>
                    <td><input type="text" name="bot_name" value="<?= esc_attr($bot_name) ?>" class="regular-text" placeholder="z.B. KI-Assistent" /></td>
                </tr>

                <tr>
                    <th>API-Key</th>
                    <td>
                        <input type="password" name="api_key" value="<?= esc_attr($api_key) ?>" class="regular-text" autocomplete="off" placeholder="owk_..." />
                        <p class="description"><strong>Optional:</strong> OpenWebUI → Settings → API → Generate Key</p>
                    </td>
                </tr>

                <tr>
                    <th>Willkommensnachricht</th>
                    <td><textarea name="welcome" rows="4" class="large-text"><?= esc_textarea($welcome) ?></textarea></td>
                </tr>
            </table>
            <?php submit_button('Speichern', 'primary', 'save'); ?>
        </form>

        <hr>
        <form method="post">
            <input type="hidden" name="crawl" value="1">
            <?php submit_button('Jetzt NEU crawlen', 'secondary'); ?>
        </form>

        <p><strong>API-URL:</strong> <code><?= esc_html(owc_get_api_url()) ?></code></p>
    </div>
    <?php
}
