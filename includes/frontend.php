<?php
if (!defined('ABSPATH')) exit;

add_action('wp_ajax_owc_chat', 'owc_chat');
add_action('wp_ajax_nopriv_owc_chat', 'owc_chat');

// === VERBESSERT: Stoppwörter-Filter + Keyword-Extraktion ===
function owc_get_relevant_keywords($msg) {
    $stopwords = ['ich', 'suche', 'etwas', 'über', 'zu', 'das', 'der', 'die', 'und', 'oder', 'in', 'auf', 'mit', 'für', 'von', 'ist', 'bin', 'sei', 'hab', 'habe']; // Deutsch-Stoppwörter
    $words = preg_split('/\s+/', strtolower($msg));
    $relevant = [];
    $synonyms = [
        'pagespeed' => ['page speed', 'pagespeedinsights', 'ladezeit', 'performance', 'optimierung'],
        'tutorial' => ['anleitung', 'guide', 'hilfe', 'tunen'],
        // Erweitere bei Bedarf
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

// Hilfsfunktion: Einfaches Stemming (für Web-Begriffe)
function owc_simple_stem($word) {
    $word = strtolower(trim($word));
    if (strlen($word) < 3) return $word;
    $word = preg_replace('/(s|es|en|ung|lich)$/i', '', $word); // Einfache Endungs-Entfernung
    return $word;
}

function owc_get_api_url() {
    $protocol = get_option('owc_protocol', 'https://');
    $host     = get_option('owc_host', '');
    $port     = get_option('owc_port', '');
    return rtrim($protocol . $host . ':' . $port, ':') . '/api/v1/chat/completions';
}

function owc_chat() {
    check_ajax_referer('owc', 'nonce');
    $msg = sanitize_text_field($_POST['msg']);

    $site = get_option('owc_site', []);
    if (empty($site)) { owc_crawl(); $site = get_option('owc_site', []); }

    // === VERBESSERT: Nur relevante Keywords ===
    $words = owc_get_relevant_keywords($msg);

    // === Mehrere Matches sammeln (Top 2) ===
    $matches = [];
    foreach ($site as $p) {
        $text = strtolower($p['title'] . ' ' . $p['content'] . ' ' . ($p['excerpt'] ?? ''));
        $score = 0;
        foreach ($words as $w) {
            $stem_w = owc_simple_stem($w);
            $score += substr_count($text, $w) * 20;  // Höheres Basis-Gewicht
            $score += substr_count($text, $stem_w) * 10;
            if (stripos($p['title'], $w) !== false) $score += 200;  // Starke Title-Priorität
            if (stripos($text, $w) !== false) $score += 80;  // Content-Boost
        }
        if ($score > 15) {  // Niedriger Threshold für bessere Treffer
            $matches[] = [
                'score' => $score,
                'url' => get_permalink($p['id']) ?: '#',
                'title' => $p['title'],
                'excerpt' => $p['excerpt'] ?? substr(strip_tags($p['content']), 0, 150) . '...'
            ];
        }
    }

    // === Top-Matches sortieren ===
    usort($matches, function($a, $b) { return $b['score'] <=> $a['score']; });
    $top_matches = array_slice($matches, 0, 2);  // Bis zu 2 Links

    $local_context = '';
    if (!empty($top_matches)) {
        foreach ($top_matches as $match) {
            $local_context .= "Lokaler Artikel: \"{$match['title']}\"\nURL: {$match['url']}\nAuszug: {$match['excerpt']}\n\n";
        }
    } else {
        $local_context = "Keine passende lokale Seite gefunden. Antworte allgemein zu: $msg\n";
    }

    // === Stärkerer Prompt ===
    $system = "Du bist ein hilfreicher Website-Assistent für diese WordPress-Seite.\n" .
              "VERWENDE IMMER den lokalen Kontext – baue deine Antwort darauf auf und erwähne die Artikel explizit!\n" .
              "Halte Antworten kurz und relevant. Füge Links ein, wo sinnvoll.\n" .
              $local_context .
              "User-Frage: $msg\nAntworte auf Deutsch.";

    // === API-Key ===
    $api_key = get_option('owc_api_key', '');

    $headers = ['Content-Type' => 'application/json'];
    if (!empty($api_key)) {
        $headers['Authorization'] = 'Bearer ' . $api_key;
    }

    $res = wp_remote_post(owc_get_api_url(), [
        'headers' => $headers,
        'body' => json_encode([
            'model' => get_option('owc_model', ''),
            'messages' => [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user', 'content' => $msg]
            ],
            'temperature' => 0.7,
            'stream' => false
        ]),
        'timeout' => 90
    ]);

    if (is_wp_error($res)) {
        wp_send_json_error('OpenWebUI offline: ' . $res->get_error_message());
    }

    $code = wp_remote_retrieve_response_code($res);
    $body = wp_remote_retrieve_body($res);
    if ($code !== 200) {
        wp_send_json_error("Fehler $code: $body");
    }

    $json = json_decode($body, true);
    $answer = $json['choices'][0]['message']['content'] ?? 'Oops';

    // === VERBESSERT: Links zu Top-Matches anhängen ===
    $link_text = '';
    if (!empty($top_matches)) {
        foreach ($top_matches as $match) {
            $link_text .= "\n\nMehr dazu: <a href=\"{$match['url']}\" target=\"_blank\" rel=\"noopener\" style=\"color:#0073aa; font-weight:bold; text-decoration:underline;\">{$match['title']}</a>";
        }
        $answer .= $link_text;
    }

    $answer = preg_replace(
        '/(https?:\/\/[^\s\)]+)/',
        '<a href="$1" target="_blank" rel="noopener" style="color:#0073aa; text-decoration:underline;">$1</a>',
        $answer
    );

    wp_send_json_success($answer);
}

// === Crawl (unverändert, aber Excerpt hilft) ===
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
            'content' => wp_strip_all_tags($p->post_content),
            'excerpt' => wp_strip_all_tags($p->post_excerpt ?: wp_trim_words($p->post_content, 50, '...'))
        ];
    }
    update_option('owc_site', $data);
    return count($data);
}
