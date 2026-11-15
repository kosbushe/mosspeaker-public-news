<?php
declare(strict_types=1);

// Fixed public sources only. No user-supplied URLs, credentials or Telegram sessions.
const NEWS_SOURCES = [
    'mos_speaker' => ['name' => 'Московский спикер', 'type' => 'КЛУБ'],
    'gorkyparkmsk' => ['name' => 'Парк Горького', 'type' => 'ПАРК ГОРЬКОГО'],
];
const NEWS_TTL = 900;
const NEWS_MAX_AGE = 604800;

function news_text(DOMNode $node): string {
    $text = preg_replace('/\s+/u', ' ', $node->textContent) ?? '';
    return trim($text);
}

function parse_news(string $html, string $channel, ?int $now = null): array {
    if (!isset(NEWS_SOURCES[$channel])) return [];
    $now = $now ?? time();
    $dom = new DOMDocument();
    $before = libxml_use_internal_errors(true);
    $loaded = $dom->loadHTML('<?xml encoding="utf-8" ?>' . $html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
    libxml_clear_errors();
    libxml_use_internal_errors($before);
    if (!$loaded) return [];
    $xp = new DOMXPath($dom);
    $posts = $xp->query('//div[contains(concat(" ",normalize-space(@class)," ")," js-widget_message ")][@data-post]');
    if ($posts === false) return [];
    $found = [];
    foreach ($posts as $post) {
        $id = $post->getAttribute('data-post');
        if (!preg_match('~^' . preg_quote($channel, '~') . '/[0-9]+$~', $id)) continue;
        if ($xp->query('.//*[contains(@class,"tgme_widget_message_forwarded_from")]', $post)->length) continue;
        $body = $xp->query('.//div[contains(concat(" ",normalize-space(@class)," ")," tgme_widget_message_text ")]', $post)->item(0);
        $time = $xp->query('.//a[contains(@class,"tgme_widget_message_date")]/time[@datetime]', $post)->item(0);
        if (!$body || !$time) continue;
        foreach ($xp->query('.//br', $body) as $br) $br->parentNode->replaceChild($dom->createTextNode("\n"), $br);
        $timestamp = strtotime($time->getAttribute('datetime'));
        if (!$timestamp || $timestamp > $now + 600 || $timestamp < $now - NEWS_MAX_AGE) continue;
        $text = news_text($body);
        if (mb_strlen($text, 'UTF-8') < 25) continue;
        if ($channel === 'gorkyparkmsk' && !preg_match('/лекци|экскурс|выстав|литератур|дискус|чтени|культур|искусств|главн.{0,15}вход|перекры|маршрут|мост|слуш|звуков|истори|театр/iu', $text)) continue;
        $lines = array_values(array_filter(array_map('trim', preg_split('/\R/u', $body->textContent))));
        $heading = $lines[0] ?? $text;
        if (mb_strlen($heading, 'UTF-8') < 25 && isset($lines[1])) $heading .= ' ' . $lines[1];
        $title = preg_replace('~https?://\S+~u', '', $heading) ?? $heading;
        $title = str_replace([' — ', ' – '], ': ', $title);
        $title = preg_replace('/#[\p{L}\p{N}_]+/u', '', $title) ?? $title;
        $title = trim(preg_replace('/\s+/u', ' ', $title) ?? $title);
        $sentence = preg_split('/(?<=[.!?])\s/u', $title, 2)[0];
        if (mb_strlen($sentence, 'UTF-8') >= 35 && mb_strlen($sentence, 'UTF-8') <= 175) $title = $sentence;
        if (mb_strlen($title, 'UTF-8') > 175) {
            $title = mb_substr($title, 0, 172, 'UTF-8');
            $title = preg_replace('/\s+\S*$/u', '', $title) ?? $title;
            $title .= '…';
        }
        $words = preg_split('/\s+/u', trim($title));
        if (count($words) > 22) $title = implode(' ', array_slice($words, 0, 22)) . '…';
        $title = trim($title);
        if (!$title) continue;
        $found[$id] = [
            'id' => $id, 'type' => NEWS_SOURCES[$channel]['type'],
            'title' => $title, 'source' => NEWS_SOURCES[$channel]['name'],
            'channel' => $channel, 'url' => 'https://t.me/' . $id,
            'published_at' => gmdate('c', $timestamp),
        ];
    }
    $found = array_values($found);
    usort($found, static function ($a, $b) { return strcmp($b['published_at'], $a['published_at']); });
    return array_slice($found, 0, 6);
}

function get_public_feed(string $channel): ?string {
    if (!isset(NEWS_SOURCES[$channel]) || !function_exists('curl_init')) return null;
    $payload = '';
    $curl = curl_init('https://t.me/s/' . $channel);
    curl_setopt_array($curl, [
        CURLOPT_FOLLOWLOCATION => false, CURLOPT_CONNECTTIMEOUT => 4,
        CURLOPT_TIMEOUT => 8, CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2, CURLOPT_USERAGENT => 'MosspeakerNews/1.0 (+https://mosspeaker.ru/)',
        CURLOPT_ENCODING => '', CURLOPT_WRITEFUNCTION => static function ($curl, $chunk) use (&$payload) {
            if (strlen($payload) + strlen($chunk) > 1500000) return 0;
            $payload .= $chunk;
            return strlen($chunk);
        },
    ]);
    if (defined('CURLOPT_PROTOCOLS_STR')) curl_setopt($curl, CURLOPT_PROTOCOLS_STR, 'https');
    elseif (defined('CURLOPT_PROTOCOLS')) curl_setopt($curl, CURLOPT_PROTOCOLS, CURLPROTO_HTTPS);
    $ok = curl_exec($curl);
    $status = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);
    return $ok !== false && $status === 200 ? $payload : null;
}

function get_relay_feed(): ?array {
 return get_relay_url('https://moskovsky-spiker.bushekon.chatgpt.site/api/public-news');
}

function get_relay_url(string $url): ?array {
    if (!function_exists('curl_init')) return null;
    $c = curl_init($url . '?v=' . (int)floor(time()/300));
    curl_setopt_array($c, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_CONNECTTIMEOUT=>4, CURLOPT_TIMEOUT=>8, CURLOPT_SSL_VERIFYPEER=>true, CURLOPT_SSL_VERIFYHOST=>2, CURLOPT_FOLLOWLOCATION=>false, CURLOPT_HTTPHEADER=>['Cache-Control: no-cache']]);
    $raw = curl_exec($c); $code = curl_getinfo($c, CURLINFO_HTTP_CODE); curl_close($c);
    if ($code !== 200 || !is_string($raw) || strlen($raw)>100000) return null;
    $data = json_decode($raw, true);
    $stamp = strtotime($data['checked_at'] ?? '');
    if (!$stamp || $stamp>time()+600 || $stamp<time()-NEWS_MAX_AGE) return null;
    $items=[];
    foreach (array_slice($data['items']??[],0,12) as $i) {
        $ch=$i['channel']??''; $id=$i['id']??''; $t=strtotime($i['published_at']??'');
        if (!isset(NEWS_SOURCES[$ch]) || !preg_match('~^'.preg_quote($ch,'~').'/[0-9]+$~',$id) || !$t || $t<time()-NEWS_MAX_AGE || $t>time()+600 || !is_string($i['title']??null) || mb_strlen($i['title'],'UTF-8')>250) continue;
        $items[]=['id'=>$id,'type'=>NEWS_SOURCES[$ch]['type'],'title'=>$i['title'],'source'=>NEWS_SOURCES[$ch]['name'],'channel'=>$ch,'url'=>'https://t.me/'.$id,'published_at'=>gmdate('c',$t)];
    }
    if (!$items) return null;
    $sources=[];
    foreach (NEWS_SOURCES as $ch=>$v) {
        $ok=false; foreach($data['sources']??[] as $source) if(($source['channel']??'')===$ch && ($source['status']??'')==='updated')$ok=true;
        $sources[]=['channel'=>$ch,'source'=>$v['name'],'status'=>$ok && $stamp>=time()-7200?'updated':'cached_or_unavailable'];
    }
    return ['items'=>$items,'sources'=>$sources,'checked_at'=>gmdate('c',$stamp),'updated_at'=>gmdate('c',$stamp),'checked_epoch'=>time(),'refresh_ok'=>$stamp>=time()-7200 && count(array_filter($sources, static function($s){return $s['status']==='updated';}))===count(NEWS_SOURCES),'refresh_interval_seconds'=>300,'cache_refreshed_at'=>gmdate('c'),'collector_trigger'=>in_array($data['collector_trigger']??'', ['sites','schedule','workflow_dispatch','push','cli'], true)?$data['collector_trigger']:'unknown'];
}


function load_news_json(string $file): array {
    if (!is_file($file)) return [];
    $raw = file_get_contents($file);
    $data = is_string($raw) ? json_decode($raw, true) : null;
    return is_array($data) ? $data : [];
}

function news_endpoint(): void {
    $cli = PHP_SAPI === 'cli';
    if (!$cli && ($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
        http_response_code(405); header('Allow: GET'); return;
    }
    $cacheDir = dirname(__DIR__, 2) . '/news-cache';
    if (!is_dir($cacheDir)) @mkdir($cacheDir, 0750, true);
    $cacheFile = $cacheDir . '/feed.json';
    $cached = load_news_json($cacheFile);
    $seed = load_news_json(__DIR__ . '/news-seed.json');
    $data = $cached ?: $seed;
    $checked = (int)($cached['checked_epoch'] ?? 0);
    $ttl = ($cached['refresh_ok'] ?? false) ? NEWS_TTL : 90;
    $lock = @fopen($cacheDir . '/refresh.lock', 'c');
    if ((time() - $checked >= $ttl || ($cli && in_array('--refresh', $_SERVER['argv'] ?? [], true))) && $lock && flock($lock, LOCK_EX | LOCK_NB)) {
        // Re-read after locking to avoid duplicate refreshes.
        $latest = load_news_json($cacheFile);
        if ($latest && time() - (int)($latest['checked_epoch'] ?? 0) < $ttl && !$cli) {
            $data = $latest;
        } else {
            $oldItems = $data['items'] ?? [];
            $items = []; $sources = []; $success = 0;
            foreach (NEWS_SOURCES as $channel => $source) {
                $html = get_public_feed($channel);
                $parsed = $html !== null ? parse_news($html, $channel) : [];
                $ok = count($parsed) > 0;
                if ($ok) { $success++; $items = array_merge($items, $parsed); }
                else foreach ($oldItems as $item) if (($item['channel'] ?? '') === $channel && strtotime($item['published_at'] ?? '') >= time() - NEWS_MAX_AGE) $items[] = $item;
                $sources[] = ['channel' => $channel, 'source' => $source['name'], 'status' => $ok ? 'updated' : 'cached_or_unavailable'];
            }
            usort($items, static function ($a, $b) { return strcmp($b['published_at'], $a['published_at']); });
            $data = ['items' => $items, 'checked_at' => gmdate('c'), 'checked_epoch' => time(),
                'updated_at' => $success ? gmdate('c') : ($data['updated_at'] ?? null),
                'refresh_ok' => $success === count(NEWS_SOURCES), 'sources' => $sources,
                'refresh_interval_seconds' => NEWS_TTL];
            $encoded = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $temp = $cacheFile . '.tmp';
            if ($encoded && @file_put_contents($temp, $encoded, LOCK_EX) !== false) { @chmod($temp, 0600); @rename($temp, $cacheFile); }
        }
        flock($lock, LOCK_UN);
    }
    if ($lock) fclose($lock);
    $data['items'] = array_values(array_filter($data['items'] ?? [], static function ($i) {
        $time = strtotime($i['published_at'] ?? '');
        return $time && $time <= time() + 600 && $time >= time() - NEWS_MAX_AGE;
    }));
    unset($data['checked_epoch'], $data['refresh_ok']);
    $data['cache_status'] = $cached ? 'cached' : 'initial';
    if (!$cli) {
        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: public, max-age=60, stale-if-error=3600');
    }
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP);
}

if (!defined('NEWS_LIBRARY_ONLY')) news_endpoint();
