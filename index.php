<?php

declare(strict_types=1);

header('Content-Type: application/json');

$configPath = resolve_config_path();

if (!is_file($configPath)) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Missing config.local.json or config.json']);
    exit;
}

$config = decode_config_file($configPath);

if (!is_array($config)) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Invalid config file']);
    exit;
}

function respond(int $code, array $data): void
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function resolve_config_path(): string
{
    $localPath = __DIR__ . '/config.local.json';
    if (is_file($localPath)) {
        return $localPath;
    }

    return __DIR__ . '/config.json';
}

function decode_config_file(string $path): ?array
{
    $raw = (string)file_get_contents($path);

    // Allow JSONC-style comments in the checked-in config template.
    $withoutBlockComments = preg_replace('#/\*.*?\*/#s', '', $raw);
    $withoutLineComments = preg_replace('/^\s*\/\/.*$/m', '', (string)$withoutBlockComments);
    $withoutTrailingCommas = preg_replace('/,\s*([}\]])/', '$1', (string)$withoutLineComments);

    $decoded = json_decode((string)$withoutTrailingCommas, true);
    return is_array($decoded) ? $decoded : null;
}

function log_event(array $config, string $level, string $message, array $context = []): void
{
    if (empty($config['logging']['enabled'])) {
        return;
    }

    $file = __DIR__ . '/' . ($config['logging']['file'] ?? 'logs/dink.log');
    $dir = dirname($file);

    if (!is_dir($dir)) {
        @mkdir($dir, 0777, true);
    }

    $entry = [
        'time' => date('c'),
        'level' => $level,
        'message' => $message,
        'context' => $context,
    ];

    @file_put_contents(
        $file,
        json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL,
        FILE_APPEND
    );
}

function cfg(array $config, array $path, $default = null)
{
    $value = $config;
    foreach ($path as $part) {
        if (!is_array($value) || !array_key_exists($part, $value)) {
            return $default;
        }
        $value = $value[$part];
    }
    return $value;
}

function normalize($v): string
{
    return strtolower(trim((string)$v));
}

function roster_lookup_enabled(array $config): bool
{
    return (bool)cfg($config, ['rosterLookup', 'enabled'], true);
}

function get_event_type(array $p): string
{
    return (string)($p['notificationType'] ?? $p['type'] ?? $p['eventType'] ?? $p['event'] ?? 'UNKNOWN');
}

function normalize_event_type(string $t): string
{
    $t = strtoupper($t);

    if (str_contains($t, 'LOOT')) return 'LOOT';
    if (str_contains($t, 'PET')) return 'PET';
    if (str_contains($t, 'COLLECTION')) return 'COLLECTION';
    if (str_contains($t, 'DEATH')) return 'DEATH';
    if (str_contains($t, 'QUEST')) return 'QUEST';
    if (str_contains($t, 'CLUE')) return 'CLUE';
    if (str_contains($t, 'COMBAT')) return 'COMBAT_TASK';
    if (str_contains($t, 'DIARY')) return 'ACHIEVEMENT_DIARY';
    if (str_contains($t, 'PLAYER_KILL') || str_contains($t, 'PLAYER KILL') || str_contains($t, 'PK')) return 'PLAYER_KILL';
    if (str_contains($t, 'LEVEL')) return 'LEVEL';

    return $t;
}

function get_player(array $p): string
{
    return (string)($p['playerName'] ?? $p['username'] ?? $p['name'] ?? 'UNKNOWN');
}

function get_items(array $p): array
{
    if (!empty($p['items']) && is_array($p['items'])) return $p['items'];
    if (!empty($p['extra']['items']) && is_array($p['extra']['items'])) return $p['extra']['items'];
    return [];
}

function get_item_names(array $p): array
{
    $names = [];
    foreach (get_items($p) as $i) {
        if (is_array($i) && isset($i['name'])) {
            $names[] = (string)$i['name'];
        }
    }
    return $names;
}

function get_value(array $p): int
{
    if (isset($p['totalValue']) && is_numeric($p['totalValue'])) return (int)$p['totalValue'];
    if (isset($p['value']) && is_numeric($p['value'])) return (int)$p['value'];
    if (isset($p['extra']['price']) && is_numeric($p['extra']['price'])) return (int)$p['extra']['price'];
    if (isset($p['extra']['valueLost']) && is_numeric($p['extra']['valueLost'])) return (int)$p['extra']['valueLost'];

    $total = 0;
    foreach (get_items($p) as $i) {
        if (!is_array($i)) continue;
        $qty = isset($i['quantity']) && is_numeric($i['quantity']) ? (int)$i['quantity'] : 1;
        $price = isset($i['priceEach']) && is_numeric($i['priceEach']) ? (int)$i['priceEach'] : 0;
        $total += ($qty * $price);
    }
    return $total;
}

function get_diary_tier(array $p): string
{
    return strtoupper((string)($p['tier'] ?? $p['difficulty'] ?? $p['extra']['difficulty'] ?? ''));
}

function is_seasonal_world(array $payload): bool
{
    return !empty($payload['seasonalWorld']);
}

function get_levelled_skills(array $payload): array
{
    $skills = $payload['extra']['levelledSkills'] ?? [];
    return is_array($skills) ? $skills : [];
}

function get_levelled_skill_entries(array $payload): array
{
    $skills = get_levelled_skills($payload);
    $out = [];

    foreach ($skills as $skillName => $level) {
        $out[] = [
            'skill' => (string)$skillName,
            'level' => is_numeric($level) ? (int)$level : 0,
        ];
    }

    return $out;
}

function get_highest_levelled_skill_value(array $payload): int
{
    $entries = get_levelled_skill_entries($payload);
    if (!$entries) {
        return 0;
    }

    $levels = array_map(fn($entry) => (int)($entry['level'] ?? 0), $entries);
    return $levels ? max($levels) : 0;
}

function get_payload(): array
{
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';

    if (stripos($contentType, 'multipart/form-data') !== false) {
        $payloadJson = $_POST['payload_json'] ?? null;

        if (!is_string($payloadJson) || $payloadJson === '') {
            respond(400, ['ok' => false, 'error' => 'Missing payload_json']);
        }

        $json = json_decode($payloadJson, true);
        if (!is_array($json)) {
            respond(400, ['ok' => false, 'error' => 'Invalid payload_json']);
        }

        return [
            'mode' => 'multipart',
            'json' => $json,
            'file' => $_FILES['file'] ?? null,
        ];
    }

    $raw = (string)file_get_contents('php://input');
    $json = json_decode($raw, true);

    if (!is_array($json)) {
        respond(400, ['ok' => false, 'error' => 'Invalid JSON body']);
    }

    return [
        'mode' => 'json',
        'json' => $json,
        'raw' => $raw,
        'file' => null,
    ];
}

function external_site_config(): array
{
    static $siteConfig = null;
    if (is_array($siteConfig)) {
        return $siteConfig;
    }

    $configPath = dirname(__DIR__) . '/config.php';
    if (!is_file($configPath)) {
        respond(500, ['ok' => false, 'error' => 'Missing external site config.php']);
    }

    $siteConfig = require $configPath;
    if (!is_array($siteConfig)) {
        respond(500, ['ok' => false, 'error' => 'Invalid external site config.php']);
    }

    return $siteConfig;
}

function external_site_db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $config = external_site_config();
    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
        (string)($config['db_host'] ?? '127.0.0.1'),
        (int)($config['db_port'] ?? 3306),
        (string)($config['db_name'] ?? '')
    );

    try {
        $pdo = new PDO($dsn, (string)($config['db_user'] ?? ''), (string)($config['db_pass'] ?? ''), [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    } catch (Throwable $error) {
        respond(500, ['ok' => false, 'error' => 'Failed to connect to website database', 'detail' => $error->getMessage()]);
    }

    return $pdo;
}

function load_roster_rows(array $config): array
{
    $refreshSeconds = (int)cfg($config, ['rosterLookup', 'refreshSeconds'], 300);
    $source = strtolower((string)cfg($config, ['rosterLookup', 'source'], 'database'));
    $cacheFile = sys_get_temp_dir() . '/dink_roster_cache_' . $source . '_' . md5(json_encode(cfg($config, ['rosterLookup'], []))) . '.json';

    if (is_file($cacheFile)) {
        $cached = json_decode((string)file_get_contents($cacheFile), true);

        if (
            is_array($cached) &&
            isset($cached['loadedAt'], $cached['rows']) &&
            (time() - (int)$cached['loadedAt']) < $refreshSeconds
        ) {
            return is_array($cached['rows']) ? $cached['rows'] : [];
        }
    }

    if ($source === 'google_sheet') {
        $rows = load_google_sheet_roster_rows($config);
    } else {
        $rows = load_database_roster_rows($config);
    }

    @file_put_contents($cacheFile, json_encode([
        'loadedAt' => time(),
        'rows' => $rows,
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

    return $rows;
}

function load_database_roster_rows(array $config): array
{
    $requiredStatus = (string)cfg($config, ['rosterLookup', 'requiredStatus'], 'Active');

    try {
        $statement = external_site_db()->prepare('
            SELECT
                rsn AS player_name,
                membership_status AS member_status,
                discord_member_id AS discord_id,
                discord_member_name AS discord_name
            FROM members
            WHERE membership_status = :status
              AND rsn <> ""
        ');
        $statement->execute([':status' => $requiredStatus]);
        $dbRows = $statement->fetchAll();
    } catch (Throwable $error) {
        respond(500, ['ok' => false, 'error' => 'Failed to load website roster from database', 'detail' => $error->getMessage()]);
    }

    $rows = array_map(static function (array $row): array {
        return [
            'Player' => (string)($row['player_name'] ?? ''),
            'Status' => (string)($row['member_status'] ?? ''),
            'Discord ID' => (string)($row['discord_id'] ?? ''),
            'Discord Name' => (string)($row['discord_name'] ?? ''),
        ];
    }, is_array($dbRows) ? $dbRows : []);

    return $rows;
}

function http_get(string $url): string
{
    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'header' => "User-Agent: dinkmiddleman/1.0\r\n",
            'timeout' => 20,
        ],
    ]);

    $body = @file_get_contents($url, false, $context);
    if (!is_string($body) || $body === '') {
        respond(500, ['ok' => false, 'error' => 'Failed to fetch remote URL', 'detail' => $url]);
    }

    return $body;
}

function build_google_sheet_csv_url(array $config): string
{
    $directUrl = trim((string)cfg($config, ['rosterLookup', 'googleSheet', 'csvUrl'], ''));
    if ($directUrl !== '') {
        return $directUrl;
    }

    $sheetId = trim((string)cfg($config, ['rosterLookup', 'googleSheet', 'sheetId'], ''));
    if ($sheetId === '') {
        respond(500, ['ok' => false, 'error' => 'Missing Google Sheet csvUrl or sheetId']);
    }

    $gid = trim((string)cfg($config, ['rosterLookup', 'googleSheet', 'gid'], '0'));
    return sprintf(
        'https://docs.google.com/spreadsheets/d/%s/export?format=csv&gid=%s',
        rawurlencode($sheetId),
        rawurlencode($gid)
    );
}

function build_google_sheet_values_api_url(array $config): string
{
    $sheetId = trim((string)cfg($config, ['rosterLookup', 'googleSheet', 'sheetId'], ''));
    $apiKey = trim((string)cfg($config, ['rosterLookup', 'googleSheet', 'apiKey'], ''));
    $range = trim((string)cfg($config, ['rosterLookup', 'googleSheet', 'range'], ''));

    if ($sheetId === '' || $apiKey === '' || $range === '') {
        respond(500, ['ok' => false, 'error' => 'Missing Google Sheet sheetId, apiKey, or range for values API mode']);
    }

    return sprintf(
        'https://sheets.googleapis.com/v4/spreadsheets/%s/values/%s?key=%s',
        rawurlencode($sheetId),
        rawurlencode($range),
        rawurlencode($apiKey)
    );
}

function build_google_sheet_gviz_url(array $config): string
{
    $sheetId = trim((string)cfg($config, ['rosterLookup', 'googleSheet', 'sheetId'], ''));
    if ($sheetId === '') {
        respond(500, ['ok' => false, 'error' => 'Missing Google Sheet sheetId']);
    }

    $sheetName = trim((string)cfg($config, ['rosterLookup', 'googleSheet', 'sheetName'], ''));
    $gid = trim((string)cfg($config, ['rosterLookup', 'googleSheet', 'gid'], ''));

    $query = ['tqx' => 'out:json'];
    if ($sheetName !== '') {
        $query['sheet'] = $sheetName;
    } elseif ($gid !== '') {
        $query['gid'] = $gid;
    }

    return 'https://docs.google.com/spreadsheets/d/' . rawurlencode($sheetId) . '/gviz/tq?' . http_build_query($query);
}

function rows_from_header_values(array $values): array
{
    if (count($values) < 2) {
        return [];
    }

    $headers = array_map(static fn($value): string => (string)$value, (array)$values[0]);
    $rows = [];

    for ($i = 1; $i < count($values); $i++) {
        $sourceRow = is_array($values[$i]) ? $values[$i] : [];
        $row = [];

        foreach ($headers as $index => $header) {
            $row[$header] = (string)($sourceRow[$index] ?? '');
        }

        $rows[] = $row;
    }

    return $rows;
}

function load_google_sheet_rows_from_values_api(array $config): array
{
    $url = build_google_sheet_values_api_url($config);
    $body = http_get($url);
    $decoded = json_decode($body, true);

    if (!is_array($decoded) || !isset($decoded['values']) || !is_array($decoded['values'])) {
        respond(500, ['ok' => false, 'error' => 'Invalid response from Google Sheets values API']);
    }

    return rows_from_header_values($decoded['values']);
}

function parse_google_visualization_json(string $body): array
{
    if (!preg_match('/google\.visualization\.Query\.setResponse\((.*)\);?\s*$/s', trim($body), $matches)) {
        respond(500, ['ok' => false, 'error' => 'Invalid Google Sheet visualization response']);
    }

    $decoded = json_decode($matches[1], true);
    if (!is_array($decoded)) {
        respond(500, ['ok' => false, 'error' => 'Failed to decode Google Sheet visualization response']);
    }

    return $decoded;
}

function load_google_sheet_rows_from_gviz(array $config): array
{
    $url = build_google_sheet_gviz_url($config);
    $body = http_get($url);
    $decoded = parse_google_visualization_json($body);
    $table = $decoded['table'] ?? null;

    if (!is_array($table) || !isset($table['cols'], $table['rows']) || !is_array($table['cols']) || !is_array($table['rows'])) {
        respond(500, ['ok' => false, 'error' => 'Invalid Google Sheet table response']);
    }

    $headers = [];
    foreach ($table['cols'] as $column) {
        $headers[] = (string)($column['label'] ?? $column['id'] ?? '');
    }

    $rows = [];
    foreach ($table['rows'] as $rowData) {
        $cells = is_array($rowData['c'] ?? null) ? $rowData['c'] : [];
        $row = [];

        foreach ($headers as $index => $header) {
            $cell = $cells[$index] ?? null;
            $row[$header] = is_array($cell) ? (string)($cell['v'] ?? '') : '';
        }

        $rows[] = $row;
    }

    return $rows;
}

function load_google_sheet_roster_rows(array $config): array
{
    $mode = strtolower((string)cfg($config, ['rosterLookup', 'googleSheet', 'mode'], 'values_api'));

    if ($mode === 'values_api') {
        return load_google_sheet_rows_from_values_api($config);
    }

    if ($mode === 'gviz') {
        return load_google_sheet_rows_from_gviz($config);
    }

    if ($mode === 'csv_export') {
        $csvUrl = build_google_sheet_csv_url($config);
        $csv = http_get($csvUrl);
        $lines = preg_split("/\r\n|\n|\r/", trim($csv));
        if (!is_array($lines) || count($lines) < 2) {
            return [];
        }

        $values = [];
        foreach ($lines as $line) {
            $parsed = str_getcsv($line);
            if (is_array($parsed)) {
                $values[] = $parsed;
            }
        }

        return rows_from_header_values($values);
    }

    respond(500, ['ok' => false, 'error' => 'Invalid Google Sheet mode']);
}

function find_user(array $config, array $payload): ?array
{
    $playerColumn = (string)cfg($config, ['rosterLookup', 'columns', 'player'], 'Player');
    $statusColumn = (string)cfg($config, ['rosterLookup', 'columns', 'status'], 'Status');
    $requiredStatus = normalize((string)cfg($config, ['rosterLookup', 'requiredStatus'], 'Active'));

    $rows = load_roster_rows($config);
    $player = normalize(get_player($payload));

    foreach ($rows as $r) {
        $rowPlayer = normalize((string)($r[$playerColumn] ?? ''));
        $rowStatus = normalize((string)($r[$statusColumn] ?? ''));

        $statusMatches = $requiredStatus === '' || $rowStatus === $requiredStatus;

        if ($rowPlayer === $player && $statusMatches) {
            return $r;
        }
    }

    return null;
}

function extract_discord_id(?array $row, array $config): ?string
{
    if (!$row) return null;

    $col = (string)cfg($config, ['rosterLookup', 'columns', 'discordId'], 'Discord ID');
    $val = (string)($row[$col] ?? '');

    if (preg_match('/\d{15,}/', $val, $m)) {
        return $m[0];
    }

    return null;
}

function add_mention(array $payload, ?string $id): array
{
    if (!$id) return $payload;

    $payload['content'] = trim(((string)($payload['content'] ?? '')) . " <@{$id}>");
    $payload['allowed_mentions'] = ['parse' => [], 'users' => [$id]];
    $payload['flags'] = ((int)($payload['flags'] ?? 0)) | 4096;

    return $payload;
}

function is_duplicate(array $config, array $payload, string $type): bool
{
    $rateLimitSeconds = (int)cfg($config, ['globalRules', 'rateLimitSeconds'], 15);
    if ($rateLimitSeconds <= 0) {
        return false;
    }

    $key = implode('|', [
        normalize(get_player($payload)),
        $type,
        (string)get_value($payload),
        implode(',', get_item_names($payload)),
    ]);

    $cacheFile = sys_get_temp_dir() . '/dink_recent_events_' . md5((string)cfg($config, ['urlToken'], 'default')) . '.json';
    $data = [];

    if (is_file($cacheFile)) {
        $decoded = json_decode((string)file_get_contents($cacheFile), true);
        if (is_array($decoded)) {
            $data = $decoded;
        }
    }

    $now = time();

    foreach ($data as $k => $ts) {
        if (($now - (int)$ts) > ($rateLimitSeconds * 3)) {
            unset($data[$k]);
        }
    }

    if (isset($data[$key]) && ($now - (int)$data[$key]) < $rateLimitSeconds) {
        @file_put_contents($cacheFile, json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        return true;
    }

    $data[$key] = $now;
    @file_put_contents($cacheFile, json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

    return false;
}

function forum_cache_path(array $config): string
{
    $relative = (string)cfg($config, ['forumRouting', 'cacheFile'], 'thread-cache.json');
    return __DIR__ . '/' . $relative;
}

function load_thread_cache(array $config): array
{
    $path = forum_cache_path($config);
    if (!is_file($path)) {
        return [];
    }

    $decoded = json_decode((string)file_get_contents($path), true);
    return is_array($decoded) ? $decoded : [];
}

function save_thread_cache(array $config, array $cache): void
{
    $path = forum_cache_path($config);
    $dir = dirname($path);
    if (!is_dir($dir)) {
        @mkdir($dir, 0777, true);
    }
    @file_put_contents($path, json_encode($cache, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
}

function get_forum_category_key(array $config, string $type, int $value, array $payload): string
{
    $seasonalEnabled = (bool)cfg($config, ['forumRouting', 'seasonal', 'enabled'], false);
    $seasonalKey = (string)cfg($config, ['forumRouting', 'seasonal', 'categoryKey'], 'SEASONAL');

    if ($seasonalEnabled && is_seasonal_world($payload)) {
        return $seasonalKey;
    }

    $highEnabled = (bool)cfg($config, ['highValueRule', 'enabled'], false);
    $highMin = (int)cfg($config, ['highValueRule', 'minValue'], 0);
    $highTypes = cfg($config, ['highValueRule', 'onlyTypes'], []);

    if ($highEnabled && $value >= $highMin && is_array($highTypes) && in_array($type, $highTypes, true)) {
        return 'HIGH_VALUE';
    }

    return $type;
}

function get_forum_thread_name(array $config, string $categoryKey): ?string
{
    $name = cfg($config, ['forumRouting', 'categories', $categoryKey], null);
    if (!is_string($name) || trim($name) === '') {
        return null;
    }
    return trim($name);
}

function resolve_webhook_request(array $config, string $type, int $value, array $payload): array
{
    $baseWebhook = resolve_base_webhook($config, $type, $value);
    if ($baseWebhook === '') {
        respond(500, ['ok' => false, 'error' => 'No webhook configured']);
    }

    $forumEnabled = (bool)cfg($config, ['forumRouting', 'enabled'], false);
    if (!$forumEnabled) {
        return [
            'url' => $baseWebhook,
            'isForum' => false,
            'categoryKey' => null,
            'threadName' => null,
            'threadId' => null,
        ];
    }

    $categoryKey = get_forum_category_key($config, $type, $value, $payload);
    $threadName = get_forum_thread_name($config, $categoryKey);

    if ($threadName === null) {
        return [
            'url' => $baseWebhook,
            'isForum' => false,
            'categoryKey' => $categoryKey,
            'threadName' => null,
            'threadId' => null,
        ];
    }

    $cache = load_thread_cache($config);
    $threadId = $cache[$categoryKey]['thread_id'] ?? null;

    if (is_string($threadId) && preg_match('/^\d+$/', $threadId)) {
        $url = $baseWebhook . (str_contains($baseWebhook, '?') ? '&' : '?') . 'wait=true&thread_id=' . rawurlencode($threadId);
        return [
            'url' => $url,
            'isForum' => true,
            'categoryKey' => $categoryKey,
            'threadName' => null,
            'threadId' => $threadId,
        ];
    }

    $url = $baseWebhook . (str_contains($baseWebhook, '?') ? '&' : '?') . 'wait=true';
    return [
        'url' => $url,
        'isForum' => true,
        'categoryKey' => $categoryKey,
        'threadName' => $threadName,
        'threadId' => null,
    ];
}

function resolve_base_webhook(array $config, string $type, int $value): string
{
    $eventWebhook = trim((string)cfg($config, ['eventRules', $type, 'webhookUrl'], ''));
    if ($eventWebhook !== '') {
        return $eventWebhook;
    }

    $highEnabled = (bool)cfg($config, ['highValueRule', 'enabled'], false);
    $highMin = (int)cfg($config, ['highValueRule', 'minValue'], 0);
    $highTypes = cfg($config, ['highValueRule', 'onlyTypes'], []);
    $highWebhook = trim((string)cfg($config, ['highValueRule', 'webhookUrl'], ''));

    if (
        $highEnabled &&
        $highWebhook !== '' &&
        $value >= $highMin &&
        is_array($highTypes) &&
        in_array($type, $highTypes, true)
    ) {
        return $highWebhook;
    }

    return trim((string)cfg($config, ['discordWebhookUrl'], ''));
}

function apply_forum_thread_name(array $payload, ?string $threadName): array
{
    if ($threadName !== null && $threadName !== '') {
        $payload['thread_name'] = $threadName;
    }
    return $payload;
}

function maybe_store_forum_thread(array $config, array $requestInfo, int $statusCode, string $responseBody): void
{
    if (empty($requestInfo['isForum'])) {
        return;
    }

    if (!empty($requestInfo['threadId'])) {
        return;
    }

    if ($statusCode < 200 || $statusCode >= 300) {
        return;
    }

    $decoded = json_decode($responseBody, true);
    if (!is_array($decoded)) {
        return;
    }

    $channelId = $decoded['channel_id'] ?? null;
    $categoryKey = $requestInfo['categoryKey'] ?? null;
    $threadName = $requestInfo['threadName'] ?? null;

    if (!is_string($channelId) || !preg_match('/^\d+$/', $channelId) || !is_string($categoryKey)) {
        return;
    }

    $cache = load_thread_cache($config);
    $cache[$categoryKey] = [
        'thread_id' => $channelId,
        'thread_name' => $threadName,
        'stored_at' => date('c'),
    ];
    save_thread_cache($config, $cache);
}

function send_json(string $url, array $data): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => 1,
        CURLOPT_RETURNTRANSFER => 1,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
    ]);

    $res = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    return [$code, $res === false ? $err : $res];
}

function send_multi(string $url, array $data, ?array $file): array
{
    if (!$file || empty($file['tmp_name'])) {
        return [400, 'Missing uploaded file'];
    }

    $post = [
        'payload_json' => json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        'file' => new CURLFile($file['tmp_name'], $file['type'] ?? 'application/octet-stream', $file['name'] ?? 'upload.bin'),
    ];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => 1,
        CURLOPT_RETURNTRANSFER => 1,
        CURLOPT_POSTFIELDS => $post,
    ]);

    $res = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    return [$code, $res === false ? $err : $res];
}

/* ================= MAIN ================= */

$path = basename((string)parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH));
$urlToken = (string)($config['urlToken'] ?? '');

if ($urlToken !== '' && $path !== $urlToken) {
    respond(401, ['ok' => false, 'error' => 'unauthorized']);
}

$incoming = get_payload();
$payload = $incoming['json'];

log_event($config, 'INFO', 'Incoming', [
    'player' => get_player($payload),
    'rawType' => get_event_type($payload),
    'mode' => $incoming['mode'],
]);

if (!empty(cfg($config, ['logging', 'logPayload'], false))) {
    log_event($config, 'DEBUG', 'Payload', $payload);
}

$type = normalize_event_type(get_event_type($payload));
log_event($config, 'INFO', 'Normalized event', ['type' => $type]);

$row = roster_lookup_enabled($config) ? find_user($config, $payload) : null;
if (roster_lookup_enabled($config) && !$row) {
    log_event($config, 'WARN', 'User blocked', ['player' => get_player($payload)]);
    respond(200, ['ok' => true, 'forwarded' => false, 'reason' => 'not in roster']);
}

$value = get_value($payload);
log_event($config, 'INFO', 'Value computed', [
    'value' => $value,
    'items' => get_item_names($payload),
]);

$rule = $config['eventRules'][$type] ?? null;
if (!$rule || empty($rule['enabled'])) {
    log_event($config, 'WARN', 'Event blocked', ['type' => $type]);
    respond(200, ['ok' => true, 'forwarded' => false, 'reason' => 'event not allowed']);
}

if (($type === 'LOOT' || $type === 'CLUE') && $value < (int)($rule['minValue'] ?? 0)) {
    log_event($config, 'WARN', 'Below threshold', [
        'type' => $type,
        'value' => $value,
        'minValue' => (int)($rule['minValue'] ?? 0),
    ]);
    respond(200, ['ok' => true, 'forwarded' => false, 'reason' => 'below threshold']);
}

if ($type === 'ACHIEVEMENT_DIARY') {
    $tier = get_diary_tier($payload);
    $map = ['EASY' => 1, 'MEDIUM' => 2, 'HARD' => 3, 'ELITE' => 4];

    if (($map[$tier] ?? 0) < ($map[(string)($rule['minTier'] ?? 'MEDIUM')] ?? 0)) {
        log_event($config, 'WARN', 'Diary blocked', ['tier' => $tier]);
        respond(200, ['ok' => true, 'forwarded' => false, 'reason' => 'tier too low']);
    }
}

if ($type === 'LEVEL') {
    $levelEntries = get_levelled_skill_entries($payload);
    $newLevel = get_highest_levelled_skill_value($payload);
    $minLevel = (int)($rule['minLevel'] ?? 0);
    $tiers = $rule['tiers'] ?? [];
    $skillRules = $rule['skills'] ?? [];

    log_event($config, 'INFO', 'Level event parsed', [
        'newLevel' => $newLevel,
        'levelledSkills' => $levelEntries,
    ]);

    if ($newLevel < $minLevel) {
        log_event($config, 'WARN', 'Level blocked by minimum', [
            'level' => $newLevel,
            'minLevel' => $minLevel,
        ]);
        respond(200, [
            'ok' => true,
            'forwarded' => false,
            'reason' => 'level below minimum',
        ]);
    }

    if (is_array($tiers) && count($tiers) > 0 && !in_array($newLevel, $tiers, true)) {
        log_event($config, 'WARN', 'Level blocked by tier filter', [
            'level' => $newLevel,
            'tiers' => $tiers,
        ]);
        respond(200, [
            'ok' => true,
            'forwarded' => false,
            'reason' => 'level not in tiers',
        ]);
    }

    if (is_array($skillRules) && count($skillRules) > 0) {
        $matchedSkillRule = false;

        foreach ($levelEntries as $entry) {
            $skillName = (string)($entry['skill'] ?? '');
            $skillLevel = (int)($entry['level'] ?? 0);

            if (!isset($skillRules[$skillName]) || !is_array($skillRules[$skillName])) {
                continue;
            }

            $matchedSkillRule = true;
            $skillRule = $skillRules[$skillName];
            $skillMin = (int)($skillRule['minLevel'] ?? 0);
            $skillTiers = $skillRule['tiers'] ?? [];

            if ($skillLevel < $skillMin) {
                log_event($config, 'WARN', 'Level blocked by skill minimum', [
                    'skill' => $skillName,
                    'level' => $skillLevel,
                    'minLevel' => $skillMin,
                ]);
                respond(200, [
                    'ok' => true,
                    'forwarded' => false,
                    'reason' => 'skill level below minimum',
                ]);
            }

            if (is_array($skillTiers) && count($skillTiers) > 0 && !in_array($skillLevel, $skillTiers, true)) {
                log_event($config, 'WARN', 'Level blocked by skill tier filter', [
                    'skill' => $skillName,
                    'level' => $skillLevel,
                    'tiers' => $skillTiers,
                ]);
                respond(200, [
                    'ok' => true,
                    'forwarded' => false,
                    'reason' => 'skill level not in tiers',
                ]);
            }
        }

        if (!$matchedSkillRule) {
            log_event($config, 'WARN', 'Level blocked because no skill-specific rule matched', [
                'levelledSkills' => $levelEntries,
            ]);
            respond(200, [
                'ok' => true,
                'forwarded' => false,
                'reason' => 'no skill rule matched',
            ]);
        }
    }
}

$blockedItems = cfg($config, ['globalRules', 'blockedItems'], []);
if (is_array($blockedItems)) {
    foreach (get_item_names($payload) as $itemName) {
        if (in_array($itemName, $blockedItems, true)) {
            log_event($config, 'WARN', 'Blocked item', ['item' => $itemName]);
            respond(200, ['ok' => true, 'forwarded' => false, 'reason' => 'blocked item']);
        }
    }
}

$blockedTypes = array_map('strtoupper', (array)cfg($config, ['globalRules', 'blockedTypes'], []));
if (in_array(strtoupper($type), $blockedTypes, true) || in_array(strtoupper(get_event_type($payload)), $blockedTypes, true)) {
    log_event($config, 'WARN', 'Blocked type', ['type' => $type]);
    respond(200, ['ok' => true, 'forwarded' => false, 'reason' => 'blocked type']);
}

if (is_duplicate($config, $payload, $type)) {
    log_event($config, 'WARN', 'Duplicate blocked', ['type' => $type]);
    respond(200, ['ok' => true, 'forwarded' => false, 'reason' => 'duplicate/rate-limited']);
}

$discordId = extract_discord_id($row, $config);
if (!empty(cfg($config, ['discordOptions', 'silentMentionDropOwner'], false))) {
    $payload = add_mention($payload, $discordId);
}

$requestInfo = resolve_webhook_request($config, $type, $value, $payload);
$payload = apply_forum_thread_name($payload, $requestInfo['threadName']);

log_event($config, 'INFO', 'Sending to Discord', [
    'url' => $requestInfo['url'],
    'player' => get_player($payload),
    'value' => $value,
    'discordId' => $discordId,
    'forum' => $requestInfo['isForum'],
    'categoryKey' => $requestInfo['categoryKey'],
    'threadName' => $requestInfo['threadName'],
    'threadId' => $requestInfo['threadId'],
    'seasonalWorld' => is_seasonal_world($payload),
]);

if ($incoming['mode'] === 'multipart') {
    [$code, $res] = send_multi($requestInfo['url'], $payload, $incoming['file']);
} else {
    [$code, $res] = send_json($requestInfo['url'], $payload);
}

log_event($config, 'INFO', 'Discord response', [
    'status' => $code,
    'response' => $res,
]);

maybe_store_forum_thread($config, $requestInfo, $code, (string)$res);

respond(200, [
    'ok' => true,
    'forwarded' => true,
    'status' => $code,
    'forum' => $requestInfo['isForum'],
    'categoryKey' => $requestInfo['categoryKey'],
    'threadName' => $requestInfo['threadName'],
    'threadId' => $requestInfo['threadId'],
]);
