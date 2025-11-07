<?php
if (!defined('ABSPATH')) exit;

add_action('wp_ajax_owc_chat', 'owc_chat');
add_action('wp_ajax_nopriv_owc_chat', 'owc_chat');

// Hilfsfunktion: Einfaches Stemming (entfernt Endungen für besseres Matching)
function owc_simple_stem($word) {
    $word = strtolower(trim($word));
    if (strlen($word) < 3) return $word;
    // Einfache Regeln für Deutsch/Englisch (erweiterbar)
    $word = preg_replace('/(ing|ed|es|s|en|de|te|ung|lich|bar)$/i', '', $word);
    return $word;
}

// Hilfsfunktion: Erweitere Words um Stems + Synonyme (einfach, erweiterbar)
function owc_get_expanded_words($msg) {
    $words = preg_split('/\s+/', strtolower($msg));
    $expanded = [];
    $synonyms = [
        'tutorial' => ['anleitung', 'guide', 'hilfe'],
        'wordpress' => ['wp', 'blog'],
        // Füge hier mehr hinzu, z.B. aus deinem Content
    ];
    foreach ($words as $w) {
        if (strlen($w) < 3) continue;
        $stem = owc_simple_stem($w);
        $expanded[] = $w;
        $expanded[] = $stem;
        if (isset($synonyms[$w])) {
            $expanded = array_merge($expanded, $synonyms[$w]);
        }
    }
    return array_unique($expanded);
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

    // === VERBESSERT: Erweiterte Word-Extraktion ===
    $words = owc_get_expanded_words($msg);

    $best_url = $best_title = $best_excerpt = '';
    $best_score = 0;

    foreach ($site as $p) {
        // === VERBESSERT: Mehr Content (Title + Content + Excerpt) ===
        $text = strtolower($p['title'] . ' ' . $p['content'] . ' ' . ($p['excerpt'] ?? ''));
        
        $score = 0;
        foreach ($words as $w) {
            $score += substr_count($text, $w) * 15;  // Höheres Gewicht
            if (stripos($p['title'], $w) !== false) $score += 150;  // Stärkerer Title-Boost
            if (stripos($text, $w) !== false) $score += 50;  // Allgemeiner Boost
        }
        
        if ($score > $best_score) {
            $best_score = $score;
            $best_url = get_permalink($p['id']) ?: '#';
            $best_title = $p['title'];
            $best_excerpt = $p['excerpt'] ?? substr(strip_tags($p['content']), 0, 200) . '...';
        }
    }

    // === VERBESSERT: Niedrigerer Schwellenwert + Fallback ===
    $local_context = '';
    if ($best_score > 20) {  // Von 30 auf 20 gesenkt
        $local_context = "Lokaler Artikel: \"$best_title\"\nURL: $best_url\nAuszug: $best_excerpt\n\n";
    } else {
        $local_context = "Keine passende lokale Seite gefunden. Antworte allgemein zu: $msg\n";
    }

    // === VERBESSERT: Stärkerer RAG-Prompt (explizit Kontext nutzen) ===
    $system = "Du bist ein hilfreicher Website-Assistent für diese WordPress-Seite.\n" .
              "VERWENDE IMMER den folgenden lokalen Kontext, falls vorhanden – baue deine Antwort darauf auf!\n" .
              "Halte Antworten kurz und relevant. Füge Links ein, wo sinnvoll.\n" .
              $local_context .
              "User-Frage: $msg\nAntworte auf Deutsch.";

    // === API-Key (aus vorheriger Erweiterung) ===
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

    $answer = preg_replace(
        '/(https?:\/\/[^\s\)]+)/',
        '<a href="$1" target="_blank" rel="noopener" style="color:#0073aa; text-decoration:underline;">$1</a>',
        $answer
    );

    wp_send_json_success($answer);
}

// === VERBESSERT: Crawl erweitert um Excerpt ===
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
            'excerpt' => wp_strip_all_tags($p->post_excerpt ?: wp_trim_words($p->post_content, 50, '...'))  // Neu: Excerpt hinzufügen
        ];
    }
    update_option('owc_site', $data);
    return count($data);
}
