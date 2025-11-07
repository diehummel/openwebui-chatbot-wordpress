<?php
if (!defined('ABSPATH')) exit;

add_action('wp_ajax_owc_chat', 'owc_chat');
add_action('wp_ajax_nopriv_owc_chat', 'owc_chat');

// === Keywords ===
function owc_get_relevant_keywords($msg) {
    $stopwords = ['ich', 'suche', 'etwas', 'über', 'zu', 'das', 'der', 'die', 'und', 'oder', 'in', 'auf', 'mit', 'für', 'von', 'ist', 'bin', 'sei', 'hab', 'habe'];
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

// === Externe Themen (optional – behalte nur, wenn du Wetter willst) ===
function owc_is_external_topic($msg) {
    $external = ['wetter', 'news', 'aktien', 'kurs', 'ergebnis', 'spiel', 'rezept', 'reise', 'flug'];
    $lower = strtolower($msg);
    foreach ($external as $kw) {
        if (strpos($lower, $kw) !== false) return true;
    }
    return false;
}

function owc_simple_stem($word) {
    $word = strtolower(trim($word));
    if (strlen($word) < 3) return $word;
    return preg_replace('/(s|es|en|ung|lich)$/i', '', $word);
}

function owc_get_api_url() {
    $protocol = get_option('owc_protocol', 'https://');
    $host     = get_option('owc_host', '');
    $port     = get_option('owc_port', '');
    return rtrim($protocol . $host . ':' . $port, ':') . '/api/chat/completions';
}

function owc_chat() {
    check_ajax_referer('owc', 'nonce');
    $msg = sanitize_text_field($_POST['msg']);

    // === Externe Themen abfangen ===
    if (owc_is_external_topic($msg)) {
        wp_send_json_success("Das ist ein externes Thema (z.B. Wetter, News). Ich helfe nur bei Inhalten dieser Website!");
        return;
    }

    $site = get_option('owc_site', []);
    if (empty($site)) { owc_crawl(); $site = get_option('owc_site', []); }

    $words = owc_get_relevant_keywords($msg);

    // === Nur Top 1 Match ===
    $matches = [];
    foreach ($site as $p) {
        $text = strtolower($p['title'] . ' ' . $p['content']);
        $score = 0;
        foreach ($words as $w) {
            $stem_w = owc_simple_stem($w);
            $score += substr_count($text, $w) * 20;
            $score += substr_count($text, $stem_w) * 10;
            if (stripos($p['title'], $w) !== false) $score += 200;
            if (stripos($text, $w) !== false) $score += 80;
        }
        if ($score > 15) {
            $matches[] = [
                'score' => $score,
                'url' => get_permalink($p['id']) ?: '#',
                'title' => $p['title']
            ];
        }
    }

    usort($matches, function($a, $b) { return $b['score'] <=> $a['score']; });
    $top_match = !empty($matches) ? $matches[0] : null;

    // === KURZER Prompt – nur Titel + URL ===
    $system = "Du bist ein hilfreicher Website-Assistent. Antworte kurz auf Deutsch.";
    $context = $top_match
        ? "Relevanter Artikel: \"{$top_match['title']}\" – Link: {$top_match['url']}"
        : "Keine passende Seite gefunden.";
    
    $user_message = "$msg\n\nKontext: $context";

    $api_key = get_option('owc_api_key', '');
    $headers = ['Content-Type' => 'application/json'];
    if (!empty($api_key)) {
        $headers['Authorization'] = 'Bearer ' . $api_key;
    }

    $api_url = owc_get_api_url();
    $prompt_length = strlen($system . $user_message);
    error_log("OWC Debug: Prompt-Länge: $prompt_length Zeichen");

    $res = wp_remote_post($api_url, [
        'headers' => $headers,
        'body' => json_encode([
            'model' => get_option('owc_model', ''),
            'messages' => [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user', 'content' => $user_message]
            ],
            'temperature' => 0.7,
            'stream' => false
        ], JSON_UNESCAPED_UNICODE),
        'timeout' => 300
    ]);

    if (is_wp_error($res)) {
        wp_send_json_error('Verbindung fehlgeschlagen: ' . $res->get_error_message());
    }

    $code = wp_remote_retrieve_response_code($res);
    $body = wp_remote_retrieve_body($res);

    if ($code !== 200) {
        wp_send_json_error("OpenWebUI-Fehler $code");
    }

    $json = json_decode($body, true);
    $answer = $json['choices'][0]['message']['content'] ?? 'Keine Antwort.';

    // === Link anhängen ===
    if ($top_match) {
        $link = '<a href="' . esc_url($top_match['url']) . '" target="_blank" rel="noopener" style="color:#0073aa; font-weight:bold; text-decoration:underline;">' . esc_html($top_match['title']) . '</a>';
        $answer .= "\n\nMehr dazu: $link";
        $answer = str_replace($top_match['url'], '', $answer);
    }

    $answer = preg_replace(
        '/(https?:\/\/[^\s\)<]+)(?![^<]*<\/a>)/',
        '<a href="$1" target="_blank" rel="noopener" style="color:#0073aa; text-decoration:underline;">$1</a>',
        $answer
    );

    wp_send_json_success($answer);
}

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
