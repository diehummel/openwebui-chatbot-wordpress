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
        // === NEU: API-Key speichern ===
        update_option('owc_api_key', sanitize_text_field($_POST['api_key']));
        echo '<div class="notice notice-success"><p>Einstellungen gespeichert!</p></div>';
    }

    if (isset($_POST['crawl'])) {
        $count = owc_crawl();
        echo '<div class="notice notice-success"><p>' . $count . ' Seiten NEU gecrawlt!</p></div>';
    }

    $protocol = get_option('owc_protocol', 'https://');
    $host     = get_option('owc_host', 'chat.hummel-web.at');
    $port     = get_option('owc_port', '443');
    $model    = get_option('owc_model', 'gemma3:latest');
    $welcome  = get_option('owc_welcome', "Hallo! Ich bin dein KI-Assistent.\nFrag mich alles über diese Website!");
    // === NEU: API-Key laden ===
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
                    <td><input type="text" name="host" value="<?= esc_attr($host) ?>" class="regular-text" placeholder="chat.hummel-web.at"></td>
                </tr>
                <tr>
                    <th>Port</th>
                    <td><input type="text" name="port" value="<?= esc_attr($port) ?>" class="small-text" placeholder="443"></td>
                </tr>
                <tr>
                    <th>Modell</th>
                    <td><input type="text" name="model" value="<?= esc_attr($model) ?>" class="regular-text" placeholder="gemma3:latest"></td>
                </tr>

                <!-- === NEU: API-Key Feld === -->
                <tr>
                    <th>API-Key</th>
                    <td>
                        <input type="password" name="api_key" value="<?= esc_attr($api_key) ?>" class="regular-text" autocomplete="off" placeholder="z.B. owk_abc123..." />
                        <p class="description">
                            <strong>Optional:</strong> In OpenWebUI → <em>Settings → API → Generate Key</em>
                        </p>
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
