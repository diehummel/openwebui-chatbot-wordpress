<?php
if (!defined('ABSPATH')) exit;

add_action('wp_ajax_owc_chat', 'owc_chat');
add_action('wp_ajax_nopriv_owc_chat', 'owc_chat');

// === Einfaches Stemming (für bessere Treffer) ===
function owc_simple_stem($word) {
    $word = strtolower(trim($word));
    if (strlen($word) < 3) return $word;
    return preg_replace('/(s|es|en|ung|lich)$/i', '', $word);
}

// === Relevante Wörter extrahieren (ohne Beispiele) ===
function owc_get_relevant_keywords($msg) {
    $stopwords = ['ich', 'du', 'er', 'sie', 'es', 'der', 'die', 'das', 'und', 'oder', 'in', 'auf', 'mit', 'für', 'von', 'zu', 'ist', 'bin', 'habe', 'hat', 'sein', 'war', 'wird', 'wie', 'was', 'wo', 'wann', 'wer', 'warum', 'dass', 'ein', 'eine', 'einen', 'einem', 'einer', 'eines'];
    $words = preg_split('/\s+/', strtolower($msg));
    $relevant = [];

    foreach ($words as $w) {
        if (strlen($w) < 3 || in_array($w, $stopwords)) continue;
        $relevant[] = $w;
    }

    return array_unique($relevant);
}

// === Robuste API-URL ===
function owc_get_api_url() {
    $protocol = trim(get_option('owc_protocol', 'http://'));
    $host = trim(get_option('owc_host', 'localhost'));
    $port = trim(get_option('owc_port', '8080'));

    if (!preg_match('#^https?://#i', $protocol)) {
        $protocol = 'http://';
    }

    $url = rtrim($protocol . $host, '/');
    if (!empty($port) && $port !== '80' && $port !== '443') {
        $url .= ':' . $port;
    }
    return $url . '/api/v1/chat/completions';
}

// === Crawl (einmalig) ===
function owc_crawl() {
    $posts = get_posts([
        'numberposts' => -1,
        'post_status' => ['publish', 'private'],
        'post_type' => 'any'
    ]);

    $data = [];
    foreach ($posts as $p) {
        $data[] = [
            'id' => $p->ID,
            'title' => $p->post_title,
            'content' => wp_strip_all_tags($p->post_content)
        ];
    }
    update_option('owc_site', $data);
    return count($data);
}

// === Hauptfunktion: Jede Frage → OpenWebUI + optionaler Link ===
function owc_chat() {
    // === DEBUG (optional, später deaktivieren) ===
    error_log('OWC: AJAX gestartet. POST: ' . print_r($_POST, true));

    // === NONCE ===
    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'owc')) {
        error_log('OWC: NONCE FEHLER');
        wp_send_json_error('Sicherheitsprüfung fehlgeschlagen.');
        return;
    }

    $msg = sanitize_text_field($_POST['msg'] ?? '');
    if (empty($msg)) {
        wp_send_json_error('Leere Nachricht.');
        return;
    }

    // === Crawl falls leer ===
    $site = get_option('owc_site', []);
    if (empty($site)) {
        owc_crawl();
        $site = get_option('owc_site', []);
    }

    // === Lokalen Artikel suchen ===
    $words = owc_get_relevant_keywords($msg);
    $top_match = null;

    if (!empty($words) && !empty($site)) {
        $matches = [];
        foreach ($site as $p) {
            $title_lower = strtolower($p['title']);
            $content_lower = strtolower(substr($p['content'], 0, 2000));
            $text = $title_lower . ' ' . $content_lower;

            $score = 0;
            foreach ($words as $w) {
                $stem_w = owc_simple_stem($w);
                $score += substr_count($text, $w) * 20;
                $score += substr_count($text, $stem_w) * 10;
                if (stripos($title_lower, $w) !== false) $score += 200;
            }

            if ($score > 15) {
                $matches[] = [
                    'score' => $score,
                    'url' => get_permalink($p['id']) ?: '#',
                    'title' => $p['title']
                ];
            }
        }

        if (!empty($matches)) {
            usort($matches, fn($a, $b) => $b['score'] <=> $a['score]);
            $top_match = $matches[0];
        }
    }

    // === System-Prompt für KI ===
    $system_prompt = "Du bist ein KI-Assistent für diese Website. Antworte kurz, hilfreich und auf Deutsch. "
                   . "Wenn ein passender Artikel existiert, verweise darauf mit dem Link. "
                   . "Wenn nicht, antworte allgemein – du darfst auf alle Themen eingehen.";

    $context = $top_match
        ? "Es gibt einen passenden Artikel:\nTitel: {$top_match['title']}\nLink: {$top_match['url']}"
        : "Kein passender Artikel auf dieser Website gefunden.";

    $user_message = "Frage: $msg\n\nKontext zur Website: $context";

    // === API-Call ===
    $model = trim(get_option('owc_model', 'gemma3:4b'));
    $api_key = trim(get_option('owc_api_key', ''));

    $headers = ['Content-Type' => 'application/json'];
    if (!empty($api_key)) {
        $headers['Authorization'] = 'Bearer ' . $api_key;
    }

    $api_url = owc_get_api_url();
    error_log("OWC: API → $api_url | Modell: $model");

    $res = wp_remote_post($api_url, [
        'headers' => $headers,
        'body' => json_encode([
            'model' => $model,
            'messages' => [
                ['role' => 'system', 'content' => $system_prompt],
                ['role' => 'user', 'content' => $user_message]
            ],
            'temperature' => 0.7,
            'max_tokens' => 200,
            'stream' => false
        ], JSON_UNESCAPED_UNICODE),
        'timeout' => 45,
        'sslverify' => false
    ]);

    // === Fehler ===
    if (is_wp_error($res)) {
        $err = $res->get_error_message();
        error_log("OWC WP_Error: $err");
        wp_send_json_error("Verbindung fehlgeschlagen: $err");
        return;
    }

    $code = wp_remote_retrieve_response_code($res);
    $body = wp_remote_retrieve_body($res);

    if ($code !== 200) {
        error_log("OWC HTTP-Fehler: $code | $body");
        wp_send_json_error("OpenWebUI-Fehler $code");
        return;
    }

    $json = json_decode($body, true);
    if (!$json || !isset($json['choices'][0]['message']['content'])) {
        error_log("OWC: Ungültige JSON-Antwort: " . substr($body, 0, 300));
        wp_send_json_error("KI hat nicht geantwortet.");
        return;
    }

    $answer = trim($json['choices'][0]['message']['content']);

    // === Link anhängen, falls nicht im Text ===
    if ($top_match && stripos($answer, $top_match['url']) === false) {
        $link = '<a href="' . esc_url($top_match['url']) . '" target="_blank" rel="noopener" style="color:#0073aa; font-weight:bold; text-decoration:underline;">' . esc_html($top_match['title']) . '</a>';
        $answer .= "\n\nPassender Artikel: $link";
    }

    // === URLs verlinken ===
    $answer = preg_replace(
        '/(https?:\/\/[^\s\)<]+)(?![^<]*<\/a>)/',
        '<a href="$1" target="_blank" rel="noopener" style="color:#0073aa; text-decoration:underline;">$1</a>',
        $answer
    );

    wp_send_json_success($answer);
}
