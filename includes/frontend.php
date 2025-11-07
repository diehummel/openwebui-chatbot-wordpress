<?php
if (!defined('ABSPATH')) exit;

add_action('wp_ajax_owc_chat', 'owc_chat');
add_action('wp_ajax_nopriv_owc_chat', 'owc_chat');

// === NEU: Wetter-Fallback (via kostenlose OpenWeatherMap API) ===
function owc_get_weather($location = 'Pressbaum,AT') {
    $api_key = 'dein_openweather_api_key';  // Ersetze durch echten Key (kostenlos bei openweathermap.org)
    if (empty($api_key) || $api_key === 'dein_openweather_api_key') {
        return "Wetter-Info nicht verfügbar (API-Key fehlt). Probiere wetter.at!";
    }
    
    $url = "https://api.openweathermap.org/data/2.5/weather?q=" . urlencode($location) . "&appid=" . $api_key . "&units=metric&lang=de";
    $res = wp_remote_get($url, ['timeout' => 10]);
    
    if (is_wp_error($res)) return "Verbindung zum Wetter-Service fehlgeschlagen.";
    
    $body = wp_remote_retrieve_body($res);
    $data = json_decode($body, true);
    
    if (isset($data['weather'][0]['description']) && isset($data['main']['temp'])) {
        $temp = round($data['main']['temp']);
        $desc = ucfirst($data['weather'][0]['description']);
        $feels = round($data['main']['feels_like']);
        return "Heute in $location: $desc, $temp°C (gefühlt $feels°C). Wind: " . round($data['wind']['speed']) . " km/h.";
    }
    
    return "Wetterdaten für $location nicht gefunden.";
}

// === VERBESSERT: Keywords + Schwarze Liste für externe Themen ===
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

// === NEU: Prüfe auf externe Themen (Wetter, News, etc.) ===
function owc_is_external_topic($msg) {
    $external_keywords = [
        'wetter', 'news', 'aktien', 'kurs', 'ergebnis', 'spiel', 'rezept', 'reise', 'flug'
    ];
    $lower_msg = strtolower($msg);
    foreach ($external_keywords as $kw) {
        if (strpos($lower_msg, $kw) !== false) return true;
    }
    return false;
}

// Hilfsfunktion: Einfaches Stemming
function owc_simple_stem($word) {
    $word = strtolower(trim($word));
    if (strlen($word) < 3) return $word;
    $word = preg_replace('/(s|es|en|ung|lich)$/i', '', $word);
    return $word;
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

    // === NEU: Externe Themen abfangen ===
    if (owc_is_external_topic($msg)) {
        if (strpos(strtolower($msg), 'wetter') !== false) {
            preg_match('/wetter\s+(in\s+)?([a-zA-ZäöüÄÖÜ\s\-]+)/i', $msg, $matches);
            $location = trim($matches[2] ?? 'Pressbaum,AT');
            $weather = owc_get_weather($location);
            wp_send_json_success($weather);
            return;
        }
        // Füge mehr Fälle hinzu, z.B. für News: wp_send_json_success("Aktuelle News: Schau bei orf.at!");
        wp_send_json_success("Das ist ein externes Thema (z.B. News/Wetter). Ich helfe bei Website-Inhalten – frag nach Artikeln!");
        return;
    }

    $site = get_option('owc_site', []);
    if (empty($site)) { owc_crawl(); $site = get_option('owc_site', []); }

    $words = owc_get_relevant_keywords($msg);

    // === VERBESSERT: Nur Top 1 Match (kürzer) ===
    $matches = [];
    foreach ($site as $p) {
        $text = strtolower($p['title'] . ' ' . $p['content'] . ' ' . ($p['excerpt'] ?? ''));
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
                'title' => $p['title'],
                'excerpt' => substr(strip_tags($p['content']), 0, 50) . '...'
            ];
        }
    }

    usort($matches, function($a, $b) { return $b['score'] <=> $a['score']; });
    $top_match = !empty($matches) ? $matches[0] : null;

    $local_context = '';
    if ($top_match) {
        $local_context = "Lokaler Artikel: \"{$top_match['title']}\"\nURL: {$top_match['url']}\nAuszug: {$top_match['excerpt']}\n\n";
    } else {
        $local_context = "Keine passende lokale Seite gefunden.\n";
    }

    $system = "Du bist ein hilfreicher Website-Assistent.\nVERWENDE den lokalen Kontext, falls vorhanden – erwähne Artikel explizit!\nAntworte kurz auf Deutsch.";
    $user_message = $msg . "\n\nKontext: " . $local_context;

    $prompt_length = strlen($system . $user_message);
    if ($prompt_length > 2000) {
        error_log("OWC Warn: Prompt zu lang ($prompt_length Zeichen) – Kontext gekürzt.");
        $user_message = $msg . "\n\nKontext: " . $local_context . "(Vollständiger Inhalt zu lang – siehe Link oben.)";
    }

    $api_key = get_option('owc_api_key', '');

    $headers = ['Content-Type' => 'application/json'];
    if (!empty($api_key)) {
        $headers['Authorization'] = 'Bearer ' . $api_key;
    }

    $api_url = owc_get_api_url();
    error_log("OWC Debug: Calling $api_url | Prompt-Länge: $prompt_length | Modell: " . get_option('owc_model', ''));

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
        'timeout' => 120
    ]);

    if (is_wp_error($res)) {
        $error_msg = $res->get_error_message();
        error_log("OWC WP_Error: $error_msg");
        wp_send_json_error('OpenWebUI-Verbindung fehlgeschlagen: ' . $error_msg . '. Überprüfe URL und Server.');
    }

    $code = wp_remote_retrieve_response_code($res);
    $body = wp_remote_retrieve_body($res);
    error_log("OWC Response: Code $code | Body-Start: " . substr($body, 0, 200));

    if ($code !== 200) {
        $error_detail = "HTTP $code: " . substr($body, 0, 500);
        if ($code == 413 || $code == 500) $error_detail .= ' (Möglicherweise zu langer Prompt – versuche kürzere Query.)';
        elseif ($code == 401) $error_detail .= ' (Ungültiger API-Key – neu generieren?)';
        elseif ($code == 404) $error_detail .= ' (Falscher Endpoint – checke OpenWebUI-URL)';
        wp_send_json_error("OpenWebUI-Fehler: $error_detail");
    }

    $json = json_decode($body, true);
    $answer = $json['choices'][0]['message']['content'] ?? 'Oops – keine Antwort erhalten.';

    // === Link anhängen (nur wenn Match) ===
    if ($top_match) {
        $link_html = '<a href="' . esc_url($top_match['url']) . '" target="_blank" rel="noopener" style="color:#0073aa; font-weight:bold; text-decoration:underline;">' . esc_html($top_match['title']) . '</a>';
        $answer .= "\n\nMehr dazu: " . $link_html;
        
        // === WICHTIG: URL aus dem Text entfernen, damit preg_replace sie nicht nochmal verlinkt ===
        $answer = str_replace($top_match['url'], '', $answer);
    }

    // === Nur URLs verlinken, die NICHT schon in einem <a> sind ===
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
            'content' => wp_strip_all_tags($p->post_content),
            'excerpt' => wp_strip_all_tags($p->post_excerpt ?: wp_trim_words($p->post_content, 50, '...'))
        ];
    }
    update_option('owc_site', $data);
    return count($data);
}
