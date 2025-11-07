<?php
if (!defined('ABSPATH')) exit;

// === KORREKT: add_action (nicht add_add_action!) ===
add_action('wp_ajax_owc_chat', 'owc_chat');
add_action('wp_ajax_nopriv_owc_chat', 'owc_chat');

// === Stemming ===
function owc_simple_stem($word) {
    $word = strtolower(trim($word));
    if (strlen($word) < 3) return $word;
    return preg_replace('/(s|es|en|ung|lich)$/i', '', $word);
}

// === Keywords ===
function owc_get_relevant_keywords($msg) {
    $stopwords = [
        'ich', 'suche', 'etwas', 'über', 'zu', 'das', 'der', 'die', 'und', 'oder', 'in', 'auf', 'mit', 'für', 'von',
        'ist', 'bin', 'sei', 'hab', 'habe', 'mir', 'dir', 'wie', 'geht', 'es', 'du', 'wer', 'bist', 'dein', 'mein'
    ];
    $words = preg_split('/\s+/', strtolower($msg));
    $relevant = [];
    $synonyms = [
        'pagespeed' => ['page speed', 'pagespeedinsights', 'ladezeit', 'performance', 'optimierung'],
        'tutorial' => ['anleitung', 'guide', 'hilfe', 'tunen'],
    ];
    foreach ($words as $w) {
        if (strlen($w) < 3 || in_array($w, $stopwords)) continue;
        $relevant[] = $w;
        if (isset($synonyms[$w])) {
            $relevant = array_merge($relevant, $synonyms[$w]);
        }
    }
    return array_unique($relevant);
}

// === Externe Themen ===
function owc_is_external_topic($msg) {
    $external = ['wetter', 'news', 'aktien', 'kurs', 'ergebnis', 'spiel', 'rezept', 'reise', 'flug'];
    $lower = strtolower($msg);
    foreach ($external as $kw) {
        if (strpos($lower, $kw) !== false) return true;
    }
    return false;
}

// === API URL – DYNAMISCH ===
function owc_get_api_url() {
    $protocol = get_option('owc_protocol', 'http://');
    $host     = get_option('owc_host', 'localhost');
    $port     = get_option('owc_port', '8080');
    return rtrim($protocol . $host . ':' . $port, ':') . '/api/v1/chat/completions';
}

// === Sortierfunktion (PHP 8.4 kompatibel) ===
function owc_sort_matches($a, $b) {
    return $b['score'] <=> $a['score'];
}

// === Hauptfunktion ===
function owc_chat() {
    check_ajax_referer('owc', 'nonce');
    $msg = sanitize_text_field($_POST['msg']);

    // === Externe Themen ===
    if (owc_is_external_topic($msg)) {
        wp_send_json_success("Ich kenne nur Inhalte dieser Website. Frag nach Artikeln!");
        return;
    }

    $site = get_option('owc_site', []);
    if (empty($site)) {
        owc_crawl();
        $site = get_option('owc_site', []);
    }

    $words = owc_get_relevant_keywords($msg);

    // === KEINE KEYWORDS → KEIN API-CALL! ===
    if (empty($words)) {
        wp_send_json_success("Frag nach einem Thema aus der Website – z.B. 'Pagespeed' oder 'WordPress'!");
        return;
    }

    $matches = [];
    foreach ($site as $p) {
        $title_lower = strtolower($p['title']);
        $title_match = false;
        foreach ($words as $w) {
            if (stripos($title_lower, $w) !== false) {
                $title_match = true;
                break;
            }
        }
        if (!$title_match) continue;

        $content_lower = strtolower(substr($p['content'], 0, 1500));
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

    // === SORTIEREN ===
    usort($matches, 'owc_sort_matches');
    $top_match = !empty($matches) ? $matches[0] : null;

    // === MODELL & API-Key aus Admin ===
    $model = trim(get_option('owc_model', 'gemma3:4b'));
    $api_key = trim(get_option('owc_api_key', ''));

    $headers = ['Content-Type' => 'application/json'];
    if (!empty($api_key)) {
        $headers['Authorization'] = 'Bearer ' . $api_key;
    }

    // === Kurzer Prompt ===
    $system = "Du bist ein KI-Assistent für diese Website. Antworte kurz und hilfreich.";
    $context = $top_match
        ? "Artikel: {$top_match['title']} – {$top_match['url']}"
        : "Kein passender Artikel.";
    $user_message = "$msg\nKontext: $context";

    $api_url = owc_get_api_url();
    error_log("OWC: API Call → $api_url | Modell: $model");

    $res = wp_remote_post($api_url, [
        'headers' => $headers,
        'body' => json_encode([
            'model' => $model,
            'messages' => [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user', 'content' => $user_message]
            ],
            'temperature' => 0.7,
            'max_tokens' => 120,
            'stream' => false
        ], JSON_UNESCAPED_UNICODE),
        'timeout' => 300
    ]);

    // === Fehlerbehandlung ===
    if (is_wp_error($res)) {
        $err = $res->get_error_message();
        error_log("OWC WP_Error: $err");
        wp_send_json_error("Verbindung fehlgeschlagen: $err");
        return;
    }

    $code = wp_remote_retrieve_response_code($res);
    $body = wp_remote_retrieve_body($res);
    error_log("OWC Response: Code $code | Body: " . substr($body, 0, 300));

    if ($code !== 200) {
        wp_send_json_error("OpenWebUI-Fehler $code");
        return;
    }

    $json = json_decode($body, true);
    if (!isset($json['choices'][0]['message']['content'])) {
        wp_send_json_error("KI hat nicht geantwortet.");
        return;
    }

    $answer = $json['choices'][0]['message']['content'];

    // === Link anhängen ===
    if ($top_match) {
        $link = '<a href="' . esc_url($top_match['url']) . '" target="_blank" rel="noopener" style="color:#0073aa; font-weight:bold; text-decoration:underline;">' . esc_html($top_match['title']) . '</a>';
        $answer .= "\n\nMehr dazu: $link";
        $answer = str_replace($top_match['url'], '', $answer);
    }

    // === URLs verlinken ===
    $answer = preg_replace(
        '/(https?:\/\/[^\s\)<]+)(?![^<]*<\/a>)/',
        '<a href="$1" target="_blank" rel="noopener" style="color:#0073aa; text-decoration:underline;">$1</a>',
        $answer
    );

    wp_send_json_success($answer);
}

// === Crawl ===
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
