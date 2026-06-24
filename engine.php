<?php
/**
 * FP Autopilot — Motor de contenido server-side para futbolpasion.cl
 * --------------------------------------------------------------------
 * Corre por CRON en cPanel (no en la nube; el sandbox de la nube no puede
 * publicar). Lee RSS (el "cable" de noticias), elige TODAS las historias
 * frescas y no duplicadas, pide a Google Gemini (free) una nota ORIGINAL en
 * es-CL por cada una y las publica en WordPress con wp_insert_post.
 *
 * NO hay límite artificial de 1 nota: publica todas las nuevas (hay un techo
 * de seguridad anti-spam configurable: max_per_run).
 *
 * Cron sugerido (cada 30 min, 12:00–23:59 hora Chile):
 *   *\/30 12-23 * * *  /usr/local/bin/php /home/futbol/fp-engine/engine.php >> /home/futbol/fp-engine/cron.log 2>&1
 *
 * Requiere config.php junto a este archivo (ver config.sample.php).
 */

// ----------------------------------------------------------------------------
// 1) Configuración
// ----------------------------------------------------------------------------
$CONFIG_FILE = __DIR__ . '/config.php';
if (!file_exists($CONFIG_FILE)) {
    fwrite(STDERR, "[FP] Falta config.php (copia config.sample.php).\n");
    exit(1);
}
$cfg = require $CONFIG_FILE;
date_default_timezone_set($cfg['timezone'] ?? 'America/Santiago');

function fp_log($msg) {
    global $cfg;
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL;
    if (!empty($cfg['log_file'])) @file_put_contents($cfg['log_file'], $line, FILE_APPEND);
    echo $line;
}

// ----------------------------------------------------------------------------
// 2) Ventana horaria (defensa extra además del cron)
// ----------------------------------------------------------------------------
$hour  = (int) date('G');
$start = (int) ($cfg['window_start'] ?? 12);
$end   = (int) ($cfg['window_end'] ?? 24); // exclusivo (24 = hasta 23:59)
if ($hour < $start || $hour >= $end) { fp_log("Fuera de ventana ({$hour}h). Salgo."); exit(0); }

// ----------------------------------------------------------------------------
// 3) Bootstrap de WordPress (acceso directo a wp_insert_post)
// ----------------------------------------------------------------------------
if (empty($cfg['wp_load']) || !file_exists($cfg['wp_load'])) {
    fp_log("ERROR: no encuentro wp-load.php en '" . ($cfg['wp_load'] ?? '') . "'."); exit(1);
}
define('WP_USE_THEMES', false);
require $cfg['wp_load'];

// ----------------------------------------------------------------------------
// 4) Helpers
// ----------------------------------------------------------------------------
function fp_http_get($url, $timeout = 15) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 5,
        CURLOPT_TIMEOUT => $timeout, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_ENCODING => '',
        CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; FutbolPasionBot/1.0; +https://futbolpasion.cl)',
        CURLOPT_HTTPHEADER => ['Accept: */*', 'Accept-Language: es-CL,es;q=0.9'],
    ]);
    $body = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); $err = curl_error($ch); curl_close($ch);
    if ($body === false || $code >= 400) { fp_log("HTTP GET $code $url " . ($err ? "[$err]" : '')); return false; }
    return $body;
}

function fp_parse_feed($xmlStr, $sourceName = '') {
    $items = [];
    if (!$xmlStr) return $items;
    libxml_use_internal_errors(true);
    $xml = simplexml_load_string($xmlStr);
    if (!$xml) return $items;
    if (isset($xml->channel->item)) {                 // RSS 2.0
        foreach ($xml->channel->item as $it) {
            $title = trim((string) $it->title);
            $link  = trim((string) $it->link);
            $desc  = trim((string) $it->description);
            $src   = isset($it->source) ? (trim((string) $it->source) ?: $sourceName) : $sourceName;
            $ts    = ($d = (string) ($it->pubDate ?? '')) ? strtotime($d) : time();
            if ($title) $items[] = compact('title', 'link', 'ts', 'src', 'desc');
        }
    } elseif (isset($xml->entry)) {                    // Atom
        foreach ($xml->entry as $en) {
            $title = trim((string) $en->title);
            $link  = isset($en->link['href']) ? (string) $en->link['href'] : '';
            $desc  = trim((string) ($en->summary ?? $en->content ?? ''));
            $ts    = ($d = (string) ($en->updated ?? $en->published ?? '')) ? strtotime($d) : time();
            if ($title) $items[] = ['title' => $title, 'link' => $link, 'ts' => $ts, 'src' => $sourceName, 'desc' => $desc];
        }
    }
    return $items;
}

function fp_recent_titles($n = 60) {
    $q = new WP_Query(['post_type' => 'post', 'post_status' => 'publish', 'posts_per_page' => $n, 'no_found_rows' => true, 'fields' => 'ids']);
    $t = [];
    foreach ($q->posts as $pid) $t[] = get_the_title($pid);
    return $t;
}

function fp_state_load($file) { $d = file_exists($file) ? json_decode((string) file_get_contents($file), true) : []; return is_array($d) ? $d : []; }
function fp_state_save($file, $state) { if (count($state) > 800) $state = array_slice($state, -800); @file_put_contents($file, json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)); }

function fp_gemini_write($cfg, $cand, $recentTitles, $extra = '') {
    $model = $cfg['gemini_model'] ?? 'gemini-2.5-flash-lite';
    $key   = $cfg['gemini_api_key'] ?? '';
    if (!$key) { fp_log('ERROR: falta gemini_api_key.'); return null; }
    $slugs = 'primera-division, copa-chile, ascenso, la-roja, mercado, opinion';
    $ya = $recentTitles ? ("- " . implode("\n- ", array_slice($recentTitles, 0, 35))) : '(ninguno)';

    $prompt =
"Eres editor de Fútbol Pasión (futbolpasion.cl), medio de NOTICIAS DE FÚTBOL CHILENO. " .
"A partir de la HISTORIA-FUENTE, redacta una nota ORIGINAL en ESPAÑOL DE CHILE (es-CL), con tus propias palabras " .
"(NO copies frase por frase). No inventes datos, cifras ni declaraciones; si algo es incierto o rumor, márcalo como 'trascendido'. " .
"Tono editorial, profesional, sin clickbait.\n\n" .
"HISTORIA-FUENTE:\nTitular: {$cand['title']}\nResumen: {$cand['desc']}\nMedio: {$cand['src']}\nURL: {$cand['link']}\n" .
($extra ? "Contexto (extracto del artículo fuente):\n{$extra}\n" : '') .
"\nNOTAS YA PUBLICADAS (no repitas el mismo hecho; si ya está cubierta, devuelve skip=true):\n{$ya}\n\n" .
"Devuelve EXCLUSIVAMENTE un JSON con esta forma exacta:\n" .
"{\n" .
'  "skip": false,' . "\n" .
'  "title": "titular específico y atractivo, sin clickbait",' . "\n" .
'  "excerpt": "bajada de 1-2 frases",' . "\n" .
'  "content_html": "<p>3 a 5 párrafos en HTML. Último párrafo de atribución: <em>Fuente: NOMBRE — <a href=\"URL\" target=\"_blank\" rel=\"noopener nofollow\">dominio</a></em></p>",' . "\n" .
'  "category_slug": "uno de: ' . $slugs . '"' . "\n" .
"}\nSi la historia ya está cubierta o no es noticiosa, devuelve {\"skip\": true}.";

    $payload = json_encode(['contents' => [['parts' => [['text' => $prompt]]]], 'generationConfig' => ['temperature' => 0.7, 'responseMimeType' => 'application/json']], JSON_UNESCAPED_UNICODE);
    $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key=" . urlencode($key);
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_POSTFIELDS => $payload, CURLOPT_HTTPHEADER => ['Content-Type: application/json'], CURLOPT_TIMEOUT => 60, CURLOPT_CONNECTTIMEOUT => 15]);
    $res = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); $err = curl_error($ch); curl_close($ch);
    if ($res === false || $code >= 400) { fp_log("Gemini HTTP $code " . ($err ? "[$err] " : '') . substr((string) $res, 0, 300)); return null; }
    $data = json_decode($res, true);
    $text = $data['candidates'][0]['content']['parts'][0]['text'] ?? '';
    if (!$text) { fp_log('Gemini sin texto: ' . substr($res, 0, 300)); return null; }
    $article = json_decode($text, true);
    if (!is_array($article)) { fp_log('Gemini JSON inválido: ' . substr($text, 0, 300)); return null; }
    return $article;
}

// ----------------------------------------------------------------------------
// 5) Flujo principal
// ----------------------------------------------------------------------------
fp_log('=== Corrida del motor ===');

// 5.1 Reúne ítems de todos los feeds
$all = [];
foreach (($cfg['feeds'] ?? []) as $feed) {
    $items = fp_parse_feed(fp_http_get($feed['url']), $feed['name'] ?? '');
    fp_log('Feed ' . ($feed['name'] ?? $feed['url']) . ': ' . count($items) . ' ítems');
    $all = array_merge($all, $items);
}
if (!$all) { fp_log('Sin ítems. Salgo.'); exit(0); }

// 5.2 Frescura (últimas N horas) + orden por fecha desc
$cutoff = time() - ((int) ($cfg['freshness_hours'] ?? 24)) * 3600;
$all = array_values(array_filter($all, fn($i) => $i['ts'] >= $cutoff));
usort($all, fn($a, $b) => $b['ts'] <=> $a['ts']);
fp_log('Ítems frescos: ' . count($all));
if (!$all) { fp_log('Nada fresco. Salgo.'); exit(0); }

// 5.3 Estado + títulos ya publicados
$state   = fp_state_load($cfg['state_file']);
$recent  = fp_recent_titles(60);
$norm    = fn($s) => mb_strtolower(trim(preg_replace('/\s+/', ' ', (string) $s)));
$recentN = array_map($norm, $recent);

// 5.4 Candidatos NUEVOS (no procesados antes y no duplicados de lo publicado)
$cands = [];
foreach ($all as $it) {
    $idKey = md5($it['link'] ?: $it['title']);
    if (in_array($idKey, $state, true)) continue;
    $tn = $norm($it['title']); $dup = false;
    foreach ($recentN as $rt) { similar_text($tn, $rt, $pct); if ($pct >= 80) { $dup = true; break; } }
    if ($dup) { $state[] = $idKey; continue; }
    $it['idKey'] = $idKey; $cands[] = $it;
}
fp_state_save($cfg['state_file'], $state);
fp_log('Candidatos nuevos: ' . count($cands));
if (!$cands) { fp_log('Sin novedades. Salgo.'); exit(0); }

// 5.5 Publica TODAS las nuevas (techo de seguridad max_per_run, NO límite real de 1)
$maxRun = (int) ($cfg['max_per_run'] ?? 12);
$seenN  = $recentN;     // evita duplicados dentro de la misma corrida
$pub    = 0;
foreach ($cands as $cand) {
    if ($pub >= $maxRun) { fp_log("Techo $maxRun alcanzado; corto."); break; }

    // Contexto extra: lee el artículo fuente si es enlace directo
    $extra = ''; $host = parse_url($cand['link'], PHP_URL_HOST) ?: '';
    if ($cand['link'] && stripos($host, 'news.google.com') === false) {
        if ($html = fp_http_get($cand['link'], 12)) {
            $txt = preg_replace('/<script\b[^>]*>.*?<\/script>/is', ' ', $html);
            $txt = preg_replace('/<style\b[^>]*>.*?<\/style>/is', ' ', $txt);
            $txt = trim(preg_replace('/\s+/', ' ', strip_tags($txt)));
            if (mb_strlen($txt) > 200) $extra = mb_substr($txt, 0, 1800);
        }
    }

    $state[] = $cand['idKey']; fp_state_save($cfg['state_file'], $state); // marca como procesada siempre

    $art = fp_gemini_write($cfg, $cand, $recent, $extra);
    if (!$art || !empty($art['skip'])) { fp_log('skip/sin nota: ' . $cand['title']); continue; }
    if (empty($art['title']) || empty($art['content_html']) || empty($art['category_slug'])) { fp_log('campos faltantes; skip'); continue; }

    $atN = $norm($art['title']); $dup2 = false;
    foreach ($seenN as $pt) { similar_text($atN, $pt, $p2); if ($p2 >= 80) { $dup2 = true; break; } }
    if ($dup2) { fp_log('dup intra-corrida; skip: ' . $art['title']); continue; }

    $term = get_term_by('slug', sanitize_title($art['category_slug']), 'category');
    $postId = wp_insert_post([
        'post_title'    => wp_strip_all_tags($art['title']),
        'post_content'  => $art['content_html'],
        'post_excerpt'  => $art['excerpt'] ?? '',
        'post_status'   => 'publish',
        'post_author'   => (int) ($cfg['author_id'] ?? 1),
        'post_category' => $term ? [(int) $term->term_id] : [],
    ], true);
    if (is_wp_error($postId)) { fp_log('ERROR insert: ' . $postId->get_error_message()); continue; }
    $seenN[] = $atN; $pub++;
    fp_log('PUBLICADO id=' . $postId . ' [' . $art['category_slug'] . '] ' . $art['title']);
}
fp_log("Fin. Publicadas en esta corrida: $pub");
exit(0);
