<?php
require_once __DIR__ . '/components/permanent-session.php';
require_once __DIR__ . '/components/whatsapp-templates-helper.php';
$mqQuoteStatusWaSettingsFile = __DIR__ . '/components/mq-quote-status-whatsapp-settings.php';
if (is_file($mqQuoteStatusWaSettingsFile)) {
    require_once $mqQuoteStatusWaSettingsFile;
}

if (!isset($_SESSION['user_logged_in']) || $_SESSION['user_logged_in'] !== true || !isset($_SESSION['user_id'])) {
    alfa_sim_redirect('https:///system/login.php');
    exit;
}

$_SESSION['login_time'] = time();

$current_user_id = (int) $_SESSION['user_id'];

// التكوين
$API_CONFIG = [
    'baseUrl' => 'https://testtest.com',
    'token' => '',
    'quotesTableId' => 704,
    'usersTableId' => 702,
    'statusesTableId' => 730,
    'notesTableId' => 734,
    'actionLogsTableId' => 707
];
// خرائط الحقول
$FIELDS = [
    'quotes' => [
        'client' => 'field_6977',
        'date' => 'field_6789',
        'totalPrice' => 'field_6984',
        'generated' => 'field_7157',
        'brand' => 'field_6973',
        'createdBy' => 'field_6990',
        'quoteNumber' => 'field_6783',
        'approvalTime' => 'field_7014',
        'rejectionReason' => 'field_7015',
        'externalStatus' => 'field_7316',
        'notes' => 'field_7345'
    ],
    'users' => [
        'name' => 'field_6912',
        'phone' => 'field_6773'
    ],
    'action_logs' => [
        'user' => 'field_6928',
        'action' => 'field_6929',
        'quote' => 'field_6935',
        'status' => 'field_8474',
        'date' => 'field_8476'
    ]
];
$NOTE_FIELDS = [
    'text' => 'field_7341',
    'author' => 'field_7342',
    'quoteLink' => 'field_7344',
    'createdOn' => 'field_7340',
    'authorName' => 'field_7347'
];
$JS_ENCODE_FLAGS = JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_INVALID_UTF8_SUBSTITUTE;
$MQ_PAGE_SIZE = 10;
$MQ_SCAN_PAGE_SIZE = 100;
$MQ_FILTER_CACHE_TTL = 300;
$MQ_FILTER_CACHE_DIR = __DIR__ . '/docs/cache';
$MQ_REFERENCE_CACHE_TTL = 180;

function safeJsonForJs($value, $fallbackJson = 'null')
{
    global $JS_ENCODE_FLAGS;
    $encoded = json_encode($value, $JS_ENCODE_FLAGS | JSON_PARTIAL_OUTPUT_ON_ERROR);
    if ($encoded === false || $encoded === null) {
        return $fallbackJson;
    }
    return $encoded;
}
$QUOTE_NOTES_CACHE = [];
$STATUS_ACTIONS_CACHE = [];
$QUOTES_CONTRACTS_STATUS_ACCESS = [
    'has_full_access' => false,
    'view_statuses' => [],
    'view_categories' => [],
    'action_statuses' => [],
    'action_categories' => [],
    'data_sections' => [],
    'restrict_view' => false,
    'restrict_action' => false,
];
$FILE_GENERATION_LOG_PATH = __DIR__ . '/docs/logs/mq-file-generation.log';
$ACTION_LOG_PATH = __DIR__ . '/docs/logs/mq-actions.log';
$GENERAL_ERROR_LOG_PATH = __DIR__ . '/docs/logs/mq-errors.log';
$LOG_DB_PATH = __DIR__ . '/docs/logs/mq-logs.sqlite';
$RECORDED_SERVER_ERROR_IDS = [];

function ensureLogDirectoryExists($path)
{
    static $ensured = [];
    $dir = dirname($path);
    if (isset($ensured[$dir])) {
        return;
    }
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    $ensured[$dir] = true;
}

function generateLogId($prefix = 'MQE')
{
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $id = '';
    try {
        for ($i = 0; $i < 3; $i++) {
            $id .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }
    } catch (Throwable $e) {
        $id = substr(str_shuffle($alphabet), 0, 3);
    }
    return $id;
}

function sanitizeLogPayload($value, $depth = 0)
{
    if ($depth > 4) {
        if (is_scalar($value) || $value === null) {
            return $value;
        }
        return '__max_depth__';
    }

    if (is_array($value)) {
        $sanitized = [];
        $count = 0;
        foreach ($value as $key => $item) {
            if ($count >= 30) {
                $sanitized['__truncated__'] = true;
                break;
            }
            $sanitized[$key] = sanitizeLogPayload($item, $depth + 1);
            $count++;
        }
        return $sanitized;
    }

    if (is_object($value)) {
        return sanitizeLogPayload((array) $value, $depth + 1);
    }

    if (is_string($value)) {
        $value = preg_replace('/\s+/u', ' ', trim($value));
        if (mb_strlen($value) > 500) {
            $value = mb_substr($value, 0, 500) . '...';
        }
        return $value;
    }

    if (is_numeric($value)) {
        return $value + 0;
    }

    if (is_bool($value) || $value === null) {
        return $value;
    }

    return (string) $value;
}

function getLogDbConnection()
{
    global $LOG_DB_PATH;
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    ensureLogDirectoryExists($LOG_DB_PATH);
    $dsn = 'sqlite:' . $LOG_DB_PATH;
    $pdo = new PDO($dsn);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('PRAGMA journal_mode=WAL;');
    $pdo->exec('PRAGMA busy_timeout=5000;');

    ensureLogDbInitialized($pdo);

    return $pdo;
}

function ensureLogDbInitialized(PDO $pdo)
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS error_logs (
            id TEXT PRIMARY KEY,
            created_at TEXT NOT NULL,
            category TEXT,
            message TEXT,
            context TEXT,
            user_id INTEGER,
            ip TEXT,
            agent TEXT,
            handled INTEGER DEFAULT 0,
            handled_at TEXT
        )
    ");
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS log_meta (
            key TEXT PRIMARY KEY,
            value TEXT
        )
    ");

    migrateLegacyLogs($pdo);
}

function getLogMeta(PDO $pdo, $key)
{
    $stmt = $pdo->prepare('SELECT value FROM log_meta WHERE key = :key LIMIT 1');
    $stmt->execute(['key' => $key]);
    $row = $stmt->fetch();
    return $row ? $row['value'] : null;
}

function setLogMeta(PDO $pdo, $key, $value)
{
    $stmt = $pdo->prepare('INSERT INTO log_meta (key, value) VALUES (:key, :value) ON CONFLICT(key) DO UPDATE SET value = :value');
    $stmt->execute(['key' => $key, 'value' => $value]);
}

function migrateLegacyLogs(PDO $pdo)
{
    global $FILE_GENERATION_LOG_PATH, $GENERAL_ERROR_LOG_PATH;

    $alreadyMigrated = getLogMeta($pdo, 'legacy_logs_migrated_v1');
    if ($alreadyMigrated === '1') {
        return;
    }

    $sources = [
        $FILE_GENERATION_LOG_PATH => 'legacy_file_generation',
        $GENERAL_ERROR_LOG_PATH => 'legacy_php_error',
    ];

    foreach ($sources as $filePath => $category) {
        if (!is_file($filePath)) {
            continue;
        }

        $handle = @fopen($filePath, 'r');
        if (!$handle) {
            continue;
        }

        while (($line = fgets($handle)) !== false) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $decoded = json_decode($line, true);
            if (!is_array($decoded)) {
                continue;
            }

            $id = isset($decoded['id']) && $decoded['id'] !== '' ? $decoded['id'] : null;
            $createdAt = $decoded['time'] ?? date('Y-m-d H:i:s');
            $message = $decoded['message'] ?? ($decoded['event'] ?? 'legacy_log');
            $agent = $decoded['agent'] ?? null;
            $userId = $decoded['user_id'] ?? null;
            $ip = $decoded['ip'] ?? null;
            $context = $decoded;

            storeErrorLog($category, $message, $context, [
                'id' => $id,
                'created_at' => $createdAt,
                'agent' => $agent,
                'user_id' => $userId,
                'ip' => $ip,
            ], true);
        }

        fclose($handle);
    }

    setLogMeta($pdo, 'legacy_logs_migrated_v1', '1');
}

function ensureUniqueLogId(PDO $pdo)
{
    $attempts = 0;
    do {
        $candidate = generateLogId();
        $stmt = $pdo->prepare('SELECT COUNT(*) AS cnt FROM error_logs WHERE id = :id');
        $stmt->execute(['id' => $candidate]);
        $exists = (int) $stmt->fetchColumn() > 0;
        $attempts++;
    } while ($exists && $attempts < 10);

    if ($exists) {
        // fallback to a prefixed random id if collisions persist
        $candidate = generateLogId() . substr((string) time(), -1);
    }

    return $candidate;
}

function storeErrorLog($category, $message, array $context = [], array $options = [], $suppressExceptions = false)
{
    try {
        $pdo = getLogDbConnection();
        $id = isset($options['id']) && $options['id'] !== '' ? $options['id'] : ensureUniqueLogId($pdo);
        $createdAt = $options['created_at'] ?? date('Y-m-d H:i:s');
        $agent = $options['agent'] ?? null;
        $userId = $options['user_id'] ?? null;
        $ip = $options['ip'] ?? null;
        $handled = isset($options['handled']) ? (int) (bool) $options['handled'] : 0;
        $handledAt = $options['handled_at'] ?? null;

        $stmt = $pdo->prepare('
            INSERT OR IGNORE INTO error_logs (id, created_at, category, message, context, user_id, ip, agent, handled, handled_at)
            VALUES (:id, :created_at, :category, :message, :context, :user_id, :ip, :agent, :handled, :handled_at)
        ');
        $stmt->execute([
            'id' => $id,
            'created_at' => $createdAt,
            'category' => $category,
            'message' => $message,
            'context' => json_encode(sanitizeLogPayload($context), JSON_UNESCAPED_UNICODE),
            'user_id' => $userId,
            'ip' => $ip,
            'agent' => $agent,
            'handled' => $handled,
            'handled_at' => $handledAt,
        ]);

        return $id;
    } catch (Throwable $e) {
        if ($suppressExceptions) {
            return null;
        }
        throw $e;
    }
}

function markErrorLogHandled($logId, $handled = true)
{
    try {
        $pdo = getLogDbConnection();
        $stmt = $pdo->prepare('
            UPDATE error_logs
            SET handled = :handled,
                handled_at = CASE WHEN :handled = 1 THEN COALESCE(handled_at, :now) ELSE NULL END
            WHERE id = :id
        ');
        $stmt->execute([
            'handled' => $handled ? 1 : 0,
            'now' => date('Y-m-d H:i:s'),
            'id' => $logId,
        ]);
        return true;
    } catch (Throwable $e) {
        error_log('Failed to update handled status for log ' . $logId . ': ' . $e->getMessage());
        return false;
    }
}

function logDiagnosticEvent($event, array $context = [])
{
    global $ACTION_LOG_PATH, $current_user_id;

    $eventName = trim((string) $event);
    if ($eventName === '') {
        $eventName = 'unspecified';
    }

    ensureLogDirectoryExists($ACTION_LOG_PATH);

    $entry = [
        'time' => date('Y-m-d H:i:s'),
        'event' => $eventName,
        'user_id' => $current_user_id ?? null,
        'ip' => $_SERVER['REMOTE_ADDR'] ?? null,
        'method' => $_SERVER['REQUEST_METHOD'] ?? null,
        'uri' => $_SERVER['REQUEST_URI'] ?? null,
        'agent' => isset($_SERVER['HTTP_USER_AGENT']) ? sanitizeLogPayload($_SERVER['HTTP_USER_AGENT']) : null,
        'context' => sanitizeLogPayload($context)
    ];

    $payload = json_encode($entry, JSON_UNESCAPED_UNICODE);
    if ($payload === false) {
        error_log('Failed to encode diagnostic payload in mq.php');
        return;
    }

    @file_put_contents($ACTION_LOG_PATH, $payload . PHP_EOL, FILE_APPEND | LOCK_EX);
}

function logFileGenerationIssue($message, array $context = [], $logPath = null, $category = 'file_generation')
{
    global $current_user_id;

    $contextPayload = array_merge($context, [
        'log_path_hint' => $logPath ?: null,
    ]);

    return storeErrorLog(
        $category ?: 'file_generation',
        $message,
        $contextPayload,
        [
            'user_id' => $current_user_id ?? null,
            'ip' => $_SERVER['REMOTE_ADDR'] ?? null,
            'agent' => isset($_SERVER['HTTP_USER_AGENT']) ? sanitizeLogPayload($_SERVER['HTTP_USER_AGENT']) : null,
        ],
        true
    );
}

function respondAjaxWithLog($event, array $payload, array $context = [])
{
    header('Content-Type: application/json');
    echo json_encode($payload);
    if ($event) {
        logDiagnosticEvent($event, array_merge($context, [
            'success' => $payload['success'] ?? null,
            'message' => $payload['message'] ?? null,
        ]));
    }
    exit;
}

function collectRequestDebugInfo()
{
    return [
        'method' => $_SERVER['REQUEST_METHOD'] ?? null,
        'uri' => $_SERVER['REQUEST_URI'] ?? null,
        'get' => isset($_GET) ? sanitizeLogPayload($_GET) : [],
        'post' => isset($_POST) ? sanitizeLogPayload($_POST) : [],
        'session_keys' => isset($_SESSION) ? array_keys($_SESSION) : [],
        'referer' => $_SERVER['HTTP_REFERER'] ?? null,
    ];
}

function recordServerError($label, array $context = [], $category = 'runtime')
{
    global $GENERAL_ERROR_LOG_PATH, $RECORDED_SERVER_ERROR_IDS;

    $logId = logFileGenerationIssue(
        $label,
        array_merge(['request' => collectRequestDebugInfo()], $context),
        $GENERAL_ERROR_LOG_PATH,
        $category
    );
    if (!isset($RECORDED_SERVER_ERROR_IDS) || !is_array($RECORDED_SERVER_ERROR_IDS)) {
        $RECORDED_SERVER_ERROR_IDS = [];
    }
    $RECORDED_SERVER_ERROR_IDS[] = $logId;

    return $logId;
}

function renderFriendlyErrorMessage($logId)
{
    static $rendered = false;
    if ($rendered) {
        return;
    }
    $rendered = true;

    $safeLogId = htmlspecialchars((string) $logId, ENT_QUOTES, 'UTF-8');
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/html; charset=UTF-8');
    }

    echo '<div style="direction: rtl; padding: 16px; margin: 12px; border: 1px solid #f87171; background: #fef2f2; color: #991b1b; font-family: Arial, sans-serif; border-radius: 8px;">';
    echo 'حدث خطأ غير متوقع وتم تسجيل تفاصيله. نرجو إرسال رمز الخطأ التالي للدعم الفني: ';
    echo '<strong style="font-family: monospace;">' . $safeLogId . '</strong>';
    echo '</div>';
}

function mqHandlePhpError($severity, $message, $file, $line)
{
    if (!(error_reporting() & $severity)) {
        return false;
    }

    recordServerError('php_error', [
        'severity' => $severity,
        'message' => $message,
        'file' => $file,
        'line' => $line,
    ], 'php_error');

    return false; // Preserve normal error handling flow
}

function mqHandleException(Throwable $exception)
{
    $logId = recordServerError('unhandled_exception', [
        'message' => $exception->getMessage(),
        'file' => $exception->getFile(),
        'line' => $exception->getLine(),
        'trace' => $exception->getTraceAsString(),
    ], 'php_exception');

    renderFriendlyErrorMessage($logId);
    exit(1);
}

function mqHandleShutdown()
{
    $lastError = error_get_last();
    if ($lastError && in_array($lastError['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) {
        $logId = recordServerError('shutdown_fatal_error', $lastError, 'php_fatal');
        renderFriendlyErrorMessage($logId);
    }
}

set_error_handler('mqHandlePhpError');
set_exception_handler('mqHandleException');
register_shutdown_function('mqHandleShutdown');

// SQLite log DB initializes lazily on first log write to avoid work on every page hit.
// وظائف مساعدة للـ API
function makeApiRequest($endpoint, $method = 'GET', $data = null)
{
    global $API_CONFIG;
    $url = $API_CONFIG['baseUrl'] . '/api/database/' . $endpoint;

    $res = makeBaserowRequest($url, $method, $data);

    if (isset($res['error']) && $res['error'] === true) {
        throw new Exception($res['message'] ?? 'API request failed');
    }

    return $res;
}


function makeBaserowRequest($url, $method = 'GET', $data = null)
{
    global $API_CONFIG;

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Token ' . $API_CONFIG['token'],
        'Content-Type: application/json'
    ]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        if ($data) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        }
    } elseif ($method === 'PATCH') {
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PATCH');
        if ($data) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        }
    }

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($ch);
    curl_close($ch);

    if (!empty($curl_error)) {
        error_log('CURL Error: ' . $curl_error);
        return ['error' => true, 'message' => 'خطأ في الاتصال'];
    }

    if ($http_code >= 200 && $http_code < 300) {
        $decoded = json_decode($response, true);
        return $decoded ?: [];
    }

    return ['error' => true, 'message' => 'خطأ في الاتصال - كود: ' . $http_code];
}

/**
 * إشعار واتساب لمن أنشأ عرض السعر عند إضافة ملاحظة من مستخدم آخر (يفترض رقم جوال في جدول المستخدمين).
 */
function mq_try_notify_quote_creator_whatsapp_on_note(int $quoteId, int $noteAuthorUserId, string $noteText): void
{
    global $API_CONFIG, $FIELDS;

    $disable = getenv('ALFA_DISABLE_MQ_NOTE_CREATOR_WHATSAPP') ?: '';
    if ($disable !== '' && $disable !== '0') {
        return;
    }

    try {
        $quotesTableId = (int) ($API_CONFIG['quotesTableId'] ?? 0);
        $usersTableId = (int) ($API_CONFIG['usersTableId'] ?? 0);
        if ($quotesTableId <= 0 || $usersTableId <= 0 || $quoteId <= 0) {
            return;
        }

        $quoteUrl = $API_CONFIG['baseUrl'] . '/api/database/rows/table/' . $quotesTableId . '/' . $quoteId . '/';
        $quoteRow = makeBaserowRequest($quoteUrl);
        if (isset($quoteRow['error']) || empty($quoteRow['id'])) {
            return;
        }

        $createdByField = $FIELDS['quotes']['createdBy'] ?? '';
        if ($createdByField === '') {
            return;
        }
        $creatorId = extractLinkedRowId($quoteRow[$createdByField] ?? null);
        if (!$creatorId || $creatorId === $noteAuthorUserId) {
            return;
        }

        $userUrl = $API_CONFIG['baseUrl'] . '/api/database/rows/table/' . $usersTableId . '/' . $creatorId . '/';
        $creatorRow = makeBaserowRequest($userUrl);
        if (isset($creatorRow['error']) || empty($creatorRow['id'])) {
            return;
        }

        $phoneField = $FIELDS['users']['phone'] ?? 'field_6773';
        $phoneRaw = trim((string) ($creatorRow[$phoneField] ?? ''));
        if ($phoneRaw === '') {
            return;
        }

        $quoteNum = (string) getQuoteNumberValue($quoteRow);
        if ($quoteNum === '') {
            $quoteNum = '#' . $quoteId;
        }

        $authorName = '';
        $authorUrl = $API_CONFIG['baseUrl'] . '/api/database/rows/table/' . $usersTableId . '/' . $noteAuthorUserId . '/';
        $authorRow = makeBaserowRequest($authorUrl);
        if (!isset($authorRow['error']) && !empty($authorRow['id'])) {
            $authorName = trim((string) ($authorRow[$FIELDS['users']['name']] ?? ''));
        }
        if ($authorName === '') {
            $authorName = 'مستخدم #' . $noteAuthorUserId;
        }

        $preview = $noteText;
        if (mb_strlen($preview) > 400) {
            $preview = mb_substr($preview, 0, 397) . '...';
        }

        $msg = "📝 *ملاحظة جديدة على عرض السعر*\n\n"
            . "رقم العرض: {$quoteNum}\n"
            . "من: {$authorName}\n\n"
            . $preview;

        $evoFile = __DIR__ . '/maintenance/components/evolution_api.php';
        if (!is_file($evoFile)) {
            error_log('mq quote note whatsapp: missing evolution_api at ' . $evoFile);
            return;
        }
        require_once $evoFile;
        if (!class_exists('EvolutionClient', false)) {
            return;
        }

        $apiKey = getenv('EVOLUTION_API_KEY') ?: 'D8D5E2D664C5-4D31-AC94-D78DDF10C70B';
        $baseEvo = rtrim((string) (getenv('EVOLUTION_BASE_URL') ?: 'https://evo.'), '/');
        $instance = (string) (getenv('EVOLUTION_INSTANCE') ?: '444');
        if ($apiKey === '' || $instance === '') {
            return;
        }

        $client = new EvolutionClient($baseEvo, $apiKey, $instance);
        $res = $client->sendText($phoneRaw, $msg);
        if (empty($res['success'])) {
            error_log('mq quote note whatsapp fail creator=' . $creatorId . ' ' . json_encode($res, JSON_UNESCAPED_UNICODE));
        }
    } catch (Throwable $e) {
        error_log('mq quote note whatsapp: ' . $e->getMessage());
    }
}

/**
 * واتساب عند تغيير حالة العرض: للمنشئ (إن فُعّل) وللعميل فقط للحالات المحددة في mu / الإعدادات.
 */
function mq_try_notify_whatsapp_on_quote_status_change(
    int $quoteId,
    int $newStatusId,
    string $statusLabel,
    int $actorUserId,
    string $rejectionReason = ''
): void {
    global $API_CONFIG, $FIELDS;

    $disable = getenv('ALFA_DISABLE_MQ_QUOTE_STATUS_WHATSAPP') ?: '';
    if ($disable !== '' && $disable !== '0') {
        return;
    }

    try {
        $quotesTableId = (int) ($API_CONFIG['quotesTableId'] ?? 0);
        $usersTableId = (int) ($API_CONFIG['usersTableId'] ?? 0);
        if ($quotesTableId <= 0 || $usersTableId <= 0 || $quoteId <= 0 || $newStatusId <= 0) {
            return;
        }
        if (
            !function_exists('alfa_mq_quote_status_wa_notify_creator_enabled')
            || !function_exists('alfa_mq_quote_status_wa_should_notify_client_for_status')
        ) {
            error_log('mq quote status whatsapp: settings helper missing; notification skipped');
            return;
        }

        $notifyCreator = alfa_mq_quote_status_wa_notify_creator_enabled();
        $notifyClient = alfa_mq_quote_status_wa_should_notify_client_for_status($newStatusId);
        if (!$notifyCreator && !$notifyClient) {
            return;
        }

        $quoteUrl = $API_CONFIG['baseUrl'] . '/api/database/rows/table/' . $quotesTableId . '/' . $quoteId . '/';
        $quoteRow = makeBaserowRequest($quoteUrl);
        if (isset($quoteRow['error']) || empty($quoteRow['id'])) {
            return;
        }

        $quoteNum = (string) getQuoteNumberValue($quoteRow);
        if ($quoteNum === '') {
            $quoteNum = '#' . $quoteId;
        }

        $phoneField = $FIELDS['users']['phone'] ?? 'field_6773';
        $nameField = $FIELDS['users']['name'] ?? 'field_6912';

        $actorName = '';
        $actorUrl = $API_CONFIG['baseUrl'] . '/api/database/rows/table/' . $usersTableId . '/' . $actorUserId . '/';
        $actorRow = makeBaserowRequest($actorUrl);
        if (!isset($actorRow['error']) && !empty($actorRow['id'])) {
            $actorName = trim((string) ($actorRow[$nameField] ?? ''));
        }
        if ($actorName === '') {
            $actorName = 'مستخدم #' . $actorUserId;
        }

        $statusLine = $statusLabel !== '' ? $statusLabel : ('حالة #' . $newStatusId);
        $refusalExtra = '';
        if ($rejectionReason !== '' && mb_strpos(mb_strtolower($statusLine), 'رفض') !== false) {
            $refusalExtra = "\nسبب الرفض: " . mb_substr($rejectionReason, 0, 400);
        }

        $evoFile = __DIR__ . '/maintenance/components/evolution_api.php';
        if (!is_file($evoFile)) {
            error_log('mq quote status whatsapp: missing evolution_api at ' . $evoFile);
            return;
        }
        require_once $evoFile;
        if (!class_exists('EvolutionClient', false)) {
            return;
        }

        $apiKey = getenv('EVOLUTION_API_KEY') ?: 'D8D5E2D664C5-4D31-AC94-D78DDF10C70B';
        $baseEvo = rtrim((string) (getenv('EVOLUTION_BASE_URL') ?: 'https://evo.'), '/');
        $instance = (string) (getenv('EVOLUTION_INSTANCE') ?: '444');
        if ($apiKey === '' || $instance === '') {
            return;
        }

        $evo = new EvolutionClient($baseEvo, $apiKey, $instance);

        $clientName = '';
        $clientPhone = '';
        $clientField = $FIELDS['quotes']['client'] ?? '';
        $clientRowId = $clientField !== '' ? extractLinkedRowId($quoteRow[$clientField] ?? null) : null;
        if ($clientRowId) {
            $clientUserUrl = $API_CONFIG['baseUrl'] . '/api/database/rows/table/' . $usersTableId . '/' . $clientRowId . '/';
            $clientRow = makeBaserowRequest($clientUserUrl);
            if (!isset($clientRow['error']) && !empty($clientRow['id'])) {
                $clientName = trim((string) ($clientRow[$nameField] ?? ''));
                $clientPhone = trim((string) ($clientRow[$phoneField] ?? ''));
            }
        }

        $projectName = '';
        $projectId = extractLinkedRowId($quoteRow['field_6788'] ?? null);
        if ($projectId) {
            $projUrl = $API_CONFIG['baseUrl'] . '/api/database/rows/table/706/' . $projectId . '/';
            $projRow = makeBaserowRequest($projUrl);
            if (!isset($projRow['error']) && !empty($projRow['id'])) {
                $projectName = trim((string) ($projRow['field_6916'] ?? ''));
            }
        }
        if ($projectName === '') {
            $projectName = 'مشروعك';
        }

        if ($notifyCreator) {
            $createdByField = $FIELDS['quotes']['createdBy'] ?? '';
            $creatorId = $createdByField !== '' ? extractLinkedRowId($quoteRow[$createdByField] ?? null) : null;
            if ($creatorId && $creatorId !== $actorUserId) {
                $creatorUserUrl = $API_CONFIG['baseUrl'] . '/api/database/rows/table/' . $usersTableId . '/' . $creatorId . '/';
                $creatorRow = makeBaserowRequest($creatorUserUrl);
                if (!isset($creatorRow['error']) && !empty($creatorRow['id'])) {
                    $cPhone = trim((string) ($creatorRow[$phoneField] ?? ''));
                    if ($cPhone !== '') {
                        $resolved = alfa_wt_resolve_message(
                            $API_CONFIG['baseUrl'],
                            ALFA_WT_PATH_MQ_QUOTE_STATUS_CREATOR,
                            [
                                'quoteNum' => $quoteNum,
                                'clientName' => $clientName,
                                'projectName' => $projectName,
                                'statusLabel' => $statusLine,
                                'actorName' => $actorName,
                                'rejectionReason' => $rejectionReason,
                                'rejectionLine' => $refusalExtra,
                            ]
                        );
                        $msgC = $resolved['msg'];
                        if ($msgC !== '') {
                            $res = $evo->sendText($cPhone, $msgC);
                            if (empty($res['success'])) {
                                error_log('mq quote status wa creator fail ' . json_encode($res, JSON_UNESCAPED_UNICODE));
                            }
                        }
                    }
                }
            }
        }

        if ($notifyClient) {
            if ($clientPhone !== '') {
                $clientGreeting = $clientName !== '' ? "عزيزنا {$clientName}\n\n" : '';
                $resolved = alfa_wt_resolve_message(
                    $API_CONFIG['baseUrl'],
                    ALFA_WT_PATH_MQ_QUOTE_STATUS_CLIENT,
                    [
                        'clientName' => $clientName,
                        'clientGreeting' => $clientGreeting,
                        'quoteNum' => $quoteNum,
                        'statusLabel' => $statusLine,
                        'rejectionReason' => $rejectionReason,
                        'rejectionLine' => $refusalExtra,
                    ]
                );
                $msgCl = $resolved['msg'];
                if ($msgCl !== '') {
                    $res = $evo->sendText($clientPhone, $msgCl);
                    if (empty($res['success'])) {
                        error_log('mq quote status wa client fail ' . json_encode($res, JSON_UNESCAPED_UNICODE));
                    }
                }
            }
        }
    } catch (Throwable $e) {
        error_log('mq quote status whatsapp: ' . $e->getMessage());
    }
}

function normalizeQuotesFilters($filters)
{
    $defaults = [
        'number' => [],
        'date' => ['from' => null, 'to' => null],
        'client' => [],
        'brand' => [],
        'user' => [],
        'status' => []
    ];
    if (!is_array($filters)) {
        return $defaults;
    }
    $normalized = $defaults;
    foreach ($defaults as $key => $value) {
        if (!array_key_exists($key, $filters)) {
            continue;
        }
        if ($key === 'date') {
            $incoming = is_array($filters[$key]) ? $filters[$key] : [];
            $normalized[$key]['from'] = $incoming['from'] ?? null;
            $normalized[$key]['to'] = $incoming['to'] ?? null;
        } else {
            $normalized[$key] = is_array($filters[$key]) ? $filters[$key] : [];
        }
    }
    return $normalized;
}

function buildQuotesOrderBy($sortBy, $sortDir)
{
    global $FIELDS;
    $sortBy = is_string($sortBy) ? trim($sortBy) : '';
    $sortDir = strtolower((string) $sortDir) === 'asc' ? 'asc' : 'desc';

    $fieldMap = [
        'number' => $FIELDS['quotes']['quoteNumber'] ?? null,
        'date' => $FIELDS['quotes']['date'] ?? null,
        'price' => $FIELDS['quotes']['totalPrice'] ?? null,
        'client' => $FIELDS['quotes']['client'] ?? null,
        'brand' => $FIELDS['quotes']['brand'] ?? null,
        'user' => $FIELDS['quotes']['createdBy'] ?? null,
        'status' => $FIELDS['quotes']['externalStatus'] ?? null,
    ];

    if ($sortBy === '' || !isset($fieldMap[$sortBy])) {
        $defaultField = $FIELDS['quotes']['date'] ?? null;
        if (!$defaultField) {
            return null;
        }
        return ($sortDir === 'asc' ? '' : '-') . $defaultField;
    }

    $field = $fieldMap[$sortBy];
    if (!$field) {
        return null;
    }

    return ($sortDir === 'asc' ? '' : '-') . $field;
}

function fetchQuotesPageRaw($page, $size, $userId = null, $orderBy = null)
{
    global $API_CONFIG, $FIELDS;

    $params = [
        'page' => max(1, (int) $page),
        'size' => max(1, (int) $size),
    ];

    if ($userId !== null && (int) $userId > 0) {
        $fieldKey = $FIELDS['quotes']['createdBy'];
        $params["filter__{$fieldKey}__link_row_has"] = (int) $userId;
    }

    if ($orderBy) {
        $params['order_by'] = $orderBy;
    }

    $queryString = '?' . http_build_query($params);
    $endpoint = "rows/table/{$API_CONFIG['quotesTableId']}/" . $queryString;

    return makeApiRequest($endpoint);
}

function canViewQuoteByStatusAccess(array $quote, array $externalStatuses, ?int $viewerUserId = null, bool $canViewAllQuotes = false, ?array $quoteStatusInfo = null): bool
{
    global $FIELDS, $QUOTES_CONTRACTS_STATUS_ACCESS;

    if (empty($QUOTES_CONTRACTS_STATUS_ACCESS['restrict_view'])) {
        return true;
    }

    $statusInfo = $quoteStatusInfo ?? getQuoteStatus($quote, $externalStatuses);
    $statusId = (int) ($statusInfo['status_id'] ?? 0);
    $categoryId = $statusInfo['status_category_id'] ?? null;
    if (in_array($statusId, $QUOTES_CONTRACTS_STATUS_ACCESS['view_statuses'], true)) {
        return true;
    }
    if ($categoryId !== null && in_array((int) $categoryId, $QUOTES_CONTRACTS_STATUS_ACCESS['view_categories'], true)) {
        return true;
    }

    // Keep drafts (no status) visible to their creator when restricted to own quotes.
    if ($statusId === 0 && !$canViewAllQuotes && $viewerUserId !== null && $viewerUserId > 0) {
        $createdById = extractLinkedRowId($quote[$FIELDS['quotes']['createdBy']] ?? null);
        if ($createdById !== null && (int) $createdById === (int) $viewerUserId) {
            return true;
        }
    }

    return false;
}

function quoteMatchesFilters(
    array $quote,
    array $activeFilters,
    array $users,
    array $externalStatuses,
    $showCancelled = false,
    ?int $viewerUserId = null,
    bool $canViewAllQuotes = false,
    ?DateTime $filterDateFrom = null,
    ?DateTime $filterDateTo = null,
    ?array $userNameById = null
) {
    global $FIELDS;

    $quoteStatusInfo = getQuoteStatus($quote, $externalStatuses);

    if (!canViewQuoteByStatusAccess($quote, $externalStatuses, $viewerUserId, $canViewAllQuotes, $quoteStatusInfo)) {
        return false;
    }

    // فلتر رقم العرض
    if (!empty($activeFilters['number'])) {
        $quoteNumber = convertToEnglishNumbers((string) getQuoteNumberValue($quote));
        if (!in_array($quoteNumber, $activeFilters['number'], true)) {
            return false;
        }
    }

    // فلتر التاريخ
    if ($filterDateFrom || $filterDateTo) {
        $quoteDate = new DateTime($quote[$FIELDS['quotes']['date']]);
        if ($filterDateFrom && $quoteDate < $filterDateFrom) {
            return false;
        }
        if ($filterDateTo && $quoteDate > $filterDateTo) {
            return false;
        }
    }

    // فلتر العميل
    if (!empty($activeFilters['client'])) {
        $clientName = getClientName($quote[$FIELDS['quotes']['client']] ?? []);
        if (!in_array($clientName, $activeFilters['client'], true)) {
            return false;
        }
    }

    // فلتر البراند
    if (!empty($activeFilters['brand'])) {
        $brandName = getBrandName($quote[$FIELDS['quotes']['brand']] ?? []);
        if (!in_array($brandName, $activeFilters['brand'], true)) {
            return false;
        }
    }

    // فلتر المستخدم
    if (!empty($activeFilters['user'])) {
        $userName = getUserName($quote[$FIELDS['quotes']['createdBy']] ?? [], $users, $userNameById);
        if (!in_array($userName, $activeFilters['user'], true)) {
            return false;
        }
    }

    // فلتر الحالة
    if (!empty($activeFilters['status'])) {
        if (!in_array($quoteStatusInfo['status'], $activeFilters['status'], true)) {
            return false;
        }
    }

    if (!$showCancelled) {
        if (isCancelledStatusLabel($quoteStatusInfo['status'] ?? '')) {
            return false;
        }
    }

    return true;
}

function buildQuotesBatch(array $options)
{
    $defaults = [
        'user_id' => null,
        'can_view_all' => false,
        'cursor' => ['page' => 1, 'offset' => 0],
        'limit' => 5,
        'scan_size' => 100,
        'active_filters' => [],
        'sort_by' => '',
        'sort_dir' => 'desc',
        'external_statuses' => [],
        'users' => [],
        'show_cancelled' => false,
    ];
    $opts = array_merge($defaults, $options);

    $cursor = is_array($opts['cursor']) ? $opts['cursor'] : [];
    $page = max(1, (int) ($cursor['page'] ?? 1));
    $offset = max(0, (int) ($cursor['offset'] ?? 0));
    $limit = max(1, (int) $opts['limit']);
    $scanSize = max(10, (int) $opts['scan_size']);
    $activeFilters = normalizeQuotesFilters($opts['active_filters']);
    $orderBy = buildQuotesOrderBy($opts['sort_by'], $opts['sort_dir']);

    $filterDateFrom = null;
    $filterDateTo = null;
    if (!empty($activeFilters['date']['from'])) {
        $filterDateFrom = new DateTime($activeFilters['date']['from']);
    }
    if (!empty($activeFilters['date']['to'])) {
        $filterDateTo = new DateTime($activeFilters['date']['to'] . ' 23:59:59');
    }
    $userNameById = !empty($activeFilters['user']) ? buildUserNameMap($opts['users']) : null;

    $results = [];
    $hasMore = false;
    $nextCursor = null;
    $lastPageCount = 0;
    $lastResponseNext = null;
    $lastPage = $page;
    $lastOffset = $offset;

    while (count($results) < $limit) {
        $response = fetchQuotesPageRaw($page, $scanSize, $opts['user_id'], $orderBy);
        if (!is_array($response)) {
            break;
        }
        if (isset($response['error'])) {
            break;
        }
        $rows = $response['results'] ?? [];
        $lastResponseNext = $response['next'] ?? null;
        $lastPageCount = count($rows);
        if ($lastPageCount === 0) {
            break;
        }

        for ($i = $offset; $i < $lastPageCount; $i++) {
            $row = $rows[$i];
            if (!is_array($row)) {
                continue;
            }
            if (
                !quoteMatchesFilters(
                    $row,
                    $activeFilters,
                    $opts['users'],
                    $opts['external_statuses'],
                    $opts['show_cancelled'],
                    $opts['user_id'],
                    (bool) $opts['can_view_all'],
                    $filterDateFrom,
                    $filterDateTo,
                    $userNameById
                )
            ) {
                continue;
            }
            $results[] = $row;
            if (count($results) >= $limit) {
                $lastPage = $page;
                $lastOffset = $i + 1;
                break 2;
            }
        }

        $lastPage = $page;
        $lastOffset = $lastPageCount;

        $page++;
        $offset = 0;
        if (empty($lastResponseNext) && $lastPageCount < $scanSize) {
            break;
        }
    }

    if (count($results) > 0) {
        $hasMore = ($lastOffset < $lastPageCount) || !empty($lastResponseNext);
        if ($hasMore) {
            if ($lastOffset >= $lastPageCount) {
                $nextCursor = [
                    'page' => $lastPage + 1,
                    'offset' => 0,
                ];
            } else {
                $nextCursor = [
                    'page' => $lastPage,
                    'offset' => $lastOffset,
                ];
            }
        }
    } else {
        $hasMore = false;
        $nextCursor = null;
    }

    return [
        'quotes' => $results,
        'has_more' => $hasMore,
        'next_cursor' => $nextCursor,
    ];
}

function loadFilterOptionsCache(string $cacheKey, int $ttlSeconds)
{
    if (!is_file($cacheKey)) {
        return null;
    }
    $mtime = @filemtime($cacheKey);
    if (!$mtime || (time() - $mtime) > $ttlSeconds) {
        return null;
    }
    $contents = @file_get_contents($cacheKey);
    if (!$contents) {
        return null;
    }
    $decoded = json_decode($contents, true);
    return is_array($decoded) ? $decoded : null;
}

function saveFilterOptionsCache(string $cacheKey, array $payload)
{
    ensureLogDirectoryExists($cacheKey);
    @file_put_contents($cacheKey, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

function fetchAllQuotesConcurrently($scanSize, $userId = null)
{
    global $API_CONFIG, $FIELDS;

    $params = [
        'page' => 1,
        'size' => max(1, (int) $scanSize),
    ];
    if ($userId !== null && (int) $userId > 0) {
        $fieldKey = $FIELDS['quotes']['createdBy'];
        $params["filter__{$fieldKey}__link_row_has"] = (int) $userId;
    }

    $queryString = '?' . http_build_query($params);
    $endpoint = "rows/table/{$API_CONFIG['quotesTableId']}/" . $queryString;

    $firstResponse = makeApiRequest($endpoint);
    if (!is_array($firstResponse) || isset($firstResponse['error']) || empty($firstResponse['results'])) {
        return [];
    }

    $allResults = $firstResponse['results'];
    $totalCount = (int) ($firstResponse['count'] ?? 0);
    $totalPages = ceil($totalCount / $scanSize);

    if ($totalPages <= 1) {
        return $allResults;
    }

    $mh = curl_multi_init();
    $curlHandles = [];

    for ($p = 2; $p <= $totalPages; $p++) {
        $pageParams = $params;
        $pageParams['page'] = $p;
        $pageQueryString = '?' . http_build_query($pageParams);
        $pageUrl = $API_CONFIG['baseUrl'] . '/api/database/rows/table/' . $API_CONFIG['quotesTableId'] . '/' . $pageQueryString;

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $pageUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Token ' . $API_CONFIG['token'],
            'Content-Type: application/json'
        ]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

        curl_multi_add_handle($mh, $ch);
        $curlHandles[$p] = $ch;
    }

    $active = null;
    do {
        $mrc = curl_multi_exec($mh, $active);
    } while ($mrc == CURLM_CALL_MULTI_PERFORM);

    while ($active && $mrc == CURLM_OK) {
        if (curl_multi_select($mh) != -1) {
            do {
                $mrc = curl_multi_exec($mh, $active);
            } while ($mrc == CURLM_CALL_MULTI_PERFORM);
        }
    }

    foreach ($curlHandles as $p => $ch) {
        $responseContent = curl_multi_getcontent($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($httpCode >= 200 && $httpCode < 300 && $responseContent) {
            $decoded = json_decode($responseContent, true);
            if (isset($decoded['results']) && is_array($decoded['results'])) {
                $allResults = array_merge($allResults, $decoded['results']);
            }
        }
        curl_multi_remove_handle($mh, $ch);
        curl_close($ch);
    }

    curl_multi_close($mh);

    return $allResults;
}

function buildFilterOptions(array $options)
{
    global $QUOTES_CONTRACTS_STATUS_ACCESS;
    $defaults = [
        'user_id' => null,
        'can_view_all' => false,
        'external_statuses' => [],
        'users' => [],
        'show_cancelled' => false,
        'scan_size' => 200,
    ];
    $opts = array_merge($defaults, $options);

    $numbers = [];
    $clients = [];
    $brands = [];
    $users = [];

    $userNameById = buildUserNameMap($opts['users']);

    $scanSize = max(10, (int) $opts['scan_size']);
    $allQuotes = fetchAllQuotesConcurrently($scanSize, $opts['user_id']);

    foreach ($allQuotes as $quote) {
        if (!is_array($quote)) {
            continue;
        }
        $quoteStatusInfo = getQuoteStatus($quote, $opts['external_statuses']);
        if (!$opts['show_cancelled']) {
            if (isCancelledStatusLabel($quoteStatusInfo['status'] ?? '')) {
                continue;
            }
        }
        if (!canViewQuoteByStatusAccess($quote, $opts['external_statuses'], $opts['user_id'], (bool) $opts['can_view_all'], $quoteStatusInfo)) {
            continue;
        }
        $quoteNumber = convertToEnglishNumbers((string) getQuoteNumberValue($quote));
        if ($quoteNumber !== '') {
            $numbers[$quoteNumber] = true;
        }
        $clientName = getClientName($quote[$GLOBALS['FIELDS']['quotes']['client']] ?? []);
        if ($clientName !== 'غير محدد') {
            $clients[$clientName] = true;
        }
        $brandName = getBrandName($quote[$GLOBALS['FIELDS']['quotes']['brand']] ?? []);
        if ($brandName !== 'غير محدد') {
            $brands[$brandName] = true;
        }
        $userName = getUserName($quote[$GLOBALS['FIELDS']['quotes']['createdBy']] ?? [], $opts['users'], $userNameById);
        if ($userName !== 'غير محدد') {
            $users[$userName] = true;
        }
    }

    $numbers = array_keys($numbers);
    usort($numbers, function ($a, $b) {
        $aNum = is_numeric($a) ? (float) $a : null;
        $bNum = is_numeric($b) ? (float) $b : null;
        if ($aNum !== null && $bNum !== null) {
            return $bNum <=> $aNum;
        }
        return strnatcmp($b, $a);
    });
    $clients = array_keys($clients);
    sort($clients, SORT_STRING);
    $brands = array_keys($brands);
    sort($brands, SORT_STRING);
    $users = array_keys($users);
    sort($users, SORT_STRING);

    return [
        'number' => $numbers,
        'client' => $clients,
        'brand' => $brands,
        'user' => $users,
    ];
}

// تحديث حالة العرض
function updateQuoteStatus($quoteId, $statusId, $statusLabel = '', $rejectionReason = '')
{
    global $API_CONFIG, $FIELDS;

    $updateData = [
        $FIELDS['quotes']['externalStatus'] => $statusId !== null ? [intval($statusId)] : [],
        $FIELDS['quotes']['approvalTime'] => date('Y-m-d\TH:i:s')
    ];

    if ($statusLabel !== '' && mb_strpos(mb_strtolower($statusLabel), 'رفض') !== false && !empty($rejectionReason)) {
        $updateData[$FIELDS['quotes']['rejectionReason']] = $rejectionReason;
    } elseif ($statusLabel === '' || mb_strpos(mb_strtolower($statusLabel), 'رفض') === false) {
        $updateData[$FIELDS['quotes']['rejectionReason']] = '';
    }

    $url = $API_CONFIG['baseUrl'] . '/api/database/rows/table/' . $API_CONFIG['quotesTableId'] . '/' . $quoteId . '/';
    $result = makeBaserowRequest($url, 'PATCH', $updateData);

    return !isset($result['error']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $incomingAction = $_POST['action'] ?? '';
    if ($incomingAction === 'log_issue') {
        header('Content-Type: application/json');
        $reason = trim((string) ($_POST['reason'] ?? ''));
        $payload = $_POST['payload'] ?? [];
        if (is_string($payload)) {
            $decoded = json_decode($payload, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $payload = $decoded;
            } else {
                $payload = ['raw' => mb_substr($payload, 0, 500)];
            }
        } elseif (!is_array($payload)) {
            $payload = ['raw' => (string) $payload];
        }
        $issueReason = $reason !== '' ? $reason : 'unspecified_issue';
        $logId = storeErrorLog(
            'client_report',
            $issueReason,
            array_merge($payload, [
                'request' => collectRequestDebugInfo(),
            ]),
            [
                'user_id' => $current_user_id ?? null,
                'ip' => $_SERVER['REMOTE_ADDR'] ?? null,
                'agent' => isset($_SERVER['HTTP_USER_AGENT']) ? sanitizeLogPayload($_SERVER['HTTP_USER_AGENT']) : null,
            ],
            true
        );
        logDiagnosticEvent('issue_reported', [
            'reason' => $issueReason,
            'payload' => $payload,
            'ajax' => isset($_POST['ajax']),
            'log_id' => $logId
        ]);
        echo json_encode([
            'success' => true,
            'log_id' => $logId
        ]);
        exit;
    } elseif ($incomingAction === 'track_event') {
        header('Content-Type: application/json');
        $eventName = trim((string) ($_POST['event'] ?? ''));
        $payload = $_POST['payload'] ?? [];
        if (is_string($payload)) {
            $decoded = json_decode($payload, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $payload = $decoded;
            } else {
                $payload = ['raw' => mb_substr($payload, 0, 500)];
            }
        } elseif (!is_array($payload)) {
            $payload = ['raw' => (string) $payload];
        }
        logDiagnosticEvent($eventName !== '' ? $eventName : 'client_action', [
            'payload' => $payload,
            'ajax' => isset($_POST['ajax']),
        ]);
        echo json_encode(['success' => true]);
        exit;
    }
}

$permissionManager = alfa_permissions($current_user_id);
$hasFullAccess = $permissionManager->hasFullAccess();
// quotes_pipeline.view_all = كل العروض من حيث المنشئ (لا فلترة على createdBy). تقييد «حالات العرض» في الجدول يأتي من quotes_contracts عند تفعيله، ويطبّق حتى مع view_all.
$canViewAllQuotes = $permissionManager->can('quotes_pipeline', 'view_all');
$legacyCanChangeQuoteStatus = $permissionManager->can('quotes_pipeline', 'change_status');
$canAddQuoteNotes = $permissionManager->can('quotes_pipeline', 'add_notes');
// PDF عرض السعر: صراحةً فقط (لا يرتبط بعرض الجدول view_own/view_all).
$canViewQuotePdfs = $hasFullAccess
    || $permissionManager->can('quotes_pipeline.pdf_access', 'view_quote_pdf')
    || $permissionManager->can('quotes_pipeline', 'edit_pdf');
$canEditQuoteWord = $permissionManager->can('quotes_pipeline', 'edit_word');
$canWordSalesContractDoc = $permissionManager->can('sales_contract_documents', 'edit_word');
$canWordHandoverDoc = $permissionManager->can('handover_documents', 'edit_word');
$canWordMaintenanceHandoverDoc = $permissionManager->can('maintenance_documents', 'edit_word');
$canShowWordToolbarMenu = $canEditQuoteWord || $canWordSalesContractDoc || $canWordHandoverDoc || $canWordMaintenanceHandoverDoc;
$canRegenerateQuoteDocuments = $permissionManager->can('quotes_pipeline', 'regenerate_documents');
$canDownloadSensitiveDocs = $permissionManager->can('quotes_pipeline', 'view_contracts');
$canPdfHandoverDoc = $hasFullAccess
    || $permissionManager->can('handover_documents', 'edit_pdf')
    || $canDownloadSensitiveDocs;
$canPdfMaintenanceHandoverDoc = $hasFullAccess
    || $permissionManager->can('maintenance_documents', 'edit_pdf')
    || $canDownloadSensitiveDocs;
$canViewPayments = $permissionManager->can('quotes_payments', 'view');
$canViewQuoteTablePrice = $permissionManager->can('quotes_pipeline', 'view_table_price');
$canCreateQuote = $permissionManager->can('quotes_pipeline', 'create_quote');
$canViewApprovedMaintQuoteFiles = $permissionManager->can('trkeb', 'view_approved_maint_quotes');
$showCancelled = false;
if (isset($_REQUEST['show_cancelled'])) {
    $cancelFlag = strtolower(trim((string) $_REQUEST['show_cancelled']));
    $showCancelled = in_array($cancelFlag, ['1', 'true', 'yes', 'on'], true);
}

$quotesContractsConfig = [];
if (method_exists($permissionManager, 'quotesContractsConfig')) {
    $quotesContractsConfig = $permissionManager->quotesContractsConfig();
}
$quotesContractsViewStatuses = normalizeIdList($quotesContractsConfig['view_statuses'] ?? $quotesContractsConfig['viewStatuses'] ?? []);
$quotesContractsViewCategories = normalizeIdList($quotesContractsConfig['view_categories'] ?? $quotesContractsConfig['viewCategories'] ?? []);
$quotesContractsActionStatuses = normalizeIdList($quotesContractsConfig['action_statuses'] ?? $quotesContractsConfig['actionStatuses'] ?? []);
$quotesContractsActionCategories = normalizeIdList($quotesContractsConfig['action_categories'] ?? $quotesContractsConfig['actionCategories'] ?? []);
$quotesContractsDataSections = is_array($quotesContractsConfig['data_sections'] ?? null) ? $quotesContractsConfig['data_sections'] : [];
$quotesContractsHasFullAccess = (bool) ($quotesContractsConfig['has_full_access'] ?? $hasFullAccess);
$quotesContractsActionScope = !empty($quotesContractsActionStatuses) || !empty($quotesContractsActionCategories);
$canChangeQuoteStatus = $legacyCanChangeQuoteStatus || $quotesContractsHasFullAccess || $quotesContractsActionScope;
$canTransitionOfferToContract = $canChangeQuoteStatus || $permissionManager->can('quotes_pipeline', 'transition_offer_to_contract');
$canTransitionContractToApproved = $canChangeQuoteStatus || $permissionManager->can('quotes_pipeline', 'transition_contract_to_approved');
$canTransitionToCancelled = $canChangeQuoteStatus || $permissionManager->can('quotes_pipeline', 'transition_to_cancelled');
$quotesContractsRestrictView = !$quotesContractsHasFullAccess && (!empty($quotesContractsViewStatuses) || !empty($quotesContractsViewCategories));
$quotesContractsRestrictAction = !$quotesContractsHasFullAccess && (!empty($quotesContractsActionStatuses) || !empty($quotesContractsActionCategories));
$QUOTES_CONTRACTS_STATUS_ACCESS = [
    'has_full_access' => $quotesContractsHasFullAccess,
    'view_statuses' => $quotesContractsViewStatuses,
    'view_categories' => $quotesContractsViewCategories,
    'action_statuses' => $quotesContractsActionStatuses,
    'action_categories' => $quotesContractsActionCategories,
    'data_sections' => $quotesContractsDataSections,
    'restrict_view' => $quotesContractsRestrictView,
    'restrict_action' => $quotesContractsRestrictAction,
];

$statusTransitionRules = [
    'لإعتماد العرض من العميل' => [
        [
            'targets' => ['لإعتماد العقد من الادارة'],
            'allowed' => $canTransitionOfferToContract,
        ],
    ],
    'لإعتماد العقد من العميل' => [
        [
            'targets' => ['معتمد'],
            'allowed' => $canTransitionContractToApproved,
        ],
    ],
];

$externalStatuses = [];
// معالجة تحديث الحالة
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_status') {
    $quoteId = intval($_POST['quote_id'] ?? 0);
    $rawStatusId = $_POST['status_id'] ?? '';
    $rawStatusId = is_array($rawStatusId) ? '' : trim((string) $rawStatusId);
    $statusId = $rawStatusId === '' ? null : intval($rawStatusId);
    $rejectionReason = trim((string) ($_POST['rejection_reason'] ?? ''));
    $currentQuoteStatus = null;
    $currentStatusLabel = '';
    $currentStatusId = null;
    $transitionDeniedMessage = 'غير مسموح لك بتغيير الحالة إلى هذه القيمة';
    $statusLogContext = [
        'quote_id' => $quoteId,
        'requested_status_id' => $statusId,
        'raw_status_id' => $rawStatusId,
        'ajax' => isset($_POST['ajax']),
        'rejection_reason_length' => mb_strlen($rejectionReason),
        'user_can_change' => $canChangeQuoteStatus,
    ];

    if (empty($externalStatuses)) {
        $externalStatuses = loadExternalStatuses();
    }

    $statusMeta = ($statusId !== null && isset($externalStatuses[$statusId])) ? $externalStatuses[$statusId] : null;
    $statusLabel = $statusMeta['label'] ?? '';
    if ($statusLabel !== '') {
        $statusLogContext['status_label'] = $statusLabel;
    }

    if (!empty($QUOTES_CONTRACTS_STATUS_ACCESS['restrict_action'])) {
        if ($statusId === null) {
            if (isset($_POST['ajax'])) {
                respondAjaxWithLog('update_status', [
                    'success' => false,
                    'message' => 'غير مسموح لك بإزالة الحالة الحالية'
                ], array_merge($statusLogContext, ['result' => 'remove_denied']));
            }
        } elseif ($statusMeta) {
            $allowed = false;
            if (in_array((int) $statusId, $QUOTES_CONTRACTS_STATUS_ACCESS['action_statuses'], true)) {
                $allowed = true;
            }
            $categoryId = $statusMeta['category_id'] ?? null;
            if (!$allowed && $categoryId !== null && in_array((int) $categoryId, $QUOTES_CONTRACTS_STATUS_ACCESS['action_categories'], true)) {
                $allowed = true;
            }
            if (!$allowed) {
                if (isset($_POST['ajax'])) {
                    respondAjaxWithLog('update_status', [
                        'success' => false,
                        'message' => 'غير مسموح لك بتغيير الحالة إلى هذه القيمة'
                    ], array_merge($statusLogContext, ['result' => 'action_restricted']));
                }
            }
        }
    }

    $transitionAllowed = $canChangeQuoteStatus;
    if (!$transitionAllowed) {
        if ($quoteId <= 0) {
            if (isset($_POST['ajax'])) {
                respondAjaxWithLog('update_status', [
                    'success' => false,
                    'message' => 'معرّف العرض غير صالح'
                ], array_merge($statusLogContext, ['result' => 'invalid_quote']));
            }
        } else {
            if ($statusId === null) {
                if (isset($_POST['ajax'])) {
                    respondAjaxWithLog('update_status', [
                        'success' => false,
                        'message' => 'غير مسموح لك بإزالة الحالة الحالية'
                    ], array_merge($statusLogContext, ['result' => 'remove_denied']));
                }
            }
            if ($statusId !== null && !$statusMeta) {
                if (isset($_POST['ajax'])) {
                    respondAjaxWithLog('update_status', [
                        'success' => false,
                        'message' => 'يرجى اختيار حالة صحيحة'
                    ], array_merge($statusLogContext, ['result' => 'invalid_status']));
                }
            }
            $quoteUrl = $API_CONFIG['baseUrl'] . '/api/database/rows/table/' . $API_CONFIG['quotesTableId'] . '/' . $quoteId . '/';
            $quoteResponse = makeBaserowRequest($quoteUrl);
            if (isset($quoteResponse['error'])) {
                if (isset($_POST['ajax'])) {
                    respondAjaxWithLog('update_status', [
                        'success' => false,
                        'message' => 'خطأ في جلب بيانات العرض'
                    ], array_merge($statusLogContext, ['result' => 'quote_fetch_error']));
                }
            } else {
                $currentQuoteStatus = getQuoteStatus($quoteResponse, $externalStatuses);
                $currentStatusLabel = $currentQuoteStatus['status'] ?? '';
                $currentStatusId = $currentQuoteStatus['status_id'] ?? null;

                if ($statusId !== null && $statusMeta) {
                    if (isCancelledStatusLabel($statusLabel)) {
                        $transitionAllowed = $canTransitionToCancelled;
                    } else {
                        $transitionAllowed = false;
                        $rules = $statusTransitionRules[$currentStatusLabel] ?? [];
                        foreach ($rules as $rule) {
                            $targets = $rule['targets'] ?? [];
                            $allowedFlag = (bool) ($rule['allowed'] ?? false);
                            if ($allowedFlag && in_array($statusLabel, $targets, true)) {
                                $transitionAllowed = true;
                                break;
                            }
                        }
                    }
                }

                if (!$transitionAllowed) {
                    if (isset($_POST['ajax'])) {
                        respondAjaxWithLog('update_status', [
                            'success' => false,
                            'message' => $transitionDeniedMessage
                        ], array_merge($statusLogContext, ['result' => 'transition_denied', 'current_status' => $currentStatusLabel]));
                    }
                }
            }
        }
    }

    if ($statusId !== null && $statusMeta && !isCancelledStatusLabel($statusLabel)) {
        if (!$currentQuoteStatus) {
            $quoteUrl = $API_CONFIG['baseUrl'] . '/api/database/rows/table/' . $API_CONFIG['quotesTableId'] . '/' . $quoteId . '/';
            $quoteResponse = makeBaserowRequest($quoteUrl);
            if (isset($quoteResponse['error'])) {
                if (isset($_POST['ajax'])) {
                    respondAjaxWithLog('update_status', [
                        'success' => false,
                        'message' => 'خطأ في جلب بيانات العرض'
                    ], array_merge($statusLogContext, ['result' => 'quote_fetch_error']));
                }
            } else {
                $currentQuoteStatus = getQuoteStatus($quoteResponse, $externalStatuses);
                $currentStatusLabel = $currentQuoteStatus['status'] ?? '';
                $currentStatusId = $currentQuoteStatus['status_id'] ?? null;
            }
        }

        $nextStatusId = getNextSequentialStatusId($currentStatusId, $externalStatuses);
        if (!$nextStatusId || (int) $statusId !== (int) $nextStatusId) {
            $statusLogContext['current_status'] = $currentStatusLabel;
            $statusLogContext['next_status_id'] = $nextStatusId;
            $transitionDeniedMessage = 'لا يمكن الانتقال إلا للحالة القادمة حسب التسلسل';
            if (isset($_POST['ajax'])) {
                respondAjaxWithLog('update_status', [
                    'success' => false,
                    'message' => $transitionDeniedMessage
                ], array_merge($statusLogContext, ['result' => 'sequence_denied']));
            }
        }
    }

    if ($statusId !== null && !$statusMeta) {
        if (isset($_POST['ajax'])) {
            respondAjaxWithLog('update_status', [
                'success' => false,
                'message' => 'يرجى اختيار حالة صحيحة'
            ], array_merge($statusLogContext, ['result' => 'missing_status']));
        }
    }

    if ($quoteId > 0 && ($statusId === null || $statusMeta)) {
        $result = updateQuoteStatus($quoteId, $statusId, $statusLabel, $rejectionReason);
        if ($result && $statusId !== null && $statusMeta) {
            $rr = ($statusMeta && mb_strpos(mb_strtolower($statusMeta['label'] ?? ''), 'رفض') !== false) ? $rejectionReason : '';
            mq_try_notify_whatsapp_on_quote_status_change($quoteId, $statusId, $statusLabel, $current_user_id, $rr);
        }
        $actionMeta = null;
        if ($result && $statusId !== null && $statusLabel !== '' && $current_user_id > 0) {
            createStatusActionLog($quoteId, $current_user_id, $statusId, $statusLabel);
            $nowIso = date('c');
            $actionMeta = [
                'user_id' => $current_user_id,
                'time' => $nowIso,
                'time_formatted' => formatDateTimeWithTime($nowIso)
            ];
        }

        if (isset($_POST['ajax'])) {
            respondAjaxWithLog('update_status', [
                'success' => $result,
                'message' => $result ? 'تم تحديث الحالة بنجاح' : 'حدث خطأ في التحديث',
                'status_label' => $statusLabel !== '' ? $statusLabel : 'لا يوجد حالة',
                'status_id' => $statusId,
                'status' => $statusMeta ? array_merge(['id' => $statusId], $statusMeta) : null,
                'rejection_reason' => ($statusMeta && mb_strpos(mb_strtolower($statusMeta['label'] ?? ''), 'رفض') !== false) ? $rejectionReason : '',
                'action_user_id' => $actionMeta['user_id'] ?? null,
                'action_time' => $actionMeta['time'] ?? null,
                'action_time_formatted' => $actionMeta['time_formatted'] ?? ''
            ], array_merge($statusLogContext, [
                    'result' => $result ? 'success' : 'failure',
                    'status_label' => $statusLabel !== '' ? $statusLabel : 'لا يوجد حالة'
                ]));
        }
    } elseif (isset($_POST['ajax'])) {
        respondAjaxWithLog('update_status', [
            'success' => false,
            'message' => 'يرجى اختيار حالة صحيحة'
        ], array_merge($statusLogContext, ['result' => 'invalid_state']));
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_note') {
    global $API_CONFIG, $NOTE_FIELDS, $current_user_id;

    header('Content-Type: application/json');
    $quoteId = intval($_POST['quote_id'] ?? 0);
    $noteText = trim((string) ($_POST['note_text'] ?? ''));
    $noteLogContext = [
        'quote_id' => $quoteId,
        'note_length' => mb_strlen($noteText),
        'ajax' => true,
    ];

    if (!$canAddQuoteNotes) {
        respondAjaxWithLog('add_note', ['success' => false, 'message' => 'لا تملك صلاحية إضافة الملاحظات على العرض.'], array_merge($noteLogContext, ['result' => 'permission_denied']));
    }

    if ($quoteId <= 0) {
        respondAjaxWithLog('add_note', ['success' => false, 'message' => 'معرّف العرض غير صالح'], array_merge($noteLogContext, ['result' => 'invalid_quote']));
    }

    if ($noteText === '') {
        respondAjaxWithLog('add_note', ['success' => false, 'message' => 'يرجى إدخال نص للملاحظة'], array_merge($noteLogContext, ['result' => 'empty_note']));
    }

    if (mb_strlen($noteText) > 2000) {
        respondAjaxWithLog('add_note', ['success' => false, 'message' => 'يرجى تقليل نص الملاحظة (الحد الأقصى 2000 حرف)'], array_merge($noteLogContext, ['result' => 'note_too_long']));
    }

    $payload = [
        $NOTE_FIELDS['text'] => $noteText,
        $NOTE_FIELDS['author'] => [$current_user_id],
        $NOTE_FIELDS['quoteLink'] => [$quoteId]
    ];

    $createUrl = $API_CONFIG['baseUrl'] . '/api/database/rows/table/' . $API_CONFIG['notesTableId'] . '/';
    $createResponse = makeBaserowRequest($createUrl, 'POST', $payload);

    if (isset($createResponse['error'])) {
        respondAjaxWithLog('add_note', [
            'success' => false,
            'message' => 'تعذر حفظ الملاحظة حالياً، حاول مرة أخرى لاحقاً'
        ], array_merge($noteLogContext, ['result' => 'create_failed']));
    }

    $noteRow = $createResponse;
    $noteId = $noteRow['id'] ?? null;

    if (!$noteId) {
        respondAjaxWithLog('add_note', [
            'success' => false,
            'message' => 'تم إنشاء الملاحظة ولكن تعذر جلب بياناتها'
        ], array_merge($noteLogContext, ['result' => 'missing_note_id']));
    }

    $noteDetailsUrl = $API_CONFIG['baseUrl'] . '/api/database/rows/table/' . $API_CONFIG['notesTableId'] . '/' . $noteId . '/';
    $noteDetails = makeBaserowRequest($noteDetailsUrl);

    if (!isset($noteDetails['error']) && isset($noteDetails['id'])) {
        $noteRow = $noteDetails;
    }

    $preparedNote = formatNoteForFrontend($noteRow);

    if (!$preparedNote) {
        respondAjaxWithLog('add_note', [
            'success' => false,
            'message' => 'تم حفظ الملاحظة لكن تعذر عرضها، أعد تحميل الصفحة'
        ], array_merge($noteLogContext, ['result' => 'formatting_failed']));
    }

    mq_try_notify_quote_creator_whatsapp_on_note($quoteId, $current_user_id, $noteText);

    respondAjaxWithLog('add_note', [
        'success' => true,
        'message' => 'تمت إضافة الملاحظة بنجاح',
        'note' => $preparedNote
    ], array_merge($noteLogContext, ['result' => 'success', 'note_id' => $noteId]));
}
// تحميل عروض الأسعار
function loadQuotes($userId = null)
{
    try {
        return fetchAllQuotesConcurrently(200, $userId);
    } catch (Exception $e) {
        return [];
    }
}
// تحميل المستخدمين
function loadUsers()
{
    global $API_CONFIG;
    try {
        $allUsers = [];
        $nextUrl = "rows/table/{$API_CONFIG['usersTableId']}/?size=100";
        while ($nextUrl) {
            $response = makeApiRequest($nextUrl);
            if (!isset($response['results']) || empty($response['results'])) {
                break;
            }
            $allUsers = array_merge($allUsers, $response['results']);
            if (empty($response['next'])) {
                break;
            }
            $parsed = parse_url($response['next']);
            $nextUrl = "rows/table/{$API_CONFIG['usersTableId']}/?" . ($parsed['query'] ?? '');
        }
        return $allUsers;
    } catch (Exception $e) {
        return [];
    }
}
// وظائف مساعدة لتنسيق البيانات
function convertToEnglishNumbers($str)
{
    if ($str === null) {
        return '';
    }

    $value = (string) $str;
    if ($value === '') {
        return '';
    }

    static $map = [
    '٠' => '0',
    '١' => '1',
    '٢' => '2',
    '٣' => '3',
    '٤' => '4',
    '٥' => '5',
    '٦' => '6',
    '٧' => '7',
    '٨' => '8',
    '٩' => '9',
    '۰' => '0',
    '۱' => '1',
    '۲' => '2',
    '۳' => '3',
    '۴' => '4',
    '۵' => '5',
    '۶' => '6',
    '۷' => '7',
    '۸' => '8',
    '۹' => '9'
    ];

    return strtr($value, $map);
}
function formatDate($dateString)
{
    if (!$dateString)
        return 'غير محدد';
    $date = new DateTime($dateString);
    return convertToEnglishNumbers($date->format('d/m/Y'));
}
function formatDateTimeWithTime($dateString)
{
    if (!$dateString)
        return '';
    try {
        $date = new DateTime($dateString);
    } catch (Exception $e) {
        return '';
    }
    $period = $date->format('A');
    $periodLabel = $period === 'AM' ? 'صباحاً' : 'مساءً';
    $formatted = sprintf(
        '%s - %s %s (%s)',
        $date->format('d/m/Y'),
        $date->format('h:i'),
        $period,
        $periodLabel
    );

    return convertToEnglishNumbers($formatted);
}
function formatPrice($price)
{
    if (!$price)
        return 'غير محدد';
    $formatted = number_format(round($price));
    $englishFormatted = convertToEnglishNumbers($formatted);
    return $englishFormatted . ' <img src="https:///images/sar.svg" alt="ريال سعودي" class="w-5 h-5 inline-block">';
}
function extractFirstScalarValue($value, $depth = 0)
{
    if ($depth > 3) {
        return '';
    }
    if (is_array($value)) {
        if (array_key_exists('value', $value)) {
            return extractFirstScalarValue($value['value'], $depth + 1);
        }
        foreach ($value as $item) {
            $resolved = extractFirstScalarValue($item, $depth + 1);
            if ($resolved !== '' && $resolved !== null) {
                return $resolved;
            }
        }
        return '';
    }
    if (is_object($value)) {
        return extractFirstScalarValue((array) $value, $depth + 1);
    }
    if (is_scalar($value)) {
        return $value;
    }
    return '';
}
function getQuoteNumberValue(array $quote)
{
    global $FIELDS;
    $candidates = [];
    if (isset($FIELDS['quotes']['quoteNumber'])) {
        $candidates[] = $quote[$FIELDS['quotes']['quoteNumber']] ?? null;
    }
    if (isset($FIELDS['quotes']['generated'])) {
        $candidates[] = $quote[$FIELDS['quotes']['generated']] ?? null;
    }
    $candidates[] = $quote['id'] ?? null;

    foreach ($candidates as $candidate) {
        $value = extractFirstScalarValue($candidate);
        if ($value !== '' && $value !== null) {
            return $value;
        }
    }

    return '';
}
function getClientName($clientArray)
{
    if (!$clientArray || !is_array($clientArray) || empty($clientArray))
        return 'غير محدد';
    return $clientArray[0]['value'] ?? 'غير محدد';
}
function getBrandName($brandArray)
{
    if (!$brandArray || !is_array($brandArray) || empty($brandArray))
        return 'غير محدد';
    $brandData = $brandArray[0];
    if (isset($brandData['value'])) {
        return is_array($brandData['value']) && isset($brandData['value']['value'])
            ? $brandData['value']['value']
            : $brandData['value'];
    }
    return 'غير محدد';
}
function getUserName($userArray, $users, ?array $userNameById = null)
{
    if (!$userArray || !is_array($userArray) || empty($userArray))
        return 'غير محدد';
    $userId = $userArray[0]['id'] ?? null;

    if ($userNameById !== null) {
        if ($userId === null || $userId === '') {
            return 'غير محدد';
        }
        return $userNameById[(int) $userId] ?? 'غير محدد';
    }

    foreach ($users as $user) {
        if ($user['id'] === $userId) {
            global $FIELDS;
            return $user[$FIELDS['users']['name']] ?? 'غير محدد';
        }
    }
    return 'غير محدد';
}
function loadNotesForQuotes(array $quoteIds, array $users = [])
{
    global $API_CONFIG, $NOTE_FIELDS;

    $quoteIds = array_values(array_unique(array_filter(array_map('intval', $quoteIds), function ($id) {
        return $id > 0;
    })));

    if (empty($quoteIds)) {
        return [];
    }

    $quoteFieldId = intval(substr($NOTE_FIELDS['quoteLink'], 6));
    $notesByQuote = [];
    $size = 200;

    // نجمع الاستعلام على دفعات صغيرة لتفادي URLs طويلة تسبب فشل الاتصال
    $chunks = array_chunk($quoteIds, 25);
    foreach ($chunks as $chunkIndex => $chunkIds) {
        $filtersPayload = json_encode([
            'filter_type' => 'OR',
            'filters' => array_map(function ($quoteId) use ($quoteFieldId) {
                return [
                    'field' => $quoteFieldId,
                    'type' => 'link_row_has',
                    'value' => (string) $quoteId
                ];
            }, $chunkIds)
        ]);

        $page = 1;
        do {
            $query = http_build_query([
                'page' => $page,
                'size' => $size,
                'filters' => $filtersPayload
            ]);

            $endpoint = "rows/table/{$API_CONFIG['notesTableId']}/?{$query}";
            try {
                $response = makeApiRequest($endpoint);
            } catch (Throwable $e) {
                // نسجل الخطأ لكن نواصل باقي الدُفعات حتى لا تتوقف الصفحة بالكامل
                recordServerError('notes_fetch_failed', [
                    'chunk_index' => $chunkIndex,
                    'chunk_size' => count($chunkIds),
                    'page' => $page,
                    'filters_length' => strlen($filtersPayload),
                    'error' => $e->getMessage()
                ], 'php_exception');
                break;
            }

            $results = $response['results'] ?? [];
            foreach ($results as $noteRow) {
                $formatted = formatNoteForFrontend($noteRow, $users);
                if (!$formatted) {
                    continue;
                }
                $linkedQuotes = $noteRow[$NOTE_FIELDS['quoteLink']] ?? [];
                if (!is_array($linkedQuotes)) {
                    continue;
                }
                foreach ($linkedQuotes as $link) {
                    $linkedId = $link['id'] ?? null;
                    if (!$linkedId) {
                        continue;
                    }
                    $notesByQuote[$linkedId][] = $formatted;
                }
            }

            $hasNext = !empty($response['next']);
            $page++;
        } while ($hasNext);
    }

    foreach ($notesByQuote as &$notesList) {
        usort($notesList, function ($a, $b) {
            $dateA = $a['created_at'] ?? '';
            $dateB = $b['created_at'] ?? '';
            if ($dateA !== $dateB) {
                return strcmp($dateB, $dateA);
            }
            return ($b['id'] ?? 0) <=> ($a['id'] ?? 0);
        });
    }
    unset($notesList);

    return $notesByQuote;
}
function extractLinkedRowId($value)
{
    if (!$value) {
        return null;
    }
    if (is_array($value)) {
        if (isset($value['id'])) {
            return (int) $value['id'];
        }
        $first = $value[0] ?? null;
        if (is_array($first) && isset($first['id'])) {
            return (int) $first['id'];
        }
    }
    return null;
}
function buildUserNameMap(array $users = [])
{
    global $FIELDS;
    $map = [];
    foreach ($users as $user) {
        $userId = $user['id'] ?? null;
        if (!$userId) {
            continue;
        }
        $name = trim((string) ($user[$FIELDS['users']['name']] ?? ''));
        if ($name !== '') {
            $map[(int) $userId] = $name;
        }
    }
    return $map;
}
function loadStatusActionsForQuotes(array $quoteIds, array $users = [])
{
    global $API_CONFIG, $FIELDS;

    $quoteIds = array_values(array_unique(array_filter(array_map('intval', $quoteIds), function ($id) {
        return $id > 0;
    })));

    if (empty($quoteIds)) {
        return [];
    }

    $tableId = (int) ($API_CONFIG['actionLogsTableId'] ?? 0);
    $fields = $FIELDS['action_logs'] ?? [];
    $quoteField = $fields['quote'] ?? '';
    $statusField = $fields['status'] ?? '';
    $userField = $fields['user'] ?? '';
    $dateField = $fields['date'] ?? '';
    $actionField = $fields['action'] ?? '';

    if ($tableId <= 0 || $quoteField === '' || $statusField === '') {
        return [];
    }

    $quoteFieldId = intval(substr($quoteField, 6));
    $actionsByQuote = [];
    $userMap = buildUserNameMap($users);
    $size = 200;

    $chunks = array_chunk($quoteIds, 25);
    foreach ($chunks as $chunkIndex => $chunkIds) {
        $filtersPayload = json_encode([
            'filter_type' => 'OR',
            'filters' => array_map(function ($quoteId) use ($quoteFieldId) {
                return [
                    'field' => $quoteFieldId,
                    'type' => 'link_row_has',
                    'value' => (string) $quoteId
                ];
            }, $chunkIds)
        ]);

        $page = 1;
        do {
            $query = http_build_query([
                'page' => $page,
                'size' => $size,
                'filters' => $filtersPayload
            ]);

            $endpoint = "rows/table/{$tableId}/?{$query}";
            try {
                $response = makeApiRequest($endpoint);
            } catch (Throwable $e) {
                recordServerError('status_actions_fetch_failed', [
                    'chunk_index' => $chunkIndex,
                    'chunk_size' => count($chunkIds),
                    'page' => $page,
                    'filters_length' => strlen($filtersPayload),
                    'error' => $e->getMessage()
                ], 'php_exception');
                break;
            }

            $results = $response['results'] ?? [];
            foreach ($results as $row) {
                $statusId = extractLinkedRowId($row[$statusField] ?? null);
                if (!$statusId) {
                    continue;
                }
                $userId = $userField !== '' ? extractLinkedRowId($row[$userField] ?? null) : null;
                $userName = $userId && isset($userMap[$userId]) ? $userMap[$userId] : '';
                $actionText = $actionField !== '' ? trim((string) ($row[$actionField] ?? '')) : '';
                $rawTime = $dateField !== '' ? ($row[$dateField] ?? null) : null;
                $timeText = $rawTime ? formatDateTimeWithTime($rawTime) : '';
                $timestamp = $rawTime ? strtotime($rawTime) : 0;

                $linkedQuotes = $row[$quoteField] ?? [];
                if (!is_array($linkedQuotes)) {
                    continue;
                }
                foreach ($linkedQuotes as $link) {
                    $quoteId = $link['id'] ?? null;
                    if (!$quoteId) {
                        continue;
                    }
                    $existingTs = $actionsByQuote[$quoteId][$statusId]['_ts'] ?? -1;
                    if ($timestamp >= $existingTs) {
                        $actionsByQuote[$quoteId][$statusId] = [
                            'user' => $userName,
                            'time' => $timeText,
                            'action' => $actionText,
                            '_ts' => $timestamp
                        ];
                    }
                }
            }

            $hasNext = !empty($response['next']);
            $page++;
        } while ($hasNext);
    }

    foreach ($actionsByQuote as $quoteId => $statusMap) {
        foreach ($statusMap as $statusId => $actionMeta) {
            unset($actionsByQuote[$quoteId][$statusId]['_ts']);
        }
    }

    return $actionsByQuote;
}
function createStatusActionLog($quoteId, $userId, $statusId, $statusLabel)
{
    global $API_CONFIG, $FIELDS;

    $tableId = (int) ($API_CONFIG['actionLogsTableId'] ?? 0);
    $fields = $FIELDS['action_logs'] ?? [];
    $userField = $fields['user'] ?? '';
    $actionField = $fields['action'] ?? '';
    $quoteField = $fields['quote'] ?? '';
    $statusField = $fields['status'] ?? '';

    if ($tableId <= 0 || $userField === '' || $actionField === '' || $quoteField === '') {
        return false;
    }
    if (!$quoteId || !$userId || $statusLabel === '') {
        return false;
    }

    $payload = [
        $userField => [(int) $userId],
        $quoteField => [(int) $quoteId],
        $actionField => mb_substr($statusLabel, 0, 200)
    ];
    if ($statusId !== null && $statusField !== '') {
        $payload[$statusField] = [(int) $statusId];
    }

    $endpoint = $API_CONFIG['baseUrl'] . '/api/database/rows/table/' . $tableId . '/';
    try {
        $resp = makeBaserowRequest($endpoint, 'POST', $payload);
        if (isset($resp['error'])) {
            return false;
        }
    } catch (Throwable $e) {
        return false;
    }
    return true;
}
function formatNoteForFrontend(array $noteRow, array $users = [])
{
    global $NOTE_FIELDS, $FIELDS;

    $noteId = $noteRow['id'] ?? null;
    if (!$noteId) {
        return null;
    }

    $noteText = trim((string) ($noteRow[$NOTE_FIELDS['text']] ?? ''));
    $createdAt = $noteRow[$NOTE_FIELDS['createdOn']] ?? ($noteRow['created_on'] ?? null);
    $authors = $noteRow[$NOTE_FIELDS['author']] ?? [];
    $authorNamesLookup = $noteRow[$NOTE_FIELDS['authorName']] ?? [];
    $authorName = 'غير معروف';
    $authorId = null;

    if (is_array($authorNamesLookup) && !empty($authorNamesLookup)) {
        $lookupEntry = $authorNamesLookup[0];
        $authorNameCandidate = trim((string) ($lookupEntry['value'] ?? ''));
        if ($authorNameCandidate !== '') {
            $authorName = $authorNameCandidate;
        }
        $authorId = $lookupEntry['id'] ?? null;
    }

    if (($authorName === '' || $authorName === 'غير معروف') && is_array($authors) && !empty($authors)) {
        $primaryAuthor = $authors[0];
        $authorId = $primaryAuthor['id'] ?? $authorId;
        $authorNameCandidate = trim((string) ($primaryAuthor['value'] ?? ''));
        if ($authorNameCandidate !== '') {
            $authorName = $authorNameCandidate;
        }
    }

    if (($authorName === '' || $authorName === 'غير معروف') && $authorId !== null && !empty($users)) {
        foreach ($users as $userRow) {
            if (($userRow['id'] ?? null) === $authorId) {
                $authorNameCandidate = $userRow[$FIELDS['users']['name']] ?? ($userRow['field_6912'] ?? '');
                if ($authorNameCandidate !== '') {
                    $authorName = $authorNameCandidate;
                }
                break;
            }
        }
    }

    if ($authorName === '' || $authorName === null) {
        $authorName = 'غير معروف';
    }

    return [
        'id' => $noteId,
        'text' => $noteText,
        'author' => $authorName,
        'author_id' => $authorId,
        'created_at' => $createdAt,
        'created_at_formatted' => $createdAt ? formatDateTimeWithTime($createdAt) : ''
    ];
}
function extractQuoteNotes(array $quoteRow, array $users = [])
{
    global $QUOTE_NOTES_CACHE;

    $quoteId = $quoteRow['id'] ?? null;
    if (!$quoteId) {
        return [];
    }

    if (isset($QUOTE_NOTES_CACHE[$quoteId])) {
        return $QUOTE_NOTES_CACHE[$quoteId];
    }

    return [];
}
function normalizeHexColor($hex)
{
    if (!$hex)
        return null;
    $hex = trim((string) $hex);
    if ($hex === '')
        return null;
    if ($hex[0] === '#') {
        $hex = substr($hex, 1);
    }
    if (strlen($hex) === 3) {
        $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
    }
    if (!preg_match('/^[0-9a-fA-F]{6}$/', $hex)) {
        return null;
    }
    return '#' . strtolower($hex);
}
function normalizeIdList($values)
{
    if (!is_array($values)) {
        return [];
    }
    $ids = [];
    foreach ($values as $value) {
        if (is_numeric($value)) {
            $ids[] = (int) $value;
            continue;
        }
        if (is_string($value)) {
            $trimmed = trim($value);
            if ($trimmed === '') {
                continue;
            }
            $parsed = intval($trimmed);
            if ($parsed > 0) {
                $ids[] = $parsed;
            }
        }
    }
    return array_values(array_unique($ids));
}
function extractStatusCategoryMeta($value): array
{
    $categoryId = null;
    $categoryLabel = '';

    if (is_object($value)) {
        return extractStatusCategoryMeta((array) $value);
    }

    if (is_array($value)) {
        $keys = array_keys($value);
        $isList = $keys === range(0, count($keys) - 1);
        if ($isList) {
            foreach ($value as $item) {
                [$id, $label] = extractStatusCategoryMeta($item);
                if ($id !== null || $label !== '') {
                    return [$id, $label];
                }
            }
            return [null, ''];
        }

        if (isset($value['id']) && is_numeric($value['id'])) {
            $categoryId = (int) $value['id'];
        }
        if (isset($value['value']) && is_scalar($value['value'])) {
            $categoryLabel = trim((string) $value['value']);
        } elseif (isset($value['label']) && is_scalar($value['label'])) {
            $categoryLabel = trim((string) $value['label']);
        }
        return [$categoryId, $categoryLabel];
    }

    if (is_numeric($value)) {
        return [(int) $value, ''];
    }

    if (is_string($value)) {
        return [null, trim($value)];
    }

    return [null, ''];
}
function getContrastColor($hex)
{
    $hex = normalizeHexColor($hex);
    if (!$hex)
        return '#1f2937';
    $r = hexdec(substr($hex, 1, 2));
    $g = hexdec(substr($hex, 3, 2));
    $b = hexdec(substr($hex, 5, 2));
    $luminance = (0.299 * $r + 0.587 * $g + 0.114 * $b) / 255;
    return $luminance > 0.6 ? '#1f2937' : '#ffffff';
}
function adjustColorBrightness($hex, $percent = 0.1)
{
    $hex = normalizeHexColor($hex);
    if (!$hex)
        return null;
    $percent = max(-1, min(1, $percent));
    $r = hexdec(substr($hex, 1, 2));
    $g = hexdec(substr($hex, 3, 2));
    $b = hexdec(substr($hex, 5, 2));
    if ($percent >= 0) {
        $r += (255 - $r) * $percent;
        $g += (255 - $g) * $percent;
        $b += (255 - $b) * $percent;
    } else {
        $r += $r * $percent;
        $g += $g * $percent;
        $b += $b * $percent;
    }
    $r = max(0, min(255, round($r)));
    $g = max(0, min(255, round($g)));
    $b = max(0, min(255, round($b)));
    return sprintf('#%02x%02x%02x', $r, $g, $b);
}
function hexToRgba($hex, $alpha = 0.18)
{
    $hex = normalizeHexColor($hex);
    if (!$hex)
        return null;
    $alpha = max(0, min(1, $alpha));
    $r = hexdec(substr($hex, 1, 2));
    $g = hexdec(substr($hex, 3, 2));
    $b = hexdec(substr($hex, 5, 2));
    return sprintf('rgba(%d, %d, %d, %.3f)', $r, $g, $b, $alpha);
}
function determineStatusIcon($label)
{
    $label = mb_strtolower(trim((string) $label));
    if ($label === '') {
        return 'fa-minus-circle';
    }
    if (mb_strpos($label, 'رفض') !== false) {
        return 'fa-times-circle';
    }
    if (mb_strpos($label, 'موافق') !== false || mb_strpos($label, 'قبول') !== false) {
        return 'fa-check-circle';
    }
    if (mb_strpos($label, 'انتظار') !== false) {
        return 'fa-clock';
    }
    if (mb_strpos($label, 'تعليق') !== false) {
        return 'fa-pause-circle';
    }
    return 'fa-circle';
}

function normalizeArabicText($text)
{
    $text = mb_strtolower(trim((string) $text));
    // Remove Arabic diacritics
    $text = preg_replace('/[\x{064B}-\x{065F}\x{0670}]/u', '', $text);
    return strtr($text, [
        'أ' => 'ا',
        'إ' => 'ا',
        'آ' => 'ا',
        'ى' => 'ي',
        'ؤ' => 'و',
        'ئ' => 'ي'
    ]);
}

function isCancelledStatusLabel($label)
{
    if ($label === null) {
        return false;
    }
    $normalized = normalizeArabicText($label);
    if ($normalized === '') {
        return false;
    }
    if (mb_strpos($normalized, 'ملغ') !== false) {
        return true;
    }
    return strpos($normalized, 'cancel') !== false;
}
function buildProgressStatuses(array $externalStatuses)
{
    $progress = [];
    foreach ($externalStatuses as $statusId => $meta) {
        $label = trim((string) ($meta['label'] ?? ''));
        if ($label === '' || isCancelledStatusLabel($label)) {
            continue;
        }
        $progress[] = [
            'id' => (int) $statusId,
            'label' => $label,
            'order' => $meta['order'] ?? 0
        ];
    }
    return $progress;
}
function getNextSequentialStatusId($currentStatusId, array $externalStatuses)
{
    $progress = buildProgressStatuses($externalStatuses);
    if (empty($progress)) {
        return null;
    }
    if (!$currentStatusId) {
        return $progress[0]['id'];
    }
    foreach ($progress as $index => $status) {
        if ((int) $status['id'] === (int) $currentStatusId) {
            return $progress[$index + 1]['id'] ?? null;
        }
    }
    return $progress[0]['id'];
}
// جلب جميع الحالات من الجدول الخارجي (730)
function loadExternalStatuses()
{
    try {
        $results = [];
        $nextUrl = "rows/table/730/?size=100";
        while ($nextUrl) {
            $response = makeApiRequest($nextUrl);
            if (!isset($response['results']) || empty($response['results'])) {
                break;
            }
            $results = array_merge($results, $response['results']);
            if (empty($response['next'])) {
                break;
            }
            $parsed = parse_url($response['next']);
            $nextUrl = "rows/table/730/?" . ($parsed['query'] ?? '');
        }
        $statuses = [];
        foreach ($results as $row) {
            $statusId = $row['id'] ?? null;
            if (!$statusId) {
                continue;
            }
            $label = trim((string) ($row['field_7313'] ?? ''));
            $color = normalizeHexColor($row['field_7319'] ?? '');
            $order = isset($row['field_7320']) ? intval($row['field_7320']) : 0;
            [$categoryId, $categoryLabel] = extractStatusCategoryMeta($row['field_8477'] ?? null);
            $hoverColor = $color ? adjustColorBrightness($color, 0.12) : null;
            $statuses[$statusId] = [
                'label' => $label !== '' ? $label : ('حالة #' . $statusId),
                'color' => $color,
                'text_color' => $color ? getContrastColor($color) : '#1f2937',
                'order' => $order,
                'icon' => determineStatusIcon($label),
                'hover_color' => $hoverColor,
                'category_id' => $categoryId,
                'category_label' => $categoryLabel,
            ];
        }
        uasort($statuses, function ($a, $b) {
            $orderA = $a['order'] ?? 0;
            $orderB = $b['order'] ?? 0;
            if ($orderA === $orderB) {
                return strcmp($a['label'], $b['label']);
            }
            return $orderA <=> $orderB;
        });
        return $statuses;
    } catch (Exception $e) {
        return [];
    }
}

function loadUsersForMqPage(string $cacheDir, int $ttlSeconds): array
{
    $cachePath = rtrim($cacheDir, '/\\') . DIRECTORY_SEPARATOR . 'mq-reference-users.json';
    $cached = loadFilterOptionsCache($cachePath, $ttlSeconds);
    if (is_array($cached) && isset($cached['users']) && is_array($cached['users'])) {
        return $cached['users'];
    }
    $users = loadUsers();
    saveFilterOptionsCache($cachePath, ['users' => $users, 'generated_at' => date('c')]);
    return $users;
}

function loadExternalStatusesForMqPage(string $cacheDir, int $ttlSeconds): array
{
    $cachePath = rtrim($cacheDir, '/\\') . DIRECTORY_SEPARATOR . 'mq-reference-statuses.json';
    $cached = loadFilterOptionsCache($cachePath, $ttlSeconds);
    if (is_array($cached) && isset($cached['statuses']) && is_array($cached['statuses'])) {
        return $cached['statuses'];
    }
    $statuses = loadExternalStatuses();
    saveFilterOptionsCache($cachePath, ['statuses' => $statuses, 'generated_at' => date('c')]);
    return $statuses;
}

// تعديل دالة الحالة لعرض اسم الحالة من الجدول الخارجي
function getQuoteStatus($quote, $externalStatuses = [])
{
    global $FIELDS;
    $linkedStatuses = $quote[$FIELDS['quotes']['externalStatus']] ?? [];
    $selectedStatus = null;
    if (is_array($linkedStatuses) && !empty($linkedStatuses)) {
        foreach ($linkedStatuses as $statusEntry) {
            $statusId = $statusEntry['id'] ?? null;
            if (!$statusId || !isset($externalStatuses[$statusId])) {
                continue;
            }
            $statusMeta = $externalStatuses[$statusId];
            $candidate = $statusMeta;
            $candidate['id'] = $statusId;
            if ($selectedStatus === null) {
                $selectedStatus = $candidate;
                continue;
            }
            $currentOrder = $selectedStatus['order'] ?? 0;
            $candidateOrder = $candidate['order'] ?? 0;
            if ($candidateOrder > $currentOrder) {
                $selectedStatus = $candidate;
            } elseif ($candidateOrder === $currentOrder && $statusId > ($selectedStatus['id'] ?? 0)) {
                $selectedStatus = $candidate;
            }
        }
    }
    if ($selectedStatus) {
        $statusId = $selectedStatus['id'];
        $status = $selectedStatus['label'] ?? 'لا يوجد حالة';
        $statusColor = $selectedStatus['color'] ?? null;
        $statusTextColor = $selectedStatus['text_color'] ?? '#1f2937';
        $statusIcon = $selectedStatus['icon'] ?? determineStatusIcon($status);
        $statusOrder = $selectedStatus['order'] ?? 0;
        $statusHoverColor = $selectedStatus['hover_color'] ?? null;
        $statusCategoryId = $selectedStatus['category_id'] ?? null;
        $statusCategoryLabel = $selectedStatus['category_label'] ?? '';
    } else {
        $statusId = null;
        $status = 'لا يوجد حالة';
        $statusColor = null;
        $statusTextColor = '#6b7280';
        $statusIcon = 'fa-minus-circle';
        $statusOrder = null;
        $statusHoverColor = null;
        $statusCategoryId = null;
        $statusCategoryLabel = '';
    }
    $rejectionReason = $quote[$FIELDS['quotes']['rejectionReason']] ?? '';
    $approvalTime = $quote[$FIELDS['quotes']['approvalTime']] ?? '';
    return [
        'status_id' => $statusId,
        'status' => $status,
        'status_color' => $statusColor,
        'status_text_color' => $statusTextColor,
        'status_icon' => $statusIcon,
        'status_hover_color' => $statusHoverColor,
        'status_order' => $statusOrder,
        'status_category_id' => $statusCategoryId,
        'status_category_label' => $statusCategoryLabel,
        'rejection_reason' => $rejectionReason,
        'approval_time' => $approvalTime
    ];
}

function removeCancelledQuotes(array $quotes, array $externalStatuses, $showCancelled = false)
{
    if ($showCancelled) {
        return $quotes;
    }
    return array_values(array_filter($quotes, function ($quote) use ($externalStatuses) {
        $statusInfo = getQuoteStatus($quote, $externalStatuses);
        return !isCancelledStatusLabel($statusInfo['status'] ?? '');
    }));
}

function renderQuotesTableRows(array $quotes, int $indexOffset, array $users, array $externalStatuses, array $permissions): string
{
    global $FIELDS;
    $canViewQuoteTablePrice = (bool) ($permissions['can_view_quote_table_price'] ?? false);
    $canViewQuotePdfs = (bool) ($permissions['can_view_quote_pdfs'] ?? false);
    $canDownloadSensitiveDocs = (bool) ($permissions['can_download_sensitive_docs'] ?? false);
    $canEditQuoteWord = (bool) ($permissions['can_edit_quote_word'] ?? false);
    $canWordSalesContractDoc = (bool) ($permissions['can_word_sales_contract'] ?? false);
    $canWordHandoverDoc = (bool) ($permissions['can_word_handover'] ?? false);
    $canWordMaintenanceHandoverDoc = (bool) ($permissions['can_word_maintenance_handover'] ?? false);
    $canShowWordToolbarMenu = $canEditQuoteWord || $canWordSalesContractDoc || $canWordHandoverDoc || $canWordMaintenanceHandoverDoc;
    $canRegenerateQuoteDocuments = (bool) ($permissions['can_regenerate_quote_documents'] ?? false);
    $canViewPayments = (bool) ($permissions['can_view_payments'] ?? false);
    $canPdfHandoverDoc = (bool) ($permissions['can_pdf_handover'] ?? false);
    $canPdfMaintenanceHandoverDoc = (bool) ($permissions['can_pdf_maintenance_handover'] ?? false);

    ob_start();
    foreach ($quotes as $index => $quote):
        $rowIndex = $indexOffset + $index;
        $quoteNumber = getQuoteNumberValue($quote);
        $quoteDate = formatDate($quote[$FIELDS['quotes']['date']] ?? null);
        $clientName = getClientName($quote[$FIELDS['quotes']['client']] ?? []);
        $totalPrice = formatPrice($quote[$FIELDS['quotes']['totalPrice']] ?? null);
        $brandName = getBrandName($quote[$FIELDS['quotes']['brand']] ?? []);
        $userName = getUserName($quote[$FIELDS['quotes']['createdBy']] ?? [], $users);
        $quoteStatus = getQuoteStatus($quote, $externalStatuses);
        $rowClasses = 'table-row';
        if ($rowIndex % 2 === 0) {
            $rowClasses .= ' table-row-white';
        } else {
            $rowClasses .= ' table-row-gray';
        }
        $rowStatusColor = $quoteStatus['status_color'] ?? '';
        $rowFadeValue = $rowStatusColor ? hexToRgba($rowStatusColor, 0.16) : null;
        if (!$rowFadeValue) {
            $rowFadeValue = 'rgba(185, 150, 53, 0.14)';
        }
        $rowStyleAttr = ' style="--row-status-fade: ' . htmlspecialchars($rowFadeValue, ENT_QUOTES) . ';"';
        $canShowPdfView = $canViewQuotePdfs;
        $canShowPdfContract = $canDownloadSensitiveDocs;
        $noteEntries = extractQuoteNotes($quote, $users);
        $visibleNotesLimit = 2;
        $hasMoreNotes = count($noteEntries) > $visibleNotesLimit;
        ?>
        <tr class="<?= $rowClasses ?>" data-quote-id="<?= $quote['id'] ?>" <?= $rowStyleAttr ?>>
            <td class="table-cell">
                <div class="quote-id">#<?= convertToEnglishNumbers($quoteNumber) ?></div>
            </td>
            <td class="table-cell">
                <div class="quote-date"><?= $quoteDate ?></div>
            </td>
            <td class="table-cell">
                <div class="quote-client"><?= htmlspecialchars($clientName) ?></div>
            </td>
            <?php if ($canViewQuoteTablePrice): ?>
                <td class="table-cell">
                    <div class="quote-price"><?= $totalPrice ?></div>
                </td>
            <?php endif; ?>
            <td class="table-cell">
                <div class="quote-brand"><?= htmlspecialchars($brandName) ?></div>
            </td>
            <td class="table-cell">
                <div class="quote-user"><?= htmlspecialchars($userName) ?></div>
            </td>
            <td class="table-cell">
                <?php
                $currentStatusId = $quoteStatus['status_id'] ?? null;
                $statusColor = $quoteStatus['status_color'] ?? '';
                $statusTextColor = $quoteStatus['status_text_color'] ?? '';
                $statusIconClass = $quoteStatus['status_icon'] ?? 'fa-circle';
                $statusOrder = $quoteStatus['status_order'] ?? '';
                $statusHoverColor = $quoteStatus['status_hover_color'] ?? '';
                $statusStyleAttr = '';
                if ($statusColor) {
                    $statusStyleAttr = 'style="background-color: ' . htmlspecialchars($statusColor, ENT_QUOTES) . '; border-color: ' . htmlspecialchars($statusColor, ENT_QUOTES) . '; color: ' . htmlspecialchars($statusTextColor ?: '#ffffff', ENT_QUOTES) . ';"';
                }
                $hasRejectionReason = mb_strpos($quoteStatus['status'], 'رفض') !== false && !empty($quoteStatus['rejection_reason']);
                $truncatedReason = $hasRejectionReason
                    ? mb_substr($quoteStatus['rejection_reason'], 0, 30) . (mb_strlen($quoteStatus['rejection_reason']) > 30 ? '...' : '')
                    : '';
                ?>
                <div class="status-control" data-quote-id="<?= $quote['id'] ?>"
                    data-current-status-id="<?= htmlspecialchars((string) ($currentStatusId ?? ''), ENT_QUOTES) ?>"
                    data-current-status-order="<?= htmlspecialchars((string) ($statusOrder ?? ''), ENT_QUOTES) ?>">
                    <button type="button"
                        class="status-badge status-trigger<?= $currentStatusId === null ? ' no-status' : '' ?>"
                        data-status-id="<?= htmlspecialchars((string) ($currentStatusId ?? ''), ENT_QUOTES) ?>"
                        data-status-color="<?= htmlspecialchars($statusColor ?? '', ENT_QUOTES) ?>"
                        data-status-text-color="<?= htmlspecialchars($statusTextColor ?? '', ENT_QUOTES) ?>"
                        data-status-icon="<?= htmlspecialchars($statusIconClass, ENT_QUOTES) ?>"
                        data-status-order="<?= htmlspecialchars((string) ($statusOrder ?? ''), ENT_QUOTES) ?>"
                        data-status-hover-color="<?= htmlspecialchars($statusHoverColor ?? '', ENT_QUOTES) ?>"
                        <?= $statusStyleAttr ?>>
                        <span class="flex items-center gap-2">
                            <i class="status-icon fas <?= htmlspecialchars($statusIconClass, ENT_QUOTES) ?>"></i>
                            <span class="status-label-text"><?= htmlspecialchars($quoteStatus['status']) ?></span>
                        </span>
                        <i class="fas fa-angle-down text-xs opacity-70"></i>
                    </button>
                    <div id="statusMenu<?= $quote['id'] ?>" class="hover-menu status-menu">
                        <?php
                        $noStatusStyleAttr = 'style="--status-option-bg: #f9fafb; --status-option-bg-hover: #e5e7eb; --status-option-text: #374151;"';
                        ?>
                        <div class="hover-option status-option<?= $currentStatusId === null ? ' selected' : '' ?>"
                            data-status-id="" data-status-label="لا يوجد حالة" data-status-color="" data-status-text-color=""
                            data-status-icon="fa-minus-circle" data-status-order="" data-status-hover-color=""
                            <?= $noStatusStyleAttr ?>>
                            <span class="status-option-swatch" style="background-color: #f3f4f6; border-color: #d1d5db;"></span>
                            <span class="status-option-label">لا يوجد حالة</span>
                        </div>
                        <?php foreach ($externalStatuses as $statusOptionId => $statusOptionMeta): ?>
                            <?php
                            $optionLabel = $statusOptionMeta['label'] ?? '';
                            if ($optionLabel === '') {
                                continue;
                            }
                            $isSelected = $currentStatusId === $statusOptionId;
                            $optionColor = $statusOptionMeta['color'] ?? '';
                            $optionTextColor = $statusOptionMeta['text_color'] ?? '';
                            $swatchColor = $optionColor ?: '#e5e7eb';
                            $swatchBorder = $optionColor ? $optionColor : '#d1d5db';
                            $optionBackground = $optionColor ? adjustColorBrightness($optionColor, 0.28) : '#f9fafb';
                            $optionHoverBackground = $optionColor ? adjustColorBrightness($optionColor, 0.18) : '#e5e7eb';
                            $optionStyleAttr = 'style="--status-option-bg: ' . htmlspecialchars($optionBackground, ENT_QUOTES) . '; --status-option-bg-hover: ' . htmlspecialchars($optionHoverBackground, ENT_QUOTES) . '; --status-option-text: ' . htmlspecialchars($optionTextColor ?: '#1f2937', ENT_QUOTES) . ';"';
                            ?>
                            <div class="hover-option status-option<?= $isSelected ? ' selected' : '' ?>"
                                data-status-id="<?= $statusOptionId ?>"
                                data-status-label="<?= htmlspecialchars($optionLabel, ENT_QUOTES) ?>"
                                data-status-color="<?= htmlspecialchars($statusOptionMeta['color'] ?? '', ENT_QUOTES) ?>"
                                data-status-text-color="<?= htmlspecialchars($statusOptionMeta['text_color'] ?? '', ENT_QUOTES) ?>"
                                data-status-icon="<?= htmlspecialchars($statusOptionMeta['icon'] ?? 'fa-circle', ENT_QUOTES) ?>"
                                data-status-order="<?= htmlspecialchars((string) ($statusOptionMeta['order'] ?? ''), ENT_QUOTES) ?>"
                                data-status-hover-color="<?= htmlspecialchars($statusOptionMeta['hover_color'] ?? '', ENT_QUOTES) ?>"
                                <?= $optionStyleAttr ?>>
                                <span class="status-option-swatch"
                                    style="background-color: <?= htmlspecialchars($swatchColor, ENT_QUOTES) ?>; border-color: <?= htmlspecialchars($swatchBorder, ENT_QUOTES) ?>;"></span>
                                <span class="status-option-label"><?= htmlspecialchars($optionLabel) ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div class="status-reason text-xs mt-1 <?= $hasRejectionReason ? 'text-red-600' : 'hidden' ?>"
                    <?= $hasRejectionReason ? 'title="' . htmlspecialchars($quoteStatus['rejection_reason'], ENT_QUOTES) . '"' : '' ?>>
                    <?= $hasRejectionReason ? htmlspecialchars($truncatedReason, ENT_QUOTES) : '' ?>
                </div>
            </td>
            <td class="table-cell">
                <div class="flex gap-1 md:gap-2 flex-wrap justify-center">
                    <button onclick="viewQuote(<?= $quote['id'] ?>)" class="btn-action btn-view" title="عرض التفاصيل">
                        <i class="fas fa-eye"></i>
                    </button>
                    <button onclick="editQuote(<?= $quote['id'] ?>)" class="btn-action btn-edit" title="تعديل">
                        <i class="fas fa-edit"></i>
                    </button>
                    <?php if ($canViewPayments): ?>
                        <button onclick="openPayments(<?= $quote['id'] ?>)" class="btn-action btn-payment" title="سداد الدفعات">
                            <i class="fas fa-hand-holding-dollar"></i>
                        </button>
                    <?php endif; ?>
                    <?php if ($canShowWordToolbarMenu): ?>
                        <div class="hover-container">
                            <button onclick="toggleHoverMenu('word', <?= $quote['id'] ?>, this)" class="btn-action btn-word"
                                title="Word">
                                <i class="fas fa-file-word"></i>
                            </button>
                            <div id="wordMenu<?= $quote['id'] ?>" class="hover-menu" data-menu-type="word"
                                data-quote-id="<?= $quote['id'] ?>">
                                <?php if ($canEditQuoteWord): ?>
                                    <div class="hover-option" onclick="viewWordQuote(<?= $quote['id'] ?>)">
                                        <i class="fas fa-eye ml-2"></i>عرض
                                    </div>
                                <?php endif; ?>
                                <?php if ($canWordSalesContractDoc): ?>
                                    <div class="hover-option" onclick="viewContractQuote(<?= $quote['id'] ?>)">
                                        <i class="fas fa-file-contract ml-2"></i>عقد
                                    </div>
                                <?php endif; ?>
                                <?php if ($canWordHandoverDoc): ?>
                                    <div class="hover-option" onclick="openDeliveryWord(<?= $quote['id'] ?>)">
                                        <i class="fas fa-file-circle-check ml-2"></i>محضر استلام
                                    </div>
                                <?php endif; ?>
                                <?php if ($canWordMaintenanceHandoverDoc): ?>
                                    <div class="hover-option" onclick="openGuaranteeWord(<?= $quote['id'] ?>)">
                                        <i class="fas fa-screwdriver-wrench ml-2"></i>محضر صيانة
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                    <?php if ($canShowPdfView || $canShowPdfContract || $canPdfHandoverDoc || $canPdfMaintenanceHandoverDoc): ?>
                        <div class="hover-container">
                            <button onclick="toggleHoverMenu('pdf', <?= $quote['id'] ?>, this)" class="btn-action btn-pdf"
                                title="PDF">
                                <i class="fas fa-file-pdf"></i>
                            </button>
                            <div id="pdfMenu<?= $quote['id'] ?>" class="hover-menu" data-menu-type="pdf"
                                data-quote-id="<?= $quote['id'] ?>">
                                <?php if ($canShowPdfView): ?>
                                    <div class="hover-option" onclick="downloadPDF(<?= $quote['id'] ?>)">
                                        <i class="fas fa-eye ml-2"></i>عرض
                                    </div>
                                <?php endif; ?>
                                <?php if ($canShowPdfContract): ?>
                                    <div class="hover-option" onclick="exportAsContract(<?= $quote['id'] ?>)">
                                        <i class="fas fa-file-contract ml-2"></i>عقد
                                    </div>
                                <?php endif; ?>
                                <?php if ($canPdfHandoverDoc): ?>
                                    <div class="hover-option" onclick="handleDeliveryPdfClick(<?= $quote['id'] ?>)">
                                        <i class="fas fa-file-circle-check ml-2"></i>محضر استلام
                                    </div>
                                <?php endif; ?>
                                <?php if ($canPdfMaintenanceHandoverDoc): ?>
                                    <div class="hover-option" onclick="handleGuaranteePdfClick(<?= $quote['id'] ?>)">
                                        <i class="fas fa-screwdriver-wrench ml-2"></i>محضر صيانة
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                    <?php if ($canRegenerateQuoteDocuments): ?>
                        <button onclick="refreshPDF(<?= $quote['id'] ?>)" class="btn-action btn-print"
                            title="إعادة إنشاء المستندات">
                            <i class="fa-solid fa-rotate-right"></i>
                        </button>
                    <?php endif; ?>
                </div>
            </td>
            <td class="table-cell notes-table-cell align-top">
                <div class="quote-notes" id="quoteNotes<?= $quote['id'] ?>" data-quote-id="<?= $quote['id'] ?>"
                    data-expanded="0" data-visible-limit="<?= $visibleNotesLimit ?>">
                    <div class="notes-toolbar">
                        <button type="button" class="notes-more-btn<?= $hasMoreNotes ? '' : ' hidden' ?>"
                            data-quote-id="<?= $quote['id'] ?>" onclick="toggleNotesVisibility(<?= $quote['id'] ?>)">
                            عرض المزيد
                        </button>
                        <button type="button" class="notes-add-btn" aria-label="إضافة ملاحظة"
                            onclick="openNoteComposer(<?= $quote['id'] ?>, this)">
                            <i class="fas fa-plus"></i>
                        </button>
                    </div>
                    <div class="note-stack" id="noteStack<?= $quote['id'] ?>">
                        <?php if (!empty($noteEntries)): ?>
                            <?php foreach ($noteEntries as $noteIndex => $noteEntry): ?>
                                <?php
                                $isHidden = $noteIndex >= $visibleNotesLimit;
                                $noteTextRaw = trim((string) ($noteEntry['text'] ?? ''));
                                $noteTextHtml = $noteTextRaw !== '' ? nl2br(htmlspecialchars($noteTextRaw, ENT_QUOTES, 'UTF-8')) : '&mdash;';
                                $noteAuthor = htmlspecialchars($noteEntry['author'] ?? 'غير معروف', ENT_QUOTES, 'UTF-8');
                                $noteDate = htmlspecialchars($noteEntry['created_at_formatted'] ?? '', ENT_QUOTES, 'UTF-8');
                                ?>
                                <div class="note-card<?= $isHidden ? ' note-card-hidden' : '' ?>"
                                    data-note-id="<?= $noteEntry['id'] ?>">
                                    <div class="note-text"><?= $noteTextHtml ?></div>
                                    <div class="note-meta">
                                        <span class="note-author"><?= $noteAuthor ?></span>
                                        <?php if ($noteDate !== ''): ?>
                                            <span class="note-separator">&bull;</span>
                                            <span class="note-date"><?= $noteDate ?></span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="notes-empty">لا توجد ملاحظات</div>
                        <?php endif; ?>
                    </div>
                </div>
            </td>
        </tr>
    <?php endforeach;
    return ob_get_clean();
}

function renderQuotesCards(array $quotes, int $indexOffset, array $users, array $externalStatuses, array $permissions): string
{
    global $FIELDS;
    $canViewQuoteTablePrice = (bool) ($permissions['can_view_quote_table_price'] ?? false);
    $canViewQuotePdfs = (bool) ($permissions['can_view_quote_pdfs'] ?? false);
    $canDownloadSensitiveDocs = (bool) ($permissions['can_download_sensitive_docs'] ?? false);
    $canEditQuoteWord = (bool) ($permissions['can_edit_quote_word'] ?? false);
    $canWordSalesContractDoc = (bool) ($permissions['can_word_sales_contract'] ?? false);
    $canWordHandoverDoc = (bool) ($permissions['can_word_handover'] ?? false);
    $canWordMaintenanceHandoverDoc = (bool) ($permissions['can_word_maintenance_handover'] ?? false);
    $canShowWordToolbarMenu = $canEditQuoteWord || $canWordSalesContractDoc || $canWordHandoverDoc || $canWordMaintenanceHandoverDoc;
    $canRegenerateQuoteDocuments = (bool) ($permissions['can_regenerate_quote_documents'] ?? false);
    $canViewPayments = (bool) ($permissions['can_view_payments'] ?? false);
    $canPdfHandoverDoc = (bool) ($permissions['can_pdf_handover'] ?? false);
    $canPdfMaintenanceHandoverDoc = (bool) ($permissions['can_pdf_maintenance_handover'] ?? false);

    ob_start();
    foreach ($quotes as $index => $quote):
        $rowIndex = $indexOffset + $index;
        $quoteNumber = getQuoteNumberValue($quote);
        $quoteDate = formatDate($quote[$FIELDS['quotes']['date']] ?? null);
        $clientName = getClientName($quote[$FIELDS['quotes']['client']] ?? []);
        $totalPrice = formatPrice($quote[$FIELDS['quotes']['totalPrice']] ?? null);
        $brandName = getBrandName($quote[$FIELDS['quotes']['brand']] ?? []);
        $userName = getUserName($quote[$FIELDS['quotes']['createdBy']] ?? [], $users);
        $quoteStatus = getQuoteStatus($quote, $externalStatuses);
        $rowStatusColor = $quoteStatus['status_color'] ?? '';
        $rowFadeValue = $rowStatusColor ? hexToRgba($rowStatusColor, 0.16) : 'rgba(185, 150, 53, 0.14)';
        $canShowPdfView = $canViewQuotePdfs;
        $canShowPdfContract = $canDownloadSensitiveDocs;
        $noteEntries = extractQuoteNotes($quote, $users);
        $visibleNotesLimit = 2;
        $hasMoreNotes = count($noteEntries) > $visibleNotesLimit;
        $currentStatusId = $quoteStatus['status_id'] ?? null;
        $statusColor = $quoteStatus['status_color'] ?? '';
        $statusTextColor = $quoteStatus['status_text_color'] ?? '';
        $statusIconClass = $quoteStatus['status_icon'] ?? 'fa-circle';
        $statusOrder = $quoteStatus['status_order'] ?? '';
        $statusHoverColor = $quoteStatus['status_hover_color'] ?? '';
        $statusStyleAttr = $statusColor
            ? 'style="background-color: ' . htmlspecialchars($statusColor, ENT_QUOTES) . '; border-color: ' . htmlspecialchars($statusColor, ENT_QUOTES) . '; color: ' . htmlspecialchars($statusTextColor ?: '#ffffff', ENT_QUOTES) . ';"'
            : '';
        $hasRejectionReason = mb_strpos($quoteStatus['status'], 'رفض') !== false && !empty($quoteStatus['rejection_reason']);
        $truncatedReason = $hasRejectionReason
            ? mb_substr($quoteStatus['rejection_reason'], 0, 30) . (mb_strlen($quoteStatus['rejection_reason']) > 30 ? '...' : '')
            : '';
        ?>
        <article class="quote-card" data-quote-id="<?= $quote['id'] ?>"
            style="--row-status-fade: <?= htmlspecialchars($rowFadeValue, ENT_QUOTES) ?>;">
            <div class="quote-card-top">
                <div class="flex flex-col gap-2">
                    <span class="quote-id-chip">#<?= convertToEnglishNumbers($quoteNumber) ?></span>
                    <span class="card-meta-badge">رقم النظام: <?= convertToEnglishNumbers($quote['id']) ?></span>
                </div>
                <div class="flex flex-col items-end gap-2">
                    <span class="pill pill-date">
                        <i class="fas fa-calendar text-gold"></i>
                        <?= $quoteDate ?>
                    </span>
                    <span class="pill pill-brand">
                        <i class="fas fa-tag text-medium-gray"></i>
                        <?= htmlspecialchars($brandName) ?>
                    </span>
                </div>
            </div>
            <div class="card-section">
                <div class="card-field">
                    <span class="card-field-label">العميل</span>
                    <span class="card-field-value"><?= htmlspecialchars($clientName) ?></span>
                </div>
                <?php if ($canViewQuoteTablePrice): ?>
                    <div class="card-info-grid">
                        <div class="card-field">
                            <span class="card-field-label">قيمة العرض</span>
                            <span class="card-field-value"><?= $totalPrice ?></span>
                        </div>
                        <div class="card-field">
                            <span class="card-field-label">بواسطة</span>
                            <span class="card-field-value"><?= htmlspecialchars($userName) ?></span>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="card-info-grid">
                        <div class="card-field">
                            <span class="card-field-label">بواسطة</span>
                            <span class="card-field-value"><?= htmlspecialchars($userName) ?></span>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
            <div class="card-section card-status">
                <div class="card-section-label">حالة العرض</div>
                <div class="status-control" data-quote-id="<?= $quote['id'] ?>"
                    data-current-status-id="<?= htmlspecialchars((string) ($currentStatusId ?? ''), ENT_QUOTES) ?>"
                    data-current-status-order="<?= htmlspecialchars((string) ($statusOrder ?? ''), ENT_QUOTES) ?>">
                    <button type="button"
                        class="status-badge status-trigger<?= $currentStatusId === null ? ' no-status' : '' ?>"
                        data-status-id="<?= htmlspecialchars((string) ($currentStatusId ?? ''), ENT_QUOTES) ?>"
                        data-status-color="<?= htmlspecialchars($statusColor ?? '', ENT_QUOTES) ?>"
                        data-status-text-color="<?= htmlspecialchars($statusTextColor ?? '', ENT_QUOTES) ?>"
                        data-status-icon="<?= htmlspecialchars($statusIconClass, ENT_QUOTES) ?>"
                        data-status-order="<?= htmlspecialchars((string) ($statusOrder ?? ''), ENT_QUOTES) ?>"
                        data-status-hover-color="<?= htmlspecialchars($statusHoverColor ?? '', ENT_QUOTES) ?>"
                        <?= $statusStyleAttr ?>>
                        <span class="flex items-center gap-2">
                            <i class="status-icon fas <?= htmlspecialchars($statusIconClass, ENT_QUOTES) ?>"></i>
                            <span class="status-label-text"><?= htmlspecialchars($quoteStatus['status']) ?></span>
                        </span>
                        <i class="fas fa-angle-down text-xs opacity-70"></i>
                    </button>
                    <div id="statusMenuCard<?= $quote['id'] ?>" class="hover-menu status-menu">
                        <?php
                        $noStatusStyleAttr = 'style="--status-option-bg: #f9fafb; --status-option-bg-hover: #e5e7eb; --status-option-text: #374151;"';
                        ?>
                        <div class="hover-option status-option<?= $currentStatusId === null ? ' selected' : '' ?>"
                            data-status-id="" data-status-label="لا يوجد حالة" data-status-color="" data-status-text-color=""
                            data-status-icon="fa-minus-circle" data-status-order="" data-status-hover-color=""
                            <?= $noStatusStyleAttr ?>>
                            <span class="status-option-swatch" style="background-color: #f3f4f6; border-color: #d1d5db;"></span>
                            <span class="status-option-label">لا يوجد حالة</span>
                        </div>
                        <?php foreach ($externalStatuses as $statusOptionId => $statusOptionMeta): ?>
                            <?php
                            $optionLabel = $statusOptionMeta['label'] ?? '';
                            if ($optionLabel === '') {
                                continue;
                            }
                            $isSelected = $currentStatusId === $statusOptionId;
                            $optionColor = $statusOptionMeta['color'] ?? '';
                            $optionTextColor = $statusOptionMeta['text_color'] ?? '';
                            $swatchColor = $optionColor ?: '#e5e7eb';
                            $swatchBorder = $optionColor ? $optionColor : '#d1d5db';
                            $optionBackground = $optionColor ? adjustColorBrightness($optionColor, 0.28) : '#f9fafb';
                            $optionHoverBackground = $optionColor ? adjustColorBrightness($optionColor, 0.18) : '#e5e7eb';
                            $optionStyleAttr = 'style="--status-option-bg: ' . htmlspecialchars($optionBackground, ENT_QUOTES) . '; --status-option-bg-hover: ' . htmlspecialchars($optionHoverBackground, ENT_QUOTES) . '; --status-option-text: ' . htmlspecialchars($optionTextColor ?: '#1f2937', ENT_QUOTES) . ';"';
                            ?>
                            <div class="hover-option status-option<?= $isSelected ? ' selected' : '' ?>"
                                data-status-id="<?= $statusOptionId ?>"
                                data-status-label="<?= htmlspecialchars($optionLabel, ENT_QUOTES) ?>"
                                data-status-color="<?= htmlspecialchars($statusOptionMeta['color'] ?? '', ENT_QUOTES) ?>"
                                data-status-text-color="<?= htmlspecialchars($statusOptionMeta['text_color'] ?? '', ENT_QUOTES) ?>"
                                data-status-icon="<?= htmlspecialchars($statusOptionMeta['icon'] ?? 'fa-circle', ENT_QUOTES) ?>"
                                data-status-order="<?= htmlspecialchars((string) ($statusOptionMeta['order'] ?? ''), ENT_QUOTES) ?>"
                                data-status-hover-color="<?= htmlspecialchars($statusOptionMeta['hover_color'] ?? '', ENT_QUOTES) ?>"
                                <?= $optionStyleAttr ?>>
                                <span class="status-option-swatch"
                                    style="background-color: <?= htmlspecialchars($swatchColor, ENT_QUOTES) ?>; border-color: <?= htmlspecialchars($swatchBorder, ENT_QUOTES) ?>;"></span>
                                <span class="status-option-label"><?= htmlspecialchars($optionLabel) ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div class="status-reason text-xs mt-1 <?= $hasRejectionReason ? 'text-red-600' : 'hidden' ?>"
                    <?= $hasRejectionReason ? 'title="' . htmlspecialchars($quoteStatus['rejection_reason'], ENT_QUOTES) . '"' : '' ?>>
                    <?= $hasRejectionReason ? htmlspecialchars($truncatedReason, ENT_QUOTES) : '' ?>
                </div>
            </div>
            <div class="card-section">
                <div class="card-section-label">الإجراءات</div>
                <div class="card-actions">
                    <button onclick="viewQuote(<?= $quote['id'] ?>)" class="btn-action btn-view" title="عرض التفاصيل">
                        <i class="fas fa-eye"></i>
                    </button>
                    <button onclick="editQuote(<?= $quote['id'] ?>)" class="btn-action btn-edit" title="تعديل">
                        <i class="fas fa-edit"></i>
                    </button>
                    <?php if ($canViewPayments): ?>
                        <button onclick="openPayments(<?= $quote['id'] ?>)" class="btn-action btn-payment" title="سداد الدفعات">
                            <i class="fas fa-hand-holding-dollar"></i>
                        </button>
                    <?php endif; ?>
                    <?php if ($canShowWordToolbarMenu): ?>
                        <div class="hover-container">
                            <button onclick="toggleHoverMenu('word', <?= $quote['id'] ?>, this)" class="btn-action btn-word"
                                title="Word">
                                <i class="fas fa-file-word"></i>
                            </button>
                            <div id="wordMenuCard<?= $quote['id'] ?>" class="hover-menu" data-menu-type="word"
                                data-quote-id="<?= $quote['id'] ?>">
                                <?php if ($canEditQuoteWord): ?>
                                    <div class="hover-option" onclick="viewWordQuote(<?= $quote['id'] ?>)">
                                        <i class="fas fa-eye ml-2"></i>عرض
                                    </div>
                                <?php endif; ?>
                                <?php if ($canWordSalesContractDoc): ?>
                                    <div class="hover-option" onclick="viewContractQuote(<?= $quote['id'] ?>)">
                                        <i class="fas fa-file-contract ml-2"></i>عقد
                                    </div>
                                <?php endif; ?>
                                <?php if ($canWordHandoverDoc): ?>
                                    <div class="hover-option" onclick="openDeliveryWord(<?= $quote['id'] ?>)">
                                        <i class="fas fa-file-circle-check ml-2"></i>محضر استلام
                                    </div>
                                <?php endif; ?>
                                <?php if ($canWordMaintenanceHandoverDoc): ?>
                                    <div class="hover-option" onclick="openGuaranteeWord(<?= $quote['id'] ?>)">
                                        <i class="fas fa-screwdriver-wrench ml-2"></i>محضر صيانة
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                    <?php if ($canShowPdfView || $canShowPdfContract || $canPdfHandoverDoc || $canPdfMaintenanceHandoverDoc): ?>
                        <div class="hover-container">
                            <button onclick="toggleHoverMenu('pdf', <?= $quote['id'] ?>, this)" class="btn-action btn-pdf"
                                title="PDF">
                                <i class="fas fa-file-pdf"></i>
                            </button>
                            <div id="pdfMenuCard<?= $quote['id'] ?>" class="hover-menu" data-menu-type="pdf"
                                data-quote-id="<?= $quote['id'] ?>">
                                <?php if ($canShowPdfView): ?>
                                    <div class="hover-option" onclick="downloadPDF(<?= $quote['id'] ?>)">
                                        <i class="fas fa-eye ml-2"></i>عرض
                                    </div>
                                <?php endif; ?>
                                <?php if ($canShowPdfContract): ?>
                                    <div class="hover-option" onclick="exportAsContract(<?= $quote['id'] ?>)">
                                        <i class="fas fa-file-contract ml-2"></i>عقد
                                    </div>
                                <?php endif; ?>
                                <?php if ($canPdfHandoverDoc): ?>
                                    <div class="hover-option" onclick="handleDeliveryPdfClick(<?= $quote['id'] ?>)">
                                        <i class="fas fa-file-circle-check ml-2"></i>محضر استلام
                                    </div>
                                <?php endif; ?>
                                <?php if ($canPdfMaintenanceHandoverDoc): ?>
                                    <div class="hover-option" onclick="handleGuaranteePdfClick(<?= $quote['id'] ?>)">
                                        <i class="fas fa-screwdriver-wrench ml-2"></i>محضر صيانة
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                    <?php if ($canRegenerateQuoteDocuments): ?>
                        <button onclick="refreshPDF(<?= $quote['id'] ?>)" class="btn-action btn-print"
                            title="إعادة إنشاء المستندات">
                            <i class="fa-solid fa-rotate-right"></i>
                        </button>
                    <?php endif; ?>
                </div>
            </div>
            <div class="card-section card-notes">
                <div class="card-section-label">الملاحظات</div>
                <div class="quote-notes" id="quoteNotesCard<?= $quote['id'] ?>" data-quote-id="<?= $quote['id'] ?>"
                    data-expanded="0" data-visible-limit="<?= $visibleNotesLimit ?>">
                    <div class="notes-toolbar">
                        <button type="button" class="notes-more-btn<?= $hasMoreNotes ? '' : ' hidden' ?>"
                            data-quote-id="<?= $quote['id'] ?>" onclick="toggleNotesVisibility(<?= $quote['id'] ?>)">
                            عرض المزيد
                        </button>
                        <button type="button" class="notes-add-btn" aria-label="إضافة ملاحظة"
                            onclick="openNoteComposer(<?= $quote['id'] ?>, this)">
                            <i class="fas fa-plus"></i>
                        </button>
                    </div>
                    <div class="note-stack" id="noteStackCard<?= $quote['id'] ?>">
                        <?php if (!empty($noteEntries)): ?>
                            <?php foreach ($noteEntries as $noteIndex => $noteEntry): ?>
                                <?php
                                $isHidden = $noteIndex >= $visibleNotesLimit;
                                $noteTextRaw = trim((string) ($noteEntry['text'] ?? ''));
                                $noteTextHtml = $noteTextRaw !== '' ? nl2br(htmlspecialchars($noteTextRaw, ENT_QUOTES, 'UTF-8')) : '&mdash;';
                                $noteAuthor = htmlspecialchars($noteEntry['author'] ?? 'غير معروف', ENT_QUOTES, 'UTF-8');
                                $noteDate = htmlspecialchars($noteEntry['created_at_formatted'] ?? '', ENT_QUOTES, 'UTF-8');
                                ?>
                                <div class="note-card<?= $isHidden ? ' note-card-hidden' : '' ?>"
                                    data-note-id="<?= $noteEntry['id'] ?>">
                                    <div class="note-text"><?= $noteTextHtml ?></div>
                                    <div class="note-meta">
                                        <span class="note-author"><?= $noteAuthor ?></span>
                                        <?php if ($noteDate !== ''): ?>
                                            <span class="note-separator">&bull;</span>
                                            <span class="note-date"><?= $noteDate ?></span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="notes-empty">لا توجد ملاحظات</div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </article>
    <?php endforeach;
    return ob_get_clean();
}

// تجهيز الفلاتر والترتيب
$activeFilters = normalizeQuotesFilters([]);
$sortBy = $_POST['sort_by'] ?? $_GET['sort_by'] ?? '';
$sortDir = $_POST['sort_dir'] ?? $_GET['sort_dir'] ?? 'desc';
if (!$canViewQuoteTablePrice && $sortBy === 'price') {
    $sortBy = '';
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'filter') {
    $activeFilters = normalizeQuotesFilters(json_decode($_POST['filters'], true));
}

// تحميل البيانات الأساسية
$users = loadUsersForMqPage($MQ_FILTER_CACHE_DIR, $MQ_REFERENCE_CACHE_TTL);
if (empty($externalStatuses)) {
    $externalStatuses = loadExternalStatusesForMqPage($MQ_FILTER_CACHE_DIR, $MQ_REFERENCE_CACHE_TTL);
}
$statusFilterLabels = [];
// عند تفعيل quotes_contracts (حالات/تصنيفات معروضة) يُقتصر ظهور العروض والفلاتر على تلك الحالات حتى مع view_all.
$restrictViewStatuses = !empty($QUOTES_CONTRACTS_STATUS_ACCESS['restrict_view']);
foreach ($externalStatuses as $statusId => $statusMeta) {
    if ($restrictViewStatuses) {
        $statusId = (int) $statusId;
        $categoryId = $statusMeta['category_id'] ?? null;
        $allowed = false;
        if (in_array($statusId, $QUOTES_CONTRACTS_STATUS_ACCESS['view_statuses'], true)) {
            $allowed = true;
        }
        if (!$allowed && $categoryId !== null && in_array((int) $categoryId, $QUOTES_CONTRACTS_STATUS_ACCESS['view_categories'], true)) {
            $allowed = true;
        }
        if (!$allowed) {
            continue;
        }
    }
    $label = $statusMeta['label'] ?? '';
    if ($label !== '' && !in_array($label, $statusFilterLabels, true)) {
        $statusFilterLabels[] = $label;
    }
}
$allowOwnDraftStatusLabel = $restrictViewStatuses && !$canViewAllQuotes;
if (
    (!$restrictViewStatuses || in_array(0, $QUOTES_CONTRACTS_STATUS_ACCESS['view_statuses'], true) || $allowOwnDraftStatusLabel)
    && !in_array('لا يوجد حالة', $statusFilterLabels, true)
) {
    array_unshift($statusFilterLabels, 'لا يوجد حالة');
}

// تحميل خيارات الفلاتر أو المزيد من العروض عبر AJAX
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    if ($action === 'filter_options') {
        header('Content-Type: application/json');
        $showCancelledFlag = false;
        if (isset($_POST['show_cancelled'])) {
            $cancelFlag = strtolower(trim((string) $_POST['show_cancelled']));
            $showCancelledFlag = in_array($cancelFlag, ['1', 'true', 'yes', 'on'], true);
        }

        $cacheSuffix = $canViewAllQuotes ? 'all' : ('user_' . (int) $current_user_id);
        if (!empty($QUOTES_CONTRACTS_STATUS_ACCESS['restrict_view'])) {
            $cacheSuffix = 'user_' . (int) $current_user_id;
        }
        $cacheKey = $MQ_FILTER_CACHE_DIR . '/mq-filter-options-' . $cacheSuffix . '-' . ($showCancelledFlag ? '1' : '0') . '.json';
        $cachedOptions = loadFilterOptionsCache($cacheKey, $MQ_FILTER_CACHE_TTL);
        if (!is_array($cachedOptions)) {
            $builtOptions = buildFilterOptions([
                'user_id' => $canViewAllQuotes ? null : $current_user_id,
                'can_view_all' => $canViewAllQuotes,
                'external_statuses' => $externalStatuses,
                'users' => $users,
                'show_cancelled' => $showCancelledFlag,
            ]);
            $cachedOptions = array_merge($builtOptions, [
                'status' => $statusFilterLabels,
                'generated_at' => date('c'),
            ]);
            saveFilterOptionsCache($cacheKey, $cachedOptions);
        } else {
            $cachedOptions['status'] = $statusFilterLabels;
        }

        echo json_encode([
            'success' => true,
            'options' => $cachedOptions,
        ]);
        exit;
    }

    if ($action === 'load_more') {
        header('Content-Type: application/json');
        $cursor = null;
        if (isset($_POST['cursor'])) {
            $cursorRaw = $_POST['cursor'];
            if (is_string($cursorRaw) && $cursorRaw !== '') {
                $decodedCursor = json_decode($cursorRaw, true);
                if (is_array($decodedCursor)) {
                    $cursor = $decodedCursor;
                }
            }
        }
        $filtersPayload = isset($_POST['filters']) ? json_decode($_POST['filters'], true) : [];
        $activeFilters = normalizeQuotesFilters($filtersPayload);
        $sortBy = $_POST['sort_by'] ?? '';
        $sortDir = $_POST['sort_dir'] ?? 'desc';
        if (!$canViewQuoteTablePrice && $sortBy === 'price') {
            $sortBy = '';
        }
        $indexOffset = isset($_POST['index_offset']) ? max(0, (int) $_POST['index_offset']) : 0;
        $showCancelledFlag = false;
        if (isset($_POST['show_cancelled'])) {
            $cancelFlag = strtolower(trim((string) $_POST['show_cancelled']));
            $showCancelledFlag = in_array($cancelFlag, ['1', 'true', 'yes', 'on'], true);
        }

        $batchResult = buildQuotesBatch([
            'user_id' => $canViewAllQuotes ? null : $current_user_id,
            'can_view_all' => $canViewAllQuotes,
            'cursor' => $cursor ?: ['page' => 1, 'offset' => 0],
            'limit' => $MQ_PAGE_SIZE,
            'scan_size' => $MQ_SCAN_PAGE_SIZE,
            'active_filters' => $activeFilters,
            'sort_by' => $sortBy,
            'sort_dir' => $sortDir,
            'external_statuses' => $externalStatuses,
            'users' => $users,
            'show_cancelled' => $showCancelledFlag,
        ]);

        $nextQuotes = $batchResult['quotes'] ?? [];
        $hasMore = !empty($batchResult['has_more']);
        $nextCursor = $batchResult['next_cursor'] ?? null;

        $quoteIdsForNotes = array_values(array_filter(array_map(function ($quote) {
            return $quote['id'] ?? null;
        }, $nextQuotes)));
        $QUOTE_NOTES_CACHE = loadNotesForQuotes($quoteIdsForNotes, $users);
        $STATUS_ACTIONS_CACHE = loadStatusActionsForQuotes($quoteIdsForNotes, $users);

        $permissionsContext = [
            'can_view_quote_table_price' => $canViewQuoteTablePrice,
            'can_view_quote_pdfs' => $canViewQuotePdfs,
            'can_download_sensitive_docs' => $canDownloadSensitiveDocs,
            'can_pdf_handover' => $canPdfHandoverDoc,
            'can_pdf_maintenance_handover' => $canPdfMaintenanceHandoverDoc,
            'can_edit_quote_word' => $canEditQuoteWord,
            'can_word_sales_contract' => $canWordSalesContractDoc,
            'can_word_handover' => $canWordHandoverDoc,
            'can_word_maintenance_handover' => $canWordMaintenanceHandoverDoc,
            'can_regenerate_quote_documents' => $canRegenerateQuoteDocuments,
            'can_view_payments' => $canViewPayments,
        ];

        $tableHtml = $nextQuotes ? renderQuotesTableRows($nextQuotes, $indexOffset, $users, $externalStatuses, $permissionsContext) : '';
        $cardsHtml = $nextQuotes ? renderQuotesCards($nextQuotes, $indexOffset, $users, $externalStatuses, $permissionsContext) : '';

        echo json_encode([
            'success' => true,
            'has_more' => $hasMore,
            'next_cursor' => $nextCursor,
            'count' => count($nextQuotes),
            'html_table' => $tableHtml,
            'html_cards' => $cardsHtml,
            'quotes' => $nextQuotes,
            'status_actions' => $STATUS_ACTIONS_CACHE,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}

// تم تعطيل التحميل المتزامن لتحسين الأداء (AJAX سيتولى تحميل أول دفعة)
$batchResult = [
    'quotes' => [],
    'has_more' => true,
    'next_cursor' => ['page' => 1, 'offset' => 0]
];

$filteredQuotes = $batchResult['quotes'] ?? [];
$hasMoreQuotes = !empty($batchResult['has_more']);
$nextQuotesCursor = $batchResult['next_cursor'] ?? null;

global $QUOTE_NOTES_CACHE;
$quoteIdsForNotes = array_values(array_filter(array_map(function ($quote) {
    return $quote['id'] ?? null;
}, $filteredQuotes)));
$QUOTE_NOTES_CACHE = loadNotesForQuotes($quoteIdsForNotes, $users);
$STATUS_ACTIONS_CACHE = loadStatusActionsForQuotes($quoteIdsForNotes, $users);

$permissionsContext = [
    'can_view_quote_table_price' => $canViewQuoteTablePrice,
    'can_view_quote_pdfs' => $canViewQuotePdfs,
    'can_download_sensitive_docs' => $canDownloadSensitiveDocs,
    'can_pdf_handover' => $canPdfHandoverDoc,
    'can_pdf_maintenance_handover' => $canPdfMaintenanceHandoverDoc,
    'can_edit_quote_word' => $canEditQuoteWord,
    'can_word_sales_contract' => $canWordSalesContractDoc,
    'can_word_handover' => $canWordHandoverDoc,
    'can_word_maintenance_handover' => $canWordMaintenanceHandoverDoc,
    'can_regenerate_quote_documents' => $canRegenerateQuoteDocuments,
    'can_view_payments' => $canViewPayments,
];
$currentUserName = '';
foreach ($users as $userRow) {
    if (($userRow['id'] ?? null) === $current_user_id) {
        $currentUserName = trim((string) ($userRow[$FIELDS['users']['name']] ?? ''));
        break;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'filter') {
    logDiagnosticEvent('filter_quotes', [
        'ajax' => isset($_POST['ajax']),
        'filters' => $activeFilters,
        'show_cancelled' => $showCancelled,
        'result_count' => count($filteredQuotes),
        'has_more' => $hasMoreQuotes
    ]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'filter' && isset($_POST['ajax'])) {
    respondAjaxWithLog('filter_quotes', ['success' => true, 'count' => count($filteredQuotes)], [
        'show_cancelled' => $showCancelled,
        'filters' => $activeFilters,
        'has_more' => $hasMoreQuotes
    ]);
}

if (!isset($_POST['ajax'])) {
    logDiagnosticEvent('page_render', [
        'loaded_quotes' => count($filteredQuotes),
        'has_more' => $hasMoreQuotes,
        'show_cancelled' => $showCancelled,
        'sort' => [
            'by' => $sortBy,
            'dir' => $sortDir
        ],
        'filters' => $activeFilters
    ]);
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>إدارة عروض الأسعار - ألفا الذهبية</title>

    <!-- Tailwind CSS v4.0 CDN -->
    <script src="https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4"></script>

    <!-- Font Awesome -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" rel="stylesheet">

    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;500;600;700&display=swap" rel="stylesheet">

    <!-- Flatpickr CSS -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/flatpickr/4.6.13/flatpickr.min.css" rel="stylesheet">

    <style type="text/tailwindcss">
        @theme {
            --color-gold: #977e2b;
            --color-gold-hover: #b89635;
            --color-gold-light: rgba(151, 126, 43, 0.1);
            --color-dark-gray: #2c2c2c;
            --color-medium-gray: #666;
            --color-light-gray: #f8f9fa;
            --color-border: #e5e7eb;
            --font-family-cairo: 'Cairo', sans-serif;
        }
       
        @layer base {
            body {
                font-family: var(--font-family-cairo);
                background-color: var(--color-light-gray);
                color: var(--color-dark-gray);
            }
           
            body, html {
                overflow-x: hidden !important;
            }
        }
       
        @layer components {
            .btn-gold {
                @apply bg-gold text-white px-6 py-3 rounded-md font-semibold transition-all duration-300 hover:bg-gold-hover hover:-translate-y-0.5 hover:shadow-lg flex items-center gap-2;
            }
           
            .btn-gray {
                @apply bg-gray-600 text-white px-5 py-2.5 rounded-md font-medium transition-all duration-300 hover:bg-gray-700 hover:-translate-y-0.5 flex items-center gap-2;
            }
           
            .card {
                @apply bg-white rounded-xl shadow-sm border border-border p-6;
            }
           
            .table-container {
                @apply overflow-x-auto;
            }
           
            .modern-table {
                @apply w-full border-collapse text-sm;
            }
           
            .modern-table thead tr:first-child {
                background-color: #b89635;
            }
            .modern-table thead tr:first-child .table-header {
                color: #ffffff;
                background-color: transparent;
                background-image: none;
                border-color: rgba(255, 255, 255, 0.25);
            }
            .modern-table thead tr:first-child .table-header:hover {
                background-color: rgba(255, 255, 255, 0.08);
                color: #ffffff;
            }
            .modern-table thead tr:first-child .table-header .fas {
                color: rgba(255, 255, 255, 0.9);
            }
           
            .table-header {
                @apply bg-gradient-to-r from-gray-50 to-gray-100 px-4 py-4 text-center font-bold text-dark-gray border-b-2 border-border text-sm cursor-pointer select-none transition-all duration-300 hover:bg-gold-light hover:text-gold whitespace-nowrap;
            }
           
            .table-cell {
                @apply px-4 py-4 border-b border-gray-100 align-middle transition-all duration-300 text-center;
            }
           
            .table-row {
                @apply transition-all duration-300;
                position: relative;
                background-image:
                    linear-gradient(to right, var(--row-status-fade, rgba(185, 150, 53, 0.16)) 0%, rgba(185, 150, 53, 0) 55%),
                    linear-gradient(to left, var(--row-status-fade, rgba(185, 150, 53, 0.16)) 0%, rgba(185, 150, 53, 0) 55%);
                background-repeat: no-repeat;
                background-size: 56px 100%, 56px 100%;
                background-position: left top, right top;
            }
            .table-row::after {
                content: '';
                position: absolute;
                inset: 0;
                border: 2px solid transparent;
                pointer-events: none;
                transition: border-color 0.2s ease, box-shadow 0.2s ease;
            }
            .table-row:hover::after {
                border-color: rgba(185, 150, 53, 0.25);
            }
            .table-row.row-selected::after,
            .table-row.row-selected:hover::after {
                border-color: #b89635;
                box-shadow: 0 0 0 1px rgba(185, 150, 53, 0.15);
            }
            .table-row-white {
                background-color: #ffffff;
            }
            .table-row-gray {
                background-color: #f7f7f7;
            }
            .table-row.row-processing {
                background-color: #d9d9d9 !important;
            }
           
            .quote-id {
                @apply font-bold text-gold text-base text-center;
            }
           
            .quote-date {
                @apply text-medium-gray text-sm font-medium text-center;
            }
           
            .quote-client {
                @apply font-semibold text-dark-gray text-center;
            }
           
            .quote-price {
                @apply font-bold text-dark-gray text-base flex items-center gap-2 justify-center;
                direction: rtl;
            }
           
            .quote-brand {
                @apply inline-flex items-center px-3 py-1.5 bg-gold-light text-gold rounded-md text-xs font-semibold border border-gold justify-center;
            }
           
            .quote-user {
                @apply text-medium-gray text-sm font-medium text-center;
            }
           
            .status-badge {
                @apply inline-flex items-center px-3 py-1.5 rounded-md text-xs font-semibold border transition-all duration-200;
                background-color: var(--color-light-gray);
                border-color: var(--color-border);
                color: var(--color-dark-gray);
            }
           
            .status-control {
                @apply relative inline-block;
            }
           
            .status-trigger {
                @apply justify-between gap-3 cursor-pointer focus:outline-none focus:ring-2 focus:ring-gold-light;
            }
           
            .status-badge.no-status {
                background-color: #f3f4f6;
                border-color: #e5e7eb;
                color: #6b7280;
            }
           
            .status-option {
                @apply flex items-center gap-3 border border-transparent rounded-md transition-colors duration-200;
                background-color: var(--status-option-bg, #ffffff);
                color: var(--status-option-text, var(--color-dark-gray));
            }
           
            .status-option:hover {
                background-color: var(--status-option-bg-hover, rgba(17, 24, 39, 0.08));
                color: var(--status-option-text, var(--color-dark-gray));
            }
           
            .status-option.selected {
                @apply border-2 border-gold;
            }
           
            .status-option.selected:hover {
                background-color: var(--status-option-bg-hover, rgba(151, 126, 43, 0.12));
            }
           
            .status-pending {
                @apply bg-blue-50 text-blue-800 border-blue-200;
            }
           
            .status-approved {
                @apply bg-green-50 text-green-800 border-green-200;
            }
           
            .status-rejected {
                @apply bg-red-50 text-red-800 border-red-200;
            }
           
            .btn-action {
                @apply p-2 border-0 rounded-md cursor-pointer text-sm transition-all duration-300 flex items-center justify-center w-9 h-9;
            }
           
            .btn-view {
                @apply bg-gray-600 text-white hover:bg-gray-700 hover:-translate-y-0.5 hover:shadow-md;
            }
           
            .btn-edit {
                @apply bg-gold text-white hover:bg-gold-hover hover:-translate-y-0.5 hover:shadow-md;
            }
           
            .btn-word {
                @apply bg-blue-600 text-white hover:bg-blue-700 hover:-translate-y-0.5 hover:shadow-md;
            }
           
            .btn-pdf {
                @apply bg-red-600 text-white hover:bg-red-700 hover:-translate-y-0.5 hover:shadow-md;
            }

            .btn-payment {
                @apply bg-emerald-600 text-white hover:bg-emerald-700 hover:-translate-y-0.5 hover:shadow-md;
                display: none !important;
            }
           
            .btn-status {
                @apply bg-green-600 text-white hover:bg-green-700 hover:-translate-y-0.5 hover:shadow-md;
            }
           
            .btn-print {
                @apply bg-white border border-red-800 text-red-800 font-bold hover:bg-red-50 hover:text-white hover:bg-red-800 hover:border-red-900 hover:shadow-md;
            }
           
            .btn-contract {
                @apply bg-red-600 text-white hover:bg-red-700 hover:-translate-y-0.5 hover:shadow-md;
            }
           
            .hover-container {
                @apply relative inline-block;
            }
           
            .hover-menu {
                @apply absolute right-0 top-full mt-1 bg-white border border-border rounded-md shadow-lg z-50 min-w-32 hidden;
            }
           
            .hover-menu.show {
                @apply block;
            }
           
            .status-menu {
                position: fixed;
                inset: auto;
                max-height: 60vh;
                min-width: 220px;
                overflow-y: auto;
                padding-block: 4px;
                z-index: 70;
            }
           
            .hover-option {
                @apply block w-full text-right px-4 py-2 text-sm text-dark-gray hover:bg-gold-light hover:text-gold transition-all duration-200 cursor-pointer border-b border-gray-100 last:border-b-0;
            }
           
            .status-option-swatch {
                width: 14px;
                height: 14px;
                border-radius: 9999px;
                border: 1px solid #d1d5db;
                flex-shrink: 0;
            }
           
            .status-option.selected .status-option-swatch {
                border-color: var(--color-gold);
            }
           
            .modal-overlay {
                @apply fixed inset-0 bg-black/50 z-50 backdrop-blur-sm flex items-center justify-center;
            }
           
            .modal-content {
                @apply bg-white rounded-xl shadow-xl w-11/12 max-w-md overflow-hidden;
            }
            .status-stack {
                display: grid;
                gap: 14px;
                position: relative;
                padding-right: 26px;
            }
            .status-stack-modal {
                display: grid;
                gap: 12px;
            }
            .status-stack::before {
                content: '';
                position: absolute;
                top: 6px;
                bottom: 6px;
                right: 9px;
                width: 3px;
                background: #e2e8f0;
                border-radius: 2px;
            }
            .status-row {
                display: flex;
                align-items: center;
                justify-content: space-between;
                gap: 10px;
                position: relative;
                padding-right: 12px;
            }
            .status-dot {
                position: absolute;
                right: -22px;
                top: 50%;
                transform: translateY(-50%);
                width: 12px;
                height: 12px;
                border-radius: 50%;
                border: 2px solid #fff;
                box-shadow: 0 3px 8px rgba(0, 0, 0, 0.12);
                flex: 0 0 auto;
            }
            .status-row.current .status-dot { background: #16a34a; }
            .status-row.next .status-dot { background: #f59e0b; }
            .status-row.after .status-dot { background: #cbd5e1; }
            .status-name {
                font-weight: 800;
                font-size: 14px;
                display: flex;
                flex-direction: column;
                gap: 4px;
            }
            .status-meta {
                font-size: 12px;
                color: var(--color-medium-gray);
                font-weight: 600;
                display: flex;
                flex-wrap: wrap;
                gap: 6px;
            }
            .timeline {
                position: relative;
                display: grid;
                gap: 14px;
                padding-right: 26px;
                max-height: 45vh;
                overflow: auto;
            }
            .timeline::before {
                content: '';
                position: absolute;
                top: 6px;
                bottom: 6px;
                right: 9px;
                width: 3px;
                background: #e2e8f0;
                border-radius: 2px;
            }
            .timeline-item {
                display: flex;
                gap: 10px;
                position: relative;
                padding-right: 12px;
            }
            .timeline-item .dot {
                position: absolute;
                right: -22px;
                top: 8px;
                width: 12px;
                height: 12px;
                border-radius: 50%;
                border: 2px solid #fff;
                box-shadow: 0 3px 8px rgba(0, 0, 0, 0.12);
                background: #cbd5e1;
            }
            .timeline-item.current .dot { background: #16a34a; }
            .timeline-item.next .dot { background: #f59e0b; }
            .timeline-item.completed .dot { background: #16a34a; opacity: 0.7; }
            .t-title {
                font-weight: 800;
                font-size: 13px;
            }
            .t-meta {
                font-size: 12px;
                color: var(--color-medium-gray);
            }
            .t-info {
                display: flex;
                flex-wrap: wrap;
                gap: 8px;
                margin-top: 4px;
                font-size: 11px;
                color: var(--color-medium-gray);
                font-weight: 600;
            }
            .status-modal-actions {
                display: flex;
                align-items: center;
                justify-content: space-between;
                gap: 12px;
                padding: 10px 12px;
                background: #f8fafc;
                border: 1px solid #e2e8f0;
                border-radius: 10px;
            }
            .status-next-label {
                font-size: 12px;
                color: var(--color-medium-gray);
                font-weight: 600;
            }
            .status-next-label span {
                color: #111827;
                font-weight: 700;
            }
            .status-action-btn {
                background: var(--color-gold);
                color: #fff;
                padding: 10px 16px;
                border-radius: 10px;
                font-weight: 700;
                display: inline-flex;
                align-items: center;
                gap: 8px;
                transition: background 0.2s ease;
            }
            .status-action-btn:disabled {
                opacity: 0.6;
                cursor: not-allowed;
            }
            .status-action-btn:not(:disabled):hover {
                background: var(--color-gold-hover);
            }
            .status-cancel-btn {
                background: #fef2f2;
                border: 1px dashed #fca5a5;
                color: #dc2626;
                padding: 10px 16px;
                border-radius: 10px;
                font-weight: 700;
                display: flex;
                align-items: center;
                justify-content: center;
                gap: 8px;
                width: 100%;
                cursor: pointer;
                transition: all 0.2s ease;
                margin-top: 12px;
                font-size: 13px;
            }
            .status-cancel-btn:hover {
                background: #fee2e2;
                border-color: #f87171;
                box-shadow: 0 2px 4px rgba(220, 38, 38, 0.05);
            }
            .status-action {
                border: 1px solid #15803d;
                background: #16a34a;
                color: #ffffff;
                padding: 6px 10px;
                border-radius: 999px;
                font-weight: 800;
                cursor: pointer;
                font-size: 12px;
                display: inline-flex;
                align-items: center;
                gap: 6px;
                box-shadow: 0 6px 12px rgba(22, 163, 74, 0.18);
                transition: transform 0.12s ease, box-shadow 0.12s ease, background 0.12s ease;
            }
            .status-action:hover {
                background: #15803d;
                transform: translateY(-1px);
            }
            .status-action:disabled {
                opacity: 0.6;
                cursor: not-allowed;
                background: #e2e8f0;
                color: #94a3b8;
                border-color: #e2e8f0;
                box-shadow: none;
                transform: none;
            }
            .status-action .icon-check {
                font-size: 12px;
            }
           
            .filter-option {
                @apply p-3 cursor-pointer transition-all duration-300 flex items-center gap-2.5 text-sm rounded-md hover:bg-light-gray;
            }
           
            .filter-option.selected {
                @apply bg-gold-light text-gold font-semibold;
            }
           
            .sort-chip {
                @apply flex items-center justify-center gap-2 p-2.5 border border-border rounded-md text-sm transition-all duration-300;
            }
           
            .sort-chip.active {
                @apply bg-gold-light border-gold text-gold font-semibold;
            }
           
            .date-option {
                @apply p-2.5 bg-light-gray border border-border rounded-md cursor-pointer transition-all duration-300 text-center text-sm hover:bg-gold-light hover:border-gold hover:text-gold;
            }
           
            .spinner {
                @apply w-8 h-8 border-4 border-border border-t-gold rounded-full animate-spin;
            }
           
            .message {
                @apply p-4 rounded-md mb-6 text-sm flex items-center gap-3 border font-medium;
            }
           
            .message.success {
                @apply bg-green-50 text-green-800 border-green-200;
            }
           
            .message.error {
                @apply bg-red-50 text-red-800 border-red-200;
            }
           
            .message-overlay {
                position: fixed;
                inset: 0;
                background-color: rgba(15, 23, 42, 0.45);
                backdrop-filter: blur(2px);
                display: flex;
                align-items: center;
                justify-content: center;
                z-index: 120;
                padding: 1.5rem;
                transition: opacity 0.3s ease;
            }
            .message-overlay.hidden {
                display: none !important;
            }
            .message-modal {
                background-color: #ffffff;
                border-radius: 1rem;
                padding: 2rem 2.5rem;
                max-width: 380px;
                width: 100%;
                box-shadow: 0 20px 45px rgba(15, 23, 42, 0.18);
                text-align: center;
                direction: rtl;
                display: flex;
                gap: 1.25rem;
            }
            .message-icon-wrapper {
                display: flex;
                align-items: center;
                justify-content: center;
            }
            .message-icon {
                font-size: 2.5rem;
                color: var(--color-gold);
            }
            .message-icon.hidden {
                display: none !important;
            }
            .message-text {
                font-size: 1.1rem;
                font-weight: 600;
                color: #1f2937;
                line-height: 1.6;
            }
            .message-close {
                background-color: var(--color-gold);
                color: #ffffff;
                border: none;
                border-radius: 999px;
                padding: 0.65rem 1.75rem;
                font-weight: 700;
                font-size: 1rem;
                cursor: pointer;
                transition: transform 0.2s ease, box-shadow 0.2s ease, background-color 0.2s ease;
            }
            .message-close:hover {
                background-color: var(--color-gold-hover);
                transform: translateY(-1px);
                box-shadow: 0 10px 20px rgba(185, 150, 53, 0.2);
            }
            .message-close.hidden {
                display: none !important;
            }
            .message-spinner {
                width: 48px;
                height: 48px;
                border-radius: 50%;
                border: 4px solid rgba(15, 23, 42, 0.1);
                border-top-color: var(--color-gold);
                animation: message-spin 1s linear infinite;
            }
            .message-spinner.hidden {
                display: none !important;
            }
            .modal-loader {
                @apply absolute inset-0 flex flex-col items-center justify-center bg-white/80 backdrop-blur-sm z-50;
                pointer-events: none;
            }
            .modal-loader.hidden {
                display: none !important;
            }
            .modal-loader-spinner {
                width: 58px;
                height: 58px;
                border-radius: 9999px;
                border: 5px solid rgba(17, 24, 39, 0.12);
                border-top-color: var(--color-gold);
                animation: message-spin 1s linear infinite;
                margin-bottom: 12px;
            }
            .modal-loader-text {
                @apply text-dark-gray font-semibold;
            }
            .notes-table-cell {
                text-align: right !important;
                vertical-align: top !important;
            }

            .quote-notes {
                @apply flex flex-col gap-1.5 items-stretch text-right;
                min-width: 180px;
            }

            .note-stack {
                @apply flex flex-col gap-1.5;
            }

            .note-card {
                @apply bg-gray-50 border border-gray-200 rounded-lg px-2.5 py-2 text-right transition-all duration-200;
            }

            .note-card-hidden {
                display: none;
            }

            .quote-notes[data-expanded="1"] .note-card-hidden {
                display: block;
            }

            .note-text {
                @apply text-xs leading-5 text-dark-gray;
                display: -webkit-box;
                -webkit-line-clamp: 2;
                -webkit-box-orient: vertical;
                overflow: hidden;
                white-space: pre-line;
            }

            .quote-notes[data-expanded="1"] .note-text {
                -webkit-line-clamp: initial;
                max-height: none;
            }

            .note-meta {
                @apply mt-1 text-[11px] text-medium-gray flex items-center gap-1;
            }

            .note-separator {
                @apply text-border text-sm leading-none;
            }

            .note-author {
                @apply font-medium text-dark-gray;
            }

            .note-date {
                @apply font-normal;
            }

            .notes-empty {
                @apply text-xs text-medium-gray bg-gray-50 border border-dashed border-gray-200 rounded-lg px-2.5 py-2 text-center;
            }

            .notes-toolbar {
                @apply flex items-center justify-between gap-2;
            }

            .notes-more-btn {
                @apply text-xs font-semibold text-gold hover:text-gold-hover transition-colors duration-200;
            }

            .notes-add-btn {
                @apply w-8 h-8 rounded-full bg-gold text-white flex items-center justify-center hover:bg-gold-hover transition-all duration-200;
            }

            .notes-add-btn i {
                @apply text-xs;
            }

            .note-composer {
                position: fixed;
                z-index: 80;
                width: min(320px, calc(100vw - 32px));
                pointer-events: none;
                opacity: 0;
                transform: translateY(12px);
                transition: opacity 0.2s ease, transform 0.2s ease;
            }

            .note-composer.active {
                pointer-events: auto;
                opacity: 1;
                transform: translateY(0);
            }

            .note-composer-content {
                @apply bg-white border border-border rounded-xl shadow-xl p-4 flex flex-col gap-3;
            }

            .note-composer-header {
                @apply flex items-center justify-between gap-3;
            }

            .note-composer-title {
                @apply text-sm font-semibold text-dark-gray;
            }

            .note-composer-close {
                @apply text-medium-gray hover:text-dark-gray transition-colors duration-200;
            }

            .note-composer-close i {
                @apply text-base;
            }

            .note-composer-textarea {
                @apply w-full border border-border rounded-lg text-sm px-3 py-2.5 focus:outline-none focus:ring-2 focus:ring-gold-light focus:border-gold resize-none;
                min-height: 110px;
            }

            .note-composer-footer {
                @apply flex items-center justify-between gap-3;
            }

            .note-composer-counter {
                @apply text-xs text-medium-gray;
            }

            .note-composer-actions {
                @apply flex items-center gap-2;
            }

            .note-composer-save {
                @apply bg-gold text-white px-4 py-2 rounded-lg text-sm font-semibold hover:bg-gold-hover transition-colors duration-200;
            }

            .note-composer-cancel {
                @apply bg-gray-100 text-medium-gray px-4 py-2 rounded-lg text-sm font-medium hover:bg-gray-200 transition-colors duration-200;
            }
            .message-overlay[data-type="error"] .message-icon {
                color: #dc2626;
            }
            .message-overlay[data-type="success"] .message-icon {
                color: #16a34a;
            }

            .view-toolbar {
                @apply flex flex-col gap-3 md:flex-row md:items-center md:justify-between border-b border-border bg-gray-50 px-4 py-3 md:px-6;
            }

            .view-mode-switch {
                @apply inline-flex items-center bg-white border border-border rounded-full p-1 shadow-sm gap-1;
            }

            .view-mode-btn {
                @apply px-3 py-1.5 text-sm font-semibold text-medium-gray rounded-full flex items-center gap-2 transition-all duration-200;
            }

            .view-mode-btn:not(.active) {
                @apply hover:text-dark-gray;
            }

            .view-mode-btn.active {
                @apply bg-gold text-white shadow-sm;
            }

            .filter-chip-row {
                @apply flex items-center gap-2 overflow-x-auto;
            }

            .cards-only {
                display: none;
            }
            [data-view-mode="cards"] .cards-only {
                display: block;
            }

            .filter-chip {
                @apply flex items-center gap-2 px-3 py-1.5 bg-white border border-border rounded-full text-xs md:text-sm text-medium-gray hover:text-gold hover:border-gold transition-all duration-200;
                white-space: nowrap;
            }

            .cards-view {
                @apply p-4 md:p-6;
            }

            .quote-cards-grid {
                @apply grid gap-3 md:gap-4;
                grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
            }

            .quote-card {
                @apply bg-white border border-border rounded-xl shadow-sm p-4 flex flex-col gap-3 transition-transform duration-200;
                position: relative;
                background-image: linear-gradient(to left, var(--row-status-fade, rgba(185, 150, 53, 0.14)) 0%, rgba(185, 150, 53, 0) 70%);
                background-repeat: no-repeat;
            }

            .quote-card:hover {
                transform: translateY(-2px);
                box-shadow: 0 12px 24px rgba(17, 24, 39, 0.08);
            }

            .quote-card::after {
                content: '';
                position: absolute;
                inset: 0;
                border-radius: 0.75rem;
                border: 2px solid transparent;
                pointer-events: none;
                transition: border-color 0.2s ease, box-shadow 0.2s ease;
            }

            .quote-card:hover::after {
                border-color: rgba(185, 150, 53, 0.18);
                box-shadow: 0 0 0 1px rgba(185, 150, 53, 0.12);
            }

            .quote-card-top {
                @apply flex items-start justify-between gap-3;
            }

            .quote-card-meta {
                @apply flex flex-wrap items-center gap-2 text-xs text-medium-gray;
            }

            .quote-card-meta .dot {
                @apply text-border;
            }

            .quote-card-price {
                @apply text-lg font-bold text-dark-gray flex items-center gap-2;
            }

            .quote-card-row {
                @apply grid grid-cols-1 sm:grid-cols-2 gap-3;
            }

            .card-section-label {
                @apply text-xs font-semibold text-medium-gray mb-1;
            }

            .card-actions {
                @apply flex flex-wrap items-center gap-2;
            }

            .card-status {
                @apply flex flex-col gap-1.5;
            }

            .card-notes {
                @apply bg-gray-50 border border-dashed border-border rounded-lg p-3;
            }

            .quote-id-chip {
                @apply inline-flex items-center gap-2 px-3 py-1.5 rounded-full bg-gold text-white text-sm font-bold shadow-sm;
            }

            .pill {
                @apply inline-flex items-center gap-2 px-3 py-1.5 rounded-full text-xs font-semibold border border-border bg-white text-medium-gray;
            }

            .pill-date {
                @apply bg-gold-light text-gold border-gold/60;
            }

            .pill-brand {
                @apply bg-white text-dark-gray border-border;
            }

            .card-section {
                @apply border-t border-border pt-3 mt-2;
            }

            .card-info-grid {
                @apply grid grid-cols-1 sm:grid-cols-2 gap-2;
            }

            .card-field {
                @apply flex items-start justify-between gap-2 bg-gray-50 border border-border rounded-lg px-3 py-2;
            }

            .card-field-label {
                @apply text-xs font-semibold text-medium-gray;
            }

            .card-field-value {
                @apply text-sm font-bold text-dark-gray text-right;
                direction: rtl;
            }

            .card-meta-badge {
                @apply text-xs font-semibold text-medium-gray;
            }
           
            @media (max-width: 768px) {
                .card {
                    @apply p-3 rounded-lg;
                }
                .table-header {
                    @apply px-2 py-3 text-xs;
                }
                .table-cell {
                    @apply px-2 py-3 text-xs;
                }
                .btn-action {
                    @apply w-8 h-8 text-xs;
                }
                .quote-id {
                    @apply text-sm;
                }
                .quote-price {
                    @apply text-sm;
                }
                .status-badge {
                    @apply px-2 py-1 text-xs;
                }
            }
        }
       
        @layer utilities {
            @keyframes message-spin {
                0% {
                    transform: rotate(0deg);
                }
                100% {
                    transform: rotate(360deg);
                }
            }
            .flatpickr-calendar {
                font-family: var(--font-family-cairo) !important;
                direction: ltr;
                z-index: 9999 !important;
            }
           
            .flatpickr-day.selected {
                background: var(--color-gold) !important;
                border-color: var(--color-gold) !important;
            }
           
            .flatpickr-day:hover {
                background: var(--color-gold-light) !important;
            }
        }
    </style>

    <?php
    $claritySnippet = $_SERVER['DOCUMENT_ROOT'] . '/clarity.php';
    if (isset($_SERVER['DOCUMENT_ROOT']) && file_exists($claritySnippet)) {
        include_once $claritySnippet;
    }
    ?>
</head>

<body>
    <!-- Loading Page -->
    <div id="pageLoader" class="fixed inset-0 bg-white/95 backdrop-blur-sm flex items-center justify-center z-50">
        <div class="spinner"></div>
    </div>
    <div class="max-w-full mx-auto p-3 md:p-6 min-h-screen">
        <!-- Header -->
        <div class="card mb-6 flex flex-col md:flex-row justify-between items-center gap-4 md:gap-6">
            <h1 class="text-xl md:text-2xl font-bold text-dark-gray flex items-center gap-3">
                <i class="fas fa-file-invoice-dollar text-gold text-lg md:text-xl"></i>
                إدارة عروض الأسعار
            </h1>
            <div class="flex flex-col md:flex-row gap-3 md:gap-6 items-center w-full md:w-auto">
                <button onclick="clearAllFilters()" class="btn-gray w-full md:w-auto">
                    <i class="fas fa-eraser"></i>
                    مسح الفلاتر
                </button>
                <button onclick="toggleCancelledQuotes()" id="toggleCancelledBtn"
                    class="<?= $showCancelled ? 'btn-gold' : 'btn-gray' ?> w-full md:w-auto"
                    data-showing="<?= $showCancelled ? '1' : '0' ?>">
                    <i class="fas <?= $showCancelled ? 'fa-eye-slash' : 'fa-eye' ?>"></i>
                    <?= $showCancelled ? 'اخفاء الملغي' : 'اظهار الملغي' ?>
                </button>
                <?php if ($canCreateQuote): ?>
                    <button onclick="createNewQuote()" class="btn-gold w-full md:w-auto">
                        <i class="fas fa-plus"></i>
                        إنشاء عرض سعر جديد
                    </button>
                <?php endif; ?>
                <?php if (!empty($canViewApprovedMaintQuoteFiles)): ?>
                    <!--<a href="maintenance/maintenance-quotes.php" class="btn-gray w-full md:w-auto inline-flex items-center justify-center gap-2 no-underline">-->
                    <!--    <i class="fas fa-file-pdf text-gold"></i>-->
                    <!--    توريد القطع-->
                    <!--</a>-->
                <?php endif; ?>
            </div>
        </div>
        <!-- Messages -->
        <div id="messageContainer" class="message-overlay hidden" role="alertdialog" aria-live="assertive"
            aria-modal="true" data-type="">
            <div class="message-modal">
                <div class="message-icon-wrapper">
                    <div id="messageSpinner" class="message-spinner hidden" aria-hidden="true"></div>
                    <i id="messageIcon" class="message-icon fas fa-info-circle"></i>
                </div>
                <div id="messageText" class="message-text"></div>
                <button id="messageCloseBtn" type="button" class="message-close">حسناً</button>
            </div>
        </div>
        <!-- Loading -->
        <div id="loading" class="hidden text-center py-16 text-medium-gray">
            <div class="spinner mx-auto mb-5"></div>
            <p>جاري تحميل البيانات...</p>
        </div>
        <!-- Quotes Table -->
        <div id="quotesTableContainer" class="card p-0" data-view-mode="table">
            <div class="view-toolbar">
                <div class="flex flex-col sm:flex-row gap-2 sm:items-center">
                    <span class="text-sm font-semibold text-medium-gray">طريقة العرض</span>
                    <div class="view-mode-switch" role="group" aria-label="تبديل طريقة العرض">
                        <button type="button" class="view-mode-btn active" data-view-mode-btn="table"
                            aria-pressed="true" onclick="handleViewModeChange('table')">
                            <i class="fas fa-table"></i>
                            <span class="hidden sm:inline">جدول</span>
                        </button>
                        <button type="button" class="view-mode-btn" data-view-mode-btn="cards" aria-pressed="false"
                            onclick="handleViewModeChange('cards')">
                            <i class="fas fa-grip"></i>
                            <span class="hidden sm:inline">كروت</span>
                        </button>
                    </div>
                </div>
                <div class="flex flex-col gap-1 w-full sm:w-auto cards-only">
                    <span class="text-xs font-semibold text-medium-gray hidden sm:block">الفلاتر السريعة</span>
                    <div class="filter-chip-row" role="list">
                        <button type="button" class="filter-chip" onclick="openFilter('status')">
                            <i class="fas fa-filter"></i>
                            <span>الحالة</span>
                        </button>
                        <button type="button" class="filter-chip" onclick="openFilter('client')">
                            <i class="fas fa-user"></i>
                            <span>العميل</span>
                        </button>
                        <button type="button" class="filter-chip" onclick="openFilter('brand')">
                            <i class="fas fa-tag"></i>
                            <span>البراند</span>
                        </button>
                        <button type="button" class="filter-chip" onclick="openFilter('date')">
                            <i class="fas fa-calendar"></i>
                            <span>التاريخ</span>
                        </button>
                    </div>
                </div>
            </div>
            <div id="tableViewWrapper" class="table-view">
                <div class="table-container">
                    <table class="modern-table">
                        <thead class="sticky top-0 z-10">
                            <tr>
                                <th onclick="openFilter('number')" data-column="number" class="table-header relative">
                                    رقم العرض
                                    <button type="button" onclick="event.stopPropagation();sortTable('number')"
                                        class="ml-2 align-middle focus:outline-none">
                                        <i
                                            class="fas fa-sort<?= ($sortBy === 'number' ? ($sortDir === 'asc' ? '-up' : '-down') : '') ?> opacity-60"></i>
                                    </button>
                                    <div
                                        class="absolute top-1 right-1 w-2 h-2 bg-gold rounded-full opacity-0 transition-all duration-300">
                                    </div>
                                </th>
                                <th onclick="openFilter('date')" data-column="date" class="table-header relative">
                                    التاريخ
                                    <button type="button" onclick="event.stopPropagation();sortTable('date')"
                                        class="ml-2 align-middle focus:outline-none">
                                        <i
                                            class="fas fa-sort<?= ($sortBy === 'date' ? ($sortDir === 'asc' ? '-up' : '-down') : '') ?> opacity-60"></i>
                                    </button>
                                    <div
                                        class="absolute top-1 right-1 w-2 h-2 bg-gold rounded-full opacity-0 transition-all duration-300">
                                    </div>
                                </th>
                                <th onclick="openFilter('client')" data-column="client" class="table-header relative">
                                    العميل
                                    <i class="fas fa-sort opacity-30 mr-2 transition-all duration-300"></i>
                                    <div
                                        class="absolute top-1 right-1 w-2 h-2 bg-gold rounded-full opacity-0 transition-all duration-300">
                                    </div>
                                </th>
                                <?php if ($canViewQuoteTablePrice): ?>
                                    <th data-column="price" class="table-header relative">
                                        قيمة العرض
                                        <button type="button" onclick="sortTable('price')"
                                            class="ml-2 align-middle focus:outline-none">
                                            <i
                                                class="fas fa-sort<?= ($sortBy === 'price' ? ($sortDir === 'asc' ? '-up' : '-down') : '') ?> opacity-60"></i>
                                        </button>
                                    </th>
                                <?php endif; ?>
                                <th onclick="openFilter('brand')" data-column="brand" class="table-header relative">
                                    البراند
                                    <i class="fas fa-sort opacity-30 mr-2 transition-all duration-300"></i>
                                    <div
                                        class="absolute top-1 right-1 w-2 h-2 bg-gold rounded-full opacity-0 transition-all duration-300">
                                    </div>
                                </th>
                                <th onclick="openFilter('user')" data-column="user" class="table-header relative">
                                    بواسطة
                                    <i class="fas fa-sort opacity-30 mr-2 transition-all duration-300"></i>
                                    <div
                                        class="absolute top-1 right-1 w-2 h-2 bg-gold rounded-full opacity-0 transition-all duration-300">
                                    </div>
                                </th>
                                <th onclick="openFilter('status')" data-column="status" class="table-header relative">
                                    حالة العرض
                                    <button type="button" onclick="event.stopPropagation();sortTable('status')"
                                        class="ml-2 align-middle focus:outline-none">
                                        <i
                                            class="fas fa-sort<?= ($sortBy === 'status' ? ($sortDir === 'asc' ? '-up' : '-down') : '') ?> opacity-60"></i>
                                    </button>
                                    <div
                                        class="absolute top-1 right-1 w-2 h-2 bg-gold rounded-full opacity-0 transition-all duration-300">
                                    </div>
                                </th>
                                <th class="table-header">الإجراءات</th>
                                <th class="table-header">الملاحظات</th>
                            </tr>
                        </thead>
                        <tbody id="quotesTableBody">
                            <?php if (empty($filteredQuotes)): ?>
                                <tr id="quotesEmptyRow" data-empty="1" class="<?= $hasMoreQuotes ? 'hidden' : '' ?>">
                                    <td colspan="<?= $canViewQuoteTablePrice ? 9 : 8 ?>"
                                        class="text-center py-20 text-medium-gray">
                                        <i class="fas fa-search text-6xl text-border mb-5 block"></i>
                                        <h3 class="text-xl mb-3 text-dark-gray font-semibold">لا توجد نتائج</h3>
                                        <p class="text-base opacity-80">جرب تغيير الفلاتر أو مسحها</p>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($filteredQuotes as $index => $quote): ?>
                                    <?php
                                    $quoteNumber = getQuoteNumberValue($quote);
                                    $quoteDate = formatDate($quote[$FIELDS['quotes']['date']]);
                                    $clientName = getClientName($quote[$FIELDS['quotes']['client']]);
                                    $totalPrice = formatPrice($quote[$FIELDS['quotes']['totalPrice']]);
                                    $brandName = getBrandName($quote[$FIELDS['quotes']['brand']]);
                                    $userName = getUserName($quote[$FIELDS['quotes']['createdBy']], $users);
                                    $quoteStatus = getQuoteStatus($quote, $externalStatuses);
                                    $rowClasses = 'table-row';
                                    if ($index % 2 === 0) {
                                        $rowClasses .= ' table-row-white';
                                    } else {
                                        $rowClasses .= ' table-row-gray';
                                    }
                                    $rowStatusColor = $quoteStatus['status_color'] ?? '';
                                    $rowFadeValue = $rowStatusColor ? hexToRgba($rowStatusColor, 0.16) : null;
                                    if (!$rowFadeValue) {
                                        $rowFadeValue = 'rgba(185, 150, 53, 0.14)';
                                    }
                                    $rowStyleAttr = ' style="--row-status-fade: ' . htmlspecialchars($rowFadeValue, ENT_QUOTES) . ';"';
                                    $canShowPdfView = $canViewQuotePdfs;
                                    $canShowPdfContract = $canDownloadSensitiveDocs;
                                    $noteEntries = extractQuoteNotes($quote, $users);
                                    $visibleNotesLimit = 2;
                                    $hasMoreNotes = count($noteEntries) > $visibleNotesLimit;
                                    ?>
                                    <tr class="<?= $rowClasses ?>" data-quote-id="<?= $quote['id'] ?>" <?= $rowStyleAttr ?>>
                                        <td class="table-cell">
                                            <div class="quote-id">#<?= convertToEnglishNumbers($quoteNumber) ?></div>
                                        </td>
                                        <td class="table-cell">
                                            <div class="quote-date"><?= $quoteDate ?></div>
                                        </td>
                                        <td class="table-cell">
                                            <div class="quote-client"><?= htmlspecialchars($clientName) ?></div>
                                        </td>
                                        <?php if ($canViewQuoteTablePrice): ?>
                                            <td class="table-cell">
                                                <div class="quote-price"><?= $totalPrice ?></div>
                                            </td>
                                        <?php endif; ?>
                                        <td class="table-cell">
                                            <div class="quote-brand"><?= htmlspecialchars($brandName) ?></div>
                                        </td>
                                        <td class="table-cell">
                                            <div class="quote-user"><?= htmlspecialchars($userName) ?></div>
                                        </td>
                                        <td class="table-cell">
                                            <?php
                                            $currentStatusId = $quoteStatus['status_id'] ?? null;
                                            $statusColor = $quoteStatus['status_color'] ?? '';
                                            $statusTextColor = $quoteStatus['status_text_color'] ?? '';
                                            $statusIconClass = $quoteStatus['status_icon'] ?? 'fa-circle';
                                            $statusOrder = $quoteStatus['status_order'] ?? '';
                                            $statusHoverColor = $quoteStatus['status_hover_color'] ?? '';
                                            $statusStyleAttr = '';
                                            if ($statusColor) {
                                                $statusStyleAttr = 'style="background-color: ' . htmlspecialchars($statusColor, ENT_QUOTES) . '; border-color: ' . htmlspecialchars($statusColor, ENT_QUOTES) . '; color: ' . htmlspecialchars($statusTextColor ?: '#ffffff', ENT_QUOTES) . ';"';
                                            }
                                            $hasRejectionReason = mb_strpos($quoteStatus['status'], 'رفض') !== false && !empty($quoteStatus['rejection_reason']);
                                            $truncatedReason = $hasRejectionReason
                                                ? mb_substr($quoteStatus['rejection_reason'], 0, 30) . (mb_strlen($quoteStatus['rejection_reason']) > 30 ? '...' : '')
                                                : '';
                                            ?>
                                            <div class="status-control" data-quote-id="<?= $quote['id'] ?>"
                                                data-current-status-id="<?= htmlspecialchars((string) ($currentStatusId ?? ''), ENT_QUOTES) ?>"
                                                data-current-status-order="<?= htmlspecialchars((string) ($statusOrder ?? ''), ENT_QUOTES) ?>">
                                                <button type="button"
                                                    class="status-badge status-trigger<?= $currentStatusId === null ? ' no-status' : '' ?>"
                                                    data-status-id="<?= htmlspecialchars((string) ($currentStatusId ?? ''), ENT_QUOTES) ?>"
                                                    data-status-color="<?= htmlspecialchars($statusColor ?? '', ENT_QUOTES) ?>"
                                                    data-status-text-color="<?= htmlspecialchars($statusTextColor ?? '', ENT_QUOTES) ?>"
                                                    data-status-icon="<?= htmlspecialchars($statusIconClass, ENT_QUOTES) ?>"
                                                    data-status-order="<?= htmlspecialchars((string) ($statusOrder ?? ''), ENT_QUOTES) ?>"
                                                    data-status-hover-color="<?= htmlspecialchars($statusHoverColor ?? '', ENT_QUOTES) ?>"
                                                    <?= $statusStyleAttr ?>>
                                                    <span class="flex items-center gap-2">
                                                        <i
                                                            class="status-icon fas <?= htmlspecialchars($statusIconClass, ENT_QUOTES) ?>"></i>
                                                        <span
                                                            class="status-label-text"><?= htmlspecialchars($quoteStatus['status']) ?></span>
                                                    </span>
                                                    <i class="fas fa-angle-down text-xs opacity-70"></i>
                                                </button>
                                                <div id="statusMenu<?= $quote['id'] ?>" class="hover-menu status-menu">
                                                    <?php
                                                    $noStatusStyleAttr = 'style="--status-option-bg: #f9fafb; --status-option-bg-hover: #e5e7eb; --status-option-text: #374151;"';
                                                    ?>
                                                    <div class="hover-option status-option<?= $currentStatusId === null ? ' selected' : '' ?>"
                                                        data-status-id="" data-status-label="لا يوجد حالة" data-status-color=""
                                                        data-status-text-color="" data-status-icon="fa-minus-circle"
                                                        data-status-order="" data-status-hover-color="" <?= $noStatusStyleAttr ?>>
                                                        <span class="status-option-swatch"
                                                            style="background-color: #f3f4f6; border-color: #d1d5db;"></span>
                                                        <span class="status-option-label">لا يوجد حالة</span>
                                                    </div>
                                                    <?php foreach ($externalStatuses as $statusOptionId => $statusOptionMeta): ?>
                                                        <?php
                                                        $optionLabel = $statusOptionMeta['label'] ?? '';
                                                        if ($optionLabel === '') {
                                                            continue;
                                                        }
                                                        $isSelected = $currentStatusId === $statusOptionId;
                                                        $optionColor = $statusOptionMeta['color'] ?? '';
                                                        $optionTextColor = $statusOptionMeta['text_color'] ?? '';
                                                        $swatchColor = $optionColor ?: '#e5e7eb';
                                                        $swatchBorder = $optionColor ? $optionColor : '#d1d5db';
                                                        $optionBackground = $optionColor ? adjustColorBrightness($optionColor, 0.28) : '#f9fafb';
                                                        $optionHoverBackground = $optionColor ? adjustColorBrightness($optionColor, 0.18) : '#e5e7eb';
                                                        $optionStyleAttr = 'style="--status-option-bg: ' . htmlspecialchars($optionBackground, ENT_QUOTES) . '; --status-option-bg-hover: ' . htmlspecialchars($optionHoverBackground, ENT_QUOTES) . '; --status-option-text: ' . htmlspecialchars($optionTextColor ?: '#1f2937', ENT_QUOTES) . ';"';
                                                        ?>
                                                        <div class="hover-option status-option<?= $isSelected ? ' selected' : '' ?>"
                                                            data-status-id="<?= $statusOptionId ?>"
                                                            data-status-label="<?= htmlspecialchars($optionLabel, ENT_QUOTES) ?>"
                                                            data-status-color="<?= htmlspecialchars($statusOptionMeta['color'] ?? '', ENT_QUOTES) ?>"
                                                            data-status-text-color="<?= htmlspecialchars($statusOptionMeta['text_color'] ?? '', ENT_QUOTES) ?>"
                                                            data-status-icon="<?= htmlspecialchars($statusOptionMeta['icon'] ?? 'fa-circle', ENT_QUOTES) ?>"
                                                            data-status-order="<?= htmlspecialchars((string) ($statusOptionMeta['order'] ?? ''), ENT_QUOTES) ?>"
                                                            data-status-hover-color="<?= htmlspecialchars($statusOptionMeta['hover_color'] ?? '', ENT_QUOTES) ?>"
                                                            <?= $optionStyleAttr ?>>
                                                            <span class="status-option-swatch"
                                                                style="background-color: <?= htmlspecialchars($swatchColor, ENT_QUOTES) ?>; border-color: <?= htmlspecialchars($swatchBorder, ENT_QUOTES) ?>;"></span>
                                                            <span
                                                                class="status-option-label"><?= htmlspecialchars($optionLabel) ?></span>
                                                        </div>
                                                    <?php endforeach; ?>
                                                </div>
                                            </div>
                                            <div class="status-reason text-xs mt-1 <?= $hasRejectionReason ? 'text-red-600' : 'hidden' ?>"
                                                <?= $hasRejectionReason ? 'title="' . htmlspecialchars($quoteStatus['rejection_reason'], ENT_QUOTES) . '"' : '' ?>>
                                                <?= $hasRejectionReason ? htmlspecialchars($truncatedReason, ENT_QUOTES) : '' ?>
                                            </div>
                                        </td>
                                        <td class="table-cell">
                                            <div class="flex gap-1 md:gap-2 flex-wrap justify-center">
                                                <button onclick="viewQuote(<?= $quote['id'] ?>)" class="btn-action btn-view"
                                                    title="عرض التفاصيل">
                                                    <i class="fas fa-eye"></i>
                                                </button>
                                                <button onclick="editQuote(<?= $quote['id'] ?>)" class="btn-action btn-edit"
                                                    title="تعديل">
                                                    <i class="fas fa-edit"></i>
                                                </button>
                                                <?php if ($canViewPayments): ?>
                                                    <button onclick="openPayments(<?= $quote['id'] ?>)"
                                                        class="btn-action btn-payment" title="سداد الدفعات">
                                                        <i class="fas fa-hand-holding-dollar"></i>
                                                    </button>
                                                <?php endif; ?>
                                                <?php if ($canShowWordToolbarMenu): ?>
                                                    <div class="hover-container">
                                                        <button onclick="toggleHoverMenu('word', <?= $quote['id'] ?>, this)"
                                                            class="btn-action btn-word" title="Word">
                                                            <i class="fas fa-file-word"></i>
                                                        </button>
                                                        <div id="wordMenu<?= $quote['id'] ?>" class="hover-menu"
                                                            data-menu-type="word" data-quote-id="<?= $quote['id'] ?>">
                                                            <?php if ($canEditQuoteWord): ?>
                                                                <div class="hover-option" onclick="viewWordQuote(<?= $quote['id'] ?>)">
                                                                    <i class="fas fa-eye ml-2"></i>عرض
                                                                </div>
                                                            <?php endif; ?>
                                                            <?php if ($canWordSalesContractDoc): ?>
                                                                <div class="hover-option"
                                                                    onclick="viewContractQuote(<?= $quote['id'] ?>)">
                                                                    <i class="fas fa-file-contract ml-2"></i>عقد
                                                                </div>
                                                            <?php endif; ?>
                                                            <?php if ($canWordHandoverDoc): ?>
                                                                <div class="hover-option"
                                                                    onclick="openDeliveryWord(<?= $quote['id'] ?>)">
                                                                    <i class="fas fa-file-circle-check ml-2"></i>محضر استلام
                                                                </div>
                                                            <?php endif; ?>
                                                            <?php if ($canWordMaintenanceHandoverDoc): ?>
                                                                <div class="hover-option"
                                                                    onclick="openGuaranteeWord(<?= $quote['id'] ?>)">
                                                                    <i class="fas fa-screwdriver-wrench ml-2"></i>محضر صيانة
                                                                </div>
                                                            <?php endif; ?>
                                                        </div>
                                                    </div>
                                                <?php endif; ?>
                                                <?php if ($canShowPdfView || $canShowPdfContract || $canPdfHandoverDoc || $canPdfMaintenanceHandoverDoc): ?>
                                                    <div class="hover-container">
                                                        <button onclick="toggleHoverMenu('pdf', <?= $quote['id'] ?>, this)"
                                                            class="btn-action btn-pdf" title="PDF">
                                                            <i class="fas fa-file-pdf"></i>
                                                        </button>
                                                        <div id="pdfMenu<?= $quote['id'] ?>" class="hover-menu" data-menu-type="pdf"
                                                            data-quote-id="<?= $quote['id'] ?>">
                                                            <?php if ($canShowPdfView): ?>
                                                                <div class="hover-option" onclick="downloadPDF(<?= $quote['id'] ?>)">
                                                                    <i class="fas fa-eye ml-2"></i>عرض
                                                                </div>
                                                            <?php endif; ?>
                                                            <?php if ($canShowPdfContract): ?>
                                                                <div class="hover-option"
                                                                    onclick="exportAsContract(<?= $quote['id'] ?>)">
                                                                    <i class="fas fa-file-contract ml-2"></i>عقد
                                                                </div>
                                                            <?php endif; ?>
                                                            <?php if ($canPdfHandoverDoc): ?>
                                                                <div class="hover-option"
                                                                    onclick="handleDeliveryPdfClick(<?= $quote['id'] ?>)">
                                                                    <i class="fas fa-file-circle-check ml-2"></i>محضر استلام
                                                                </div>
                                                            <?php endif; ?>
                                                            <?php if ($canPdfMaintenanceHandoverDoc): ?>
                                                                <div class="hover-option"
                                                                    onclick="handleGuaranteePdfClick(<?= $quote['id'] ?>)">
                                                                    <i class="fas fa-screwdriver-wrench ml-2"></i>محضر صيانة
                                                                </div>
                                                            <?php endif; ?>
                                                        </div>
                                                    </div>
                                                <?php endif; ?>
                                                <?php if ($canRegenerateQuoteDocuments): ?>
                                                    <button onclick="refreshPDF(<?= $quote['id'] ?>)" class="btn-action btn-print"
                                                        title="إعادة إنشاء المستندات">
                                                        <i class="fa-solid fa-rotate-right"></i>
                                                    </button>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                        <td class="table-cell notes-table-cell align-top">
                                            <div class="quote-notes" id="quoteNotes<?= $quote['id'] ?>"
                                                data-quote-id="<?= $quote['id'] ?>" data-expanded="0"
                                                data-visible-limit="<?= $visibleNotesLimit ?>">
                                                <div class="notes-toolbar">
                                                    <button type="button"
                                                        class="notes-more-btn<?= $hasMoreNotes ? '' : ' hidden' ?>"
                                                        data-quote-id="<?= $quote['id'] ?>"
                                                        onclick="toggleNotesVisibility(<?= $quote['id'] ?>)">
                                                        عرض المزيد
                                                    </button>
                                                    <button type="button" class="notes-add-btn" aria-label="إضافة ملاحظة"
                                                        onclick="openNoteComposer(<?= $quote['id'] ?>, this)">
                                                        <i class="fas fa-plus"></i>
                                                    </button>
                                                </div>
                                                <div class="note-stack" id="noteStack<?= $quote['id'] ?>">
                                                    <?php if (!empty($noteEntries)): ?>
                                                        <?php foreach ($noteEntries as $noteIndex => $noteEntry): ?>
                                                            <?php
                                                            $isHidden = $noteIndex >= $visibleNotesLimit;
                                                            $noteTextRaw = trim((string) ($noteEntry['text'] ?? ''));
                                                            $noteTextHtml = $noteTextRaw !== '' ? nl2br(htmlspecialchars($noteTextRaw, ENT_QUOTES, 'UTF-8')) : '&mdash;';
                                                            $noteAuthor = htmlspecialchars($noteEntry['author'] ?? 'غير معروف', ENT_QUOTES, 'UTF-8');
                                                            $noteDate = htmlspecialchars($noteEntry['created_at_formatted'] ?? '', ENT_QUOTES, 'UTF-8');
                                                            ?>
                                                            <div class="note-card<?= $isHidden ? ' note-card-hidden' : '' ?>"
                                                                data-note-id="<?= $noteEntry['id'] ?>">
                                                                <div class="note-text"><?= $noteTextHtml ?></div>
                                                                <div class="note-meta">
                                                                    <span class="note-author"><?= $noteAuthor ?></span>
                                                                    <?php if ($noteDate !== ''): ?>
                                                                        <span class="note-separator">&bull;</span>
                                                                        <span class="note-date"><?= $noteDate ?></span>
                                                                    <?php endif; ?>
                                                                </div>
                                                            </div>
                                                        <?php endforeach; ?>
                                                    <?php else: ?>
                                                        <div class="notes-empty">لا توجد ملاحظات</div>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <div id="cardsViewWrapper" class="cards-view hidden">
                <?php if (empty($filteredQuotes)): ?>
                    <div id="quotesCardsEmpty" data-empty="1"
                        class="text-center py-16 text-medium-gray <?= $hasMoreQuotes ? 'hidden' : '' ?>">
                        <i class="fas fa-layer-group text-5xl text-border mb-4 block"></i>
                        <p class="text-lg font-semibold text-dark-gray mb-2">لا توجد عروض لعرضها</p>
                        <p class="text-sm opacity-80">
                            <?= $canCreateQuote ? 'يمكنك تغيير الفلاتر أو إنشاء عرض جديد' : 'يمكنك تغيير الفلاتر' ?>
                        </p>
                    </div>
                <?php else: ?>
                    <div class="quote-cards-grid" id="quoteCardsGrid">
                        <?php foreach ($filteredQuotes as $index => $quote): ?>
                            <?php
                            $quoteNumber = getQuoteNumberValue($quote);
                            $quoteDate = formatDate($quote[$FIELDS['quotes']['date']]);
                            $clientName = getClientName($quote[$FIELDS['quotes']['client']]);
                            $totalPrice = formatPrice($quote[$FIELDS['quotes']['totalPrice']]);
                            $brandName = getBrandName($quote[$FIELDS['quotes']['brand']]);
                            $userName = getUserName($quote[$FIELDS['quotes']['createdBy']], $users);
                            $quoteStatus = getQuoteStatus($quote, $externalStatuses);
                            $rowStatusColor = $quoteStatus['status_color'] ?? '';
                            $rowFadeValue = $rowStatusColor ? hexToRgba($rowStatusColor, 0.16) : 'rgba(185, 150, 53, 0.14)';
                            $canShowPdfView = $canViewQuotePdfs;
                            $canShowPdfContract = $canDownloadSensitiveDocs;
                            $noteEntries = extractQuoteNotes($quote, $users);
                            $visibleNotesLimit = 2;
                            $hasMoreNotes = count($noteEntries) > $visibleNotesLimit;
                            $currentStatusId = $quoteStatus['status_id'] ?? null;
                            $statusColor = $quoteStatus['status_color'] ?? '';
                            $statusTextColor = $quoteStatus['status_text_color'] ?? '';
                            $statusIconClass = $quoteStatus['status_icon'] ?? 'fa-circle';
                            $statusOrder = $quoteStatus['status_order'] ?? '';
                            $statusHoverColor = $quoteStatus['status_hover_color'] ?? '';
                            $statusStyleAttr = $statusColor
                                ? 'style="background-color: ' . htmlspecialchars($statusColor, ENT_QUOTES) . '; border-color: ' . htmlspecialchars($statusColor, ENT_QUOTES) . '; color: ' . htmlspecialchars($statusTextColor ?: '#ffffff', ENT_QUOTES) . ';"'
                                : '';
                            $hasRejectionReason = mb_strpos($quoteStatus['status'], 'رفض') !== false && !empty($quoteStatus['rejection_reason']);
                            $truncatedReason = $hasRejectionReason
                                ? mb_substr($quoteStatus['rejection_reason'], 0, 30) . (mb_strlen($quoteStatus['rejection_reason']) > 30 ? '...' : '')
                                : '';
                            ?>
                            <article class="quote-card" data-quote-id="<?= $quote['id'] ?>"
                                style="--row-status-fade: <?= htmlspecialchars($rowFadeValue, ENT_QUOTES) ?>;">
                                <div class="quote-card-top">
                                    <div class="flex flex-col gap-2">
                                        <span class="quote-id-chip">#<?= convertToEnglishNumbers($quoteNumber) ?></span>
                                        <span class="card-meta-badge">رقم النظام:
                                            <?= convertToEnglishNumbers($quote['id']) ?></span>
                                    </div>
                                    <div class="flex flex-col items-end gap-2">
                                        <span class="pill pill-date">
                                            <i class="fas fa-calendar text-gold"></i>
                                            <?= $quoteDate ?>
                                        </span>
                                        <span class="pill pill-brand">
                                            <i class="fas fa-tag text-medium-gray"></i>
                                            <?= htmlspecialchars($brandName) ?>
                                        </span>
                                    </div>
                                </div>
                                <div class="card-section">
                                    <div class="card-field">
                                        <span class="card-field-label">العميل</span>
                                        <span class="card-field-value"><?= htmlspecialchars($clientName) ?></span>
                                    </div>
                                    <?php if ($canViewQuoteTablePrice): ?>
                                        <div class="card-info-grid">
                                            <div class="card-field">
                                                <span class="card-field-label">قيمة العرض</span>
                                                <span class="card-field-value"><?= $totalPrice ?></span>
                                            </div>
                                            <div class="card-field">
                                                <span class="card-field-label">بواسطة</span>
                                                <span class="card-field-value"><?= htmlspecialchars($userName) ?></span>
                                            </div>
                                        </div>
                                    <?php else: ?>
                                        <div class="card-info-grid">
                                            <div class="card-field">
                                                <span class="card-field-label">بواسطة</span>
                                                <span class="card-field-value"><?= htmlspecialchars($userName) ?></span>
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                <div class="card-section card-status">
                                    <div class="card-section-label">حالة العرض</div>
                                    <div class="status-control" data-quote-id="<?= $quote['id'] ?>"
                                        data-current-status-id="<?= htmlspecialchars((string) ($currentStatusId ?? ''), ENT_QUOTES) ?>"
                                        data-current-status-order="<?= htmlspecialchars((string) ($statusOrder ?? ''), ENT_QUOTES) ?>">
                                        <button type="button"
                                            class="status-badge status-trigger<?= $currentStatusId === null ? ' no-status' : '' ?>"
                                            data-status-id="<?= htmlspecialchars((string) ($currentStatusId ?? ''), ENT_QUOTES) ?>"
                                            data-status-color="<?= htmlspecialchars($statusColor ?? '', ENT_QUOTES) ?>"
                                            data-status-text-color="<?= htmlspecialchars($statusTextColor ?? '', ENT_QUOTES) ?>"
                                            data-status-icon="<?= htmlspecialchars($statusIconClass, ENT_QUOTES) ?>"
                                            data-status-order="<?= htmlspecialchars((string) ($statusOrder ?? ''), ENT_QUOTES) ?>"
                                            data-status-hover-color="<?= htmlspecialchars($statusHoverColor ?? '', ENT_QUOTES) ?>"
                                            <?= $statusStyleAttr ?>>
                                            <span class="flex items-center gap-2">
                                                <i
                                                    class="status-icon fas <?= htmlspecialchars($statusIconClass, ENT_QUOTES) ?>"></i>
                                                <span
                                                    class="status-label-text"><?= htmlspecialchars($quoteStatus['status']) ?></span>
                                            </span>
                                            <i class="fas fa-angle-down text-xs opacity-70"></i>
                                        </button>
                                        <div id="statusMenuCard<?= $quote['id'] ?>" class="hover-menu status-menu">
                                            <?php
                                            $noStatusStyleAttr = 'style="--status-option-bg: #f9fafb; --status-option-bg-hover: #e5e7eb; --status-option-text: #374151;"';
                                            ?>
                                            <div class="hover-option status-option<?= $currentStatusId === null ? ' selected' : '' ?>"
                                                data-status-id="" data-status-label="لا يوجد حالة" data-status-color=""
                                                data-status-text-color="" data-status-icon="fa-minus-circle"
                                                data-status-order="" data-status-hover-color="" <?= $noStatusStyleAttr ?>>
                                                <span class="status-option-swatch"
                                                    style="background-color: #f3f4f6; border-color: #d1d5db;"></span>
                                                <span class="status-option-label">لا يوجد حالة</span>
                                            </div>
                                            <?php foreach ($externalStatuses as $statusOptionId => $statusOptionMeta): ?>
                                                <?php
                                                $optionLabel = $statusOptionMeta['label'] ?? '';
                                                if ($optionLabel === '') {
                                                    continue;
                                                }
                                                $isSelected = $currentStatusId === $statusOptionId;
                                                $optionColor = $statusOptionMeta['color'] ?? '';
                                                $optionTextColor = $statusOptionMeta['text_color'] ?? '';
                                                $swatchColor = $optionColor ?: '#e5e7eb';
                                                $swatchBorder = $optionColor ? $optionColor : '#d1d5db';
                                                $optionBackground = $optionColor ? adjustColorBrightness($optionColor, 0.28) : '#f9fafb';
                                                $optionHoverBackground = $optionColor ? adjustColorBrightness($optionColor, 0.18) : '#e5e7eb';
                                                $optionStyleAttr = 'style="--status-option-bg: ' . htmlspecialchars($optionBackground, ENT_QUOTES) . '; --status-option-bg-hover: ' . htmlspecialchars($optionHoverBackground, ENT_QUOTES) . '; --status-option-text: ' . htmlspecialchars($optionTextColor ?: '#1f2937', ENT_QUOTES) . ';"';
                                                ?>
                                                <div class="hover-option status-option<?= $isSelected ? ' selected' : '' ?>"
                                                    data-status-id="<?= $statusOptionId ?>"
                                                    data-status-label="<?= htmlspecialchars($optionLabel, ENT_QUOTES) ?>"
                                                    data-status-color="<?= htmlspecialchars($statusOptionMeta['color'] ?? '', ENT_QUOTES) ?>"
                                                    data-status-text-color="<?= htmlspecialchars($statusOptionMeta['text_color'] ?? '', ENT_QUOTES) ?>"
                                                    data-status-icon="<?= htmlspecialchars($statusOptionMeta['icon'] ?? 'fa-circle', ENT_QUOTES) ?>"
                                                    data-status-order="<?= htmlspecialchars((string) ($statusOptionMeta['order'] ?? ''), ENT_QUOTES) ?>"
                                                    data-status-hover-color="<?= htmlspecialchars($statusOptionMeta['hover_color'] ?? '', ENT_QUOTES) ?>"
                                                    <?= $optionStyleAttr ?>>
                                                    <span class="status-option-swatch"
                                                        style="background-color: <?= htmlspecialchars($swatchColor, ENT_QUOTES) ?>; border-color: <?= htmlspecialchars($swatchBorder, ENT_QUOTES) ?>;"></span>
                                                    <span class="status-option-label"><?= htmlspecialchars($optionLabel) ?></span>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                    <div class="status-reason text-xs <?= $hasRejectionReason ? 'text-red-600' : 'hidden' ?>"
                                        <?= $hasRejectionReason ? 'title="' . htmlspecialchars($quoteStatus['rejection_reason'], ENT_QUOTES) . '"' : '' ?>>
                                        <?= $hasRejectionReason ? htmlspecialchars($truncatedReason, ENT_QUOTES) : '' ?>
                                    </div>
                                </div>
                                <div class="card-section">
                                    <div class="card-section-label">الإجراءات</div>
                                    <div class="card-actions">
                                        <button onclick="viewQuote(<?= $quote['id'] ?>)" class="btn-action btn-view"
                                            title="عرض التفاصيل">
                                            <i class="fas fa-eye"></i>
                                        </button>
                                        <button onclick="editQuote(<?= $quote['id'] ?>)" class="btn-action btn-edit"
                                            title="تعديل">
                                            <i class="fas fa-edit"></i>
                                        </button>
                                        <?php if ($canViewPayments): ?>
                                            <button onclick="openPayments(<?= $quote['id'] ?>)" class="btn-action btn-payment"
                                                title="سداد الدفعات">
                                                <i class="fas fa-hand-holding-dollar"></i>
                                            </button>
                                        <?php endif; ?>
                                        <?php if ($canShowWordToolbarMenu): ?>
                                            <div class="hover-container">
                                                <button onclick="toggleHoverMenu('word', <?= $quote['id'] ?>, this)"
                                                    class="btn-action btn-word" title="Word">
                                                    <i class="fas fa-file-word"></i>
                                                </button>
                                                <div id="wordMenuCard<?= $quote['id'] ?>" class="hover-menu" data-menu-type="word"
                                                    data-quote-id="<?= $quote['id'] ?>">
                                                    <?php if ($canEditQuoteWord): ?>
                                                        <div class="hover-option" onclick="viewWordQuote(<?= $quote['id'] ?>)">
                                                            <i class="fas fa-eye ml-2"></i>عرض
                                                        </div>
                                                    <?php endif; ?>
                                                    <?php if ($canWordSalesContractDoc): ?>
                                                        <div class="hover-option" onclick="viewContractQuote(<?= $quote['id'] ?>)">
                                                            <i class="fas fa-file-contract ml-2"></i>عقد
                                                        </div>
                                                    <?php endif; ?>
                                                    <?php if ($canWordHandoverDoc): ?>
                                                        <div class="hover-option" onclick="openDeliveryWord(<?= $quote['id'] ?>)">
                                                            <i class="fas fa-file-circle-check ml-2"></i>محضر استلام
                                                        </div>
                                                    <?php endif; ?>
                                                    <?php if ($canWordMaintenanceHandoverDoc): ?>
                                                        <div class="hover-option" onclick="openGuaranteeWord(<?= $quote['id'] ?>)">
                                                            <i class="fas fa-screwdriver-wrench ml-2"></i>محضر صيانة
                                                        </div>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        <?php endif; ?>
                                        <?php if ($canShowPdfView || $canShowPdfContract || $canPdfHandoverDoc || $canPdfMaintenanceHandoverDoc): ?>
                                            <div class="hover-container">
                                                <button onclick="toggleHoverMenu('pdf', <?= $quote['id'] ?>, this)"
                                                    class="btn-action btn-pdf" title="PDF">
                                                    <i class="fas fa-file-pdf"></i>
                                                </button>
                                                <div id="pdfMenuCard<?= $quote['id'] ?>" class="hover-menu" data-menu-type="pdf"
                                                    data-quote-id="<?= $quote['id'] ?>">
                                                    <?php if ($canShowPdfView): ?>
                                                        <div class="hover-option" onclick="downloadPDF(<?= $quote['id'] ?>)">
                                                            <i class="fas fa-eye ml-2"></i>عرض
                                                        </div>
                                                    <?php endif; ?>
                                                    <?php if ($canShowPdfContract): ?>
                                                        <div class="hover-option" onclick="exportAsContract(<?= $quote['id'] ?>)">
                                                            <i class="fas fa-file-contract ml-2"></i>عقد
                                                        </div>
                                                    <?php endif; ?>
                                                    <?php if ($canPdfHandoverDoc): ?>
                                                        <div class="hover-option" onclick="handleDeliveryPdfClick(<?= $quote['id'] ?>)">
                                                            <i class="fas fa-file-circle-check ml-2"></i>محضر استلام
                                                        </div>
                                                    <?php endif; ?>
                                                    <?php if ($canPdfMaintenanceHandoverDoc): ?>
                                                        <div class="hover-option"
                                                            onclick="handleGuaranteePdfClick(<?= $quote['id'] ?>)">
                                                            <i class="fas fa-screwdriver-wrench ml-2"></i>محضر صيانة
                                                        </div>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        <?php endif; ?>
                                        <?php if ($canRegenerateQuoteDocuments): ?>
                                            <button onclick="refreshPDF(<?= $quote['id'] ?>)" class="btn-action btn-print"
                                                title="إعادة إنشاء المستندات">
                                                <i class="fa-solid fa-rotate-right"></i>
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="card-section card-notes">
                                    <div class="card-section-label">الملاحظات</div>
                                    <div class="quote-notes" id="quoteNotesCard<?= $quote['id'] ?>"
                                        data-quote-id="<?= $quote['id'] ?>" data-expanded="0"
                                        data-visible-limit="<?= $visibleNotesLimit ?>">
                                        <div class="notes-toolbar">
                                            <button type="button" class="notes-more-btn<?= $hasMoreNotes ? '' : ' hidden' ?>"
                                                data-quote-id="<?= $quote['id'] ?>"
                                                onclick="toggleNotesVisibility(<?= $quote['id'] ?>)">
                                                عرض المزيد
                                            </button>
                                            <button type="button" class="notes-add-btn" aria-label="إضافة ملاحظة"
                                                onclick="openNoteComposer(<?= $quote['id'] ?>, this)">
                                                <i class="fas fa-plus"></i>
                                            </button>
                                        </div>
                                        <div class="note-stack" id="noteStackCard<?= $quote['id'] ?>">
                                            <?php if (!empty($noteEntries)): ?>
                                                <?php foreach ($noteEntries as $noteIndex => $noteEntry): ?>
                                                    <?php
                                                    $isHidden = $noteIndex >= $visibleNotesLimit;
                                                    $noteTextRaw = trim((string) ($noteEntry['text'] ?? ''));
                                                    $noteTextHtml = $noteTextRaw !== '' ? nl2br(htmlspecialchars($noteTextRaw, ENT_QUOTES, 'UTF-8')) : '&mdash;';
                                                    $noteAuthor = htmlspecialchars($noteEntry['author'] ?? 'غير معروف', ENT_QUOTES, 'UTF-8');
                                                    $noteDate = htmlspecialchars($noteEntry['created_at_formatted'] ?? '', ENT_QUOTES, 'UTF-8');
                                                    ?>
                                                    <div class="note-card<?= $isHidden ? ' note-card-hidden' : '' ?>"
                                                        data-note-id="<?= $noteEntry['id'] ?>">
                                                        <div class="note-text"><?= $noteTextHtml ?></div>
                                                        <div class="note-meta">
                                                            <span class="note-author"><?= $noteAuthor ?></span>
                                                            <?php if ($noteDate !== ''): ?>
                                                                <span class="note-separator">&bull;</span>
                                                                <span class="note-date"><?= $noteDate ?></span>
                                                            <?php endif; ?>
                                                        </div>
                                                    </div>
                                                <?php endforeach; ?>
                                            <?php else: ?>
                                                <div class="notes-empty">لا توجد ملاحظات</div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
            <div id="loadMoreIndicator" class="hidden text-center py-4 text-sm text-medium-gray">
                <div class="spinner mx-auto mb-2"></div>
                <div>جاري تحميل المزيد...</div>
            </div>
            <div id="loadMoreSentinel" class="h-1"></div>
        </div>
    </div>
    <!-- Filter Modal -->
    <div id="filterModal" class="hidden modal-overlay">
        <div class="modal-content">
            <div class="p-5 border-b border-border bg-light-gray flex justify-between items-center">
                <div id="filterTitle" class="text-lg font-bold text-dark-gray">فلتر</div>
                <button onclick="closeFilter()"
                    class="text-xl cursor-pointer text-medium-gray p-1 rounded-md transition-all duration-300 hover:bg-border hover:text-dark-gray">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div id="filterBody" class="p-5 max-h-96 overflow-y-auto">
                <!-- Filter content will be populated here -->
            </div>
            <div class="p-5 border-t border-border bg-white flex flex-col md:flex-row gap-3">
                <button type="button" onclick="resetCurrentFilterValues()" class="btn-gray w-full md:w-1/3">
                    <i class="fas fa-undo"></i>
                    مسح هذا الفلتر
                </button>
                <button type="button" onclick="applyFilters(true); closeFilter();" class="btn-gold w-full md:w-2/3">
                    <i class="fas fa-check"></i>
                    تطبيق الفلتر
                </button>
            </div>
        </div>
    </div>
    <!-- Status Update Modal -->
    <div id="statusModal" class="hidden modal-overlay">
        <div class="modal-content">
            <div class="p-5 border-b border-border bg-light-gray flex justify-between items-center">
                <div class="text-lg font-bold text-dark-gray">تحديث حالة العرض</div>
                <button onclick="closeStatusModal()"
                    class="text-xl cursor-pointer text-medium-gray p-1 rounded-md transition-all duration-300 hover:bg-border hover:text-dark-gray">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="p-5">
                <form id="statusForm" onsubmit="return false;">
                    <input type="hidden" id="statusQuoteId" name="quote_id">
                    <input type="hidden" id="statusSelectedId" name="status_id" value="">
                    <input type="hidden" id="statusSelectedLabel" name="status_label" value="">

                    <div class="mb-4">
                        <div class="flex items-center justify-between mb-2">
                            <label class="block text-sm font-medium text-dark-gray">مسار الحالة</label>
                            <span id="statusCurrentLabel" class="text-xs text-medium-gray"></span>
                        </div>
                        <div id="statusStackContainer" class="status-stack-modal" aria-live="polite"></div>
                    </div>

                    <div id="rejectionReasonDiv" class="mb-4 hidden">
                        <label class="block text-sm font-medium text-dark-gray mb-2">سبب الرفض</label>
                        <textarea id="rejectionReason" name="rejection_reason" rows="3"
                            class="w-full p-3 border border-border rounded-md text-sm focus:border-gold focus:ring-2 focus:ring-gold-light focus:outline-none"
                            placeholder="يرجى إدخال سبب الرفض..."></textarea>
                    </div>

                    <div class="flex gap-3">
                        <button type="button" onclick="closeStatusModal()"
                            class="flex-1 bg-gray-500 text-white py-3 px-4 rounded-md font-medium hover:bg-gray-600 transition-colors">
                            <i class="fas fa-times mr-2"></i>
                            إغلاق
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <!-- Modal for viewing/editing quotes -->
    <div id="quoteModal" class="hidden fixed inset-0 bg-black/80 backdrop-blur-sm z-50">
        <div class="w-full h-full bg-white relative">
            <div
                class="p-3 md:p-6 border-b border-border bg-gradient-to-r from-light-gray to-gray-50 flex justify-between items-center">
                <div id="modalTitle" class="text-lg md:text-xl font-bold text-dark-gray">عرض السعر</div>
                <button onclick="closeModal()"
                    class="text-xl md:text-2xl cursor-pointer text-medium-gray p-2 rounded-md transition-all duration-300 hover:bg-gray-100 hover:text-dark-gray">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div id="modalLoader" class="modal-loader hidden" aria-hidden="true">
                <div class="modal-loader-spinner" role="presentation"></div>
                <div class="modal-loader-text">جارٍ تحميل الملف...</div>
            </div>
            <iframe id="modalIframe" class="w-full border-0" style="height: calc(100% - 70px);"
                src="about:blank"></iframe>
        </div>
    </div>
    <div id="noteComposer" class="note-composer hidden" role="dialog" aria-modal="false" data-quote-id=""
        style="top: 0; right: 0;">
        <div class="note-composer-content">
            <div class="note-composer-header">
                <span class="note-composer-title">إضافة ملاحظة</span>
                <button type="button" class="note-composer-close" onclick="closeNoteComposer()" aria-label="إغلاق">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <textarea id="noteComposerTextarea" class="note-composer-textarea" maxlength="2000"
                placeholder="أضف ملاحظتك هنا..."></textarea>
            <div class="note-composer-footer">
                <span id="noteComposerCounter" class="note-composer-counter">0 / 2000</span>
                <div class="note-composer-actions">
                    <button type="button" class="note-composer-save" onclick="submitNoteComposer()">حفظ</button>
                    <button type="button" class="note-composer-cancel" onclick="closeNoteComposer()">
                        إلغاء
                    </button>
                </div>
            </div>
        </div>
    </div>
    <!-- Flatpickr JS -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/flatpickr/4.6.13/flatpickr.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/flatpickr/4.6.13/l10n/ar.min.js"></script>
    <script>
        let quotes = <?= safeJsonForJs($filteredQuotes, '[]') ?> || [];
        const statusOptionsMap = <?= safeJsonForJs($externalStatuses, '{}') ?> || {};
        const statusActionsByQuote = <?= safeJsonForJs($STATUS_ACTIONS_CACHE, '{}') ?> || {};
        const canChangeQuoteStatus = <?= safeJsonForJs($canChangeQuoteStatus, 'false') ?>;
        const canTransitionToCancelled = <?= safeJsonForJs($canTransitionToCancelled, 'false') ?>;
        const statusTransitionRules = <?= safeJsonForJs($statusTransitionRules, '{}') ?> || {};
        const usersData = <?= safeJsonForJs($users, '[]') ?>;
        const currentUserId = <?= safeJsonForJs($current_user_id, '0') ?>;
        const currentUserName = <?= safeJsonForJs($currentUserName, '""') ?>;
        const isShowingCancelled = <?= $showCancelled ? 'true' : 'false' ?>;
        const quotesContractsAccess = <?= safeJsonForJs($QUOTES_CONTRACTS_STATUS_ACCESS, '{}') ?> || {};
        const canAccessPayments = <?= safeJsonForJs($canViewPayments, 'false') ?>;
        const canCreateQuote = <?= $canCreateQuote ? 'true' : 'false' ?>;
        const canWordQuoteMain = <?= safeJsonForJs((bool) $canEditQuoteWord, 'false') ?>;
        const canWordSalesContract = <?= safeJsonForJs((bool) $canWordSalesContractDoc, 'false') ?>;
        const canWordHandover = <?= safeJsonForJs((bool) $canWordHandoverDoc, 'false') ?>;
        const canWordMaintenanceHandover = <?= safeJsonForJs((bool) $canWordMaintenanceHandoverDoc, 'false') ?>;
        const canPdfHandover = <?= safeJsonForJs((bool) $canPdfHandoverDoc, 'false') ?>;
        const canPdfMaintenanceHandover = <?= safeJsonForJs((bool) $canPdfMaintenanceHandoverDoc, 'false') ?>;
        const quotesContractsActionStatuses = Array.isArray(quotesContractsAccess.action_statuses)
            ? quotesContractsAccess.action_statuses.map(value => parseInt(value, 10)).filter(value => Number.isFinite(value))
            : [];
        const quotesContractsActionCategories = Array.isArray(quotesContractsAccess.action_categories)
            ? quotesContractsAccess.action_categories.map(value => parseInt(value, 10)).filter(value => Number.isFinite(value))
            : [];
        const quotesContractsRestrictAction = !!quotesContractsAccess.restrict_action;
        const TEMPLATE_PROCESSOR_ENDPOINT = 'https:///system/docs/template_processor.php';
        const MQ_LOG_ENDPOINT = `${window.location.pathname}${window.location.search || ''}`;
        const ACTION_TRACK_ENDPOINT = MQ_LOG_ENDPOINT;
        const SERVER_ERROR_LOG_IDS = <?= safeJsonForJs($RECORDED_SERVER_ERROR_IDS, '[]') ?> || [];
        const TEMPLATE_RETRYABLE_STATUS = new Set([408, 425, 429, 500, 502, 503, 504]);
        const TEMPLATE_REQUEST_MAX_ATTEMPTS = 3;
        const TEMPLATE_REQUEST_RETRY_DELAY_MS = 1500;
        const messageContainer = document.getElementById('messageContainer');
        const messageTextEl = document.getElementById('messageText');
        const messageIconEl = document.getElementById('messageIcon');
        const messageSpinnerEl = document.getElementById('messageSpinner');
        const messageCloseBtn = document.getElementById('messageCloseBtn');
        const modalIframe = document.getElementById('modalIframe');
        const modalLoader = document.getElementById('modalLoader');
        const modalLoaderTextEl = modalLoader ? modalLoader.querySelector('.modal-loader-text') : null;
        const statusStackContainerEl = document.getElementById('statusStackContainer');
        const statusSelectedIdInput = document.getElementById('statusSelectedId');
        const statusSelectedLabelInput = document.getElementById('statusSelectedLabel');
        const statusCurrentLabelEl = document.getElementById('statusCurrentLabel');
        // Global variables
        const quotesTableContainerEl = document.getElementById('quotesTableContainer');
        const tableViewWrapperEl = document.getElementById('tableViewWrapper');
        const cardsViewWrapperEl = document.getElementById('cardsViewWrapper');
        const VIEW_MODE_STORAGE_KEY = 'mq_view_mode';
        const loadMoreSentinelEl = document.getElementById('loadMoreSentinel');
        const loadMoreIndicatorEl = document.getElementById('loadMoreIndicator');
        let hasMoreQuotes = <?= $hasMoreQuotes ? 'true' : 'false' ?>;
        let nextQuotesCursor = <?= safeJsonForJs($nextQuotesCursor, 'null') ?> || null;
        let isLoadingMore = false;
        let filterOptionsCache = null;
        let filterOptionsLoading = null;
        if (hasMoreQuotes && !nextQuotesCursor) {
            hasMoreQuotes = false;
        }
        let selectedRowElement = null;
        let currentFilterType = null;
        let datePickerFrom = null;
        let datePickerTo = null;
        let activeFilters = <?= safeJsonForJs($activeFilters, '{"number":[],"date":{"from":null,"to":null},"client":[],"brand":[],"user":[],"status":[]}') ?> || {
            number: [],
            date: { from: null, to: null },
            client: [],
            brand: [],
            user: [],
            status: []
        };
        let draftFilters = null;
        let currentSortBy = <?= safeJsonForJs($sortBy, '""') ?> || '';
        let currentSortDir = <?= safeJsonForJs($sortDir ?? 'desc', '"desc"') ?> || 'desc';
        const canSortByPrice = <?= $canViewQuoteTablePrice ? 'true' : 'false' ?>;
        let submittingFilters = false;
        let messageTimeout = null;
        const noteComposerEl = document.getElementById('noteComposer');
        const noteComposerTextareaEl = document.getElementById('noteComposerTextarea');
        const noteComposerCounterEl = document.getElementById('noteComposerCounter');
        const NOTE_MAX_LENGTH = 2000;
        let noteComposerQuoteId = null;
        let noteComposerAnchorEl = null;
        let noteComposerSubmitting = false;
        let viewModeResizeTimeout = null;

        function normalizeActionPayload(detail, depth = 0) {
            if (depth > 2) {
                return '[truncated]';
            }
            if (detail === null || detail === undefined) {
                return null;
            }
            if (Array.isArray(detail)) {
                return detail.slice(0, 10).map(item => normalizeActionPayload(item, depth + 1));
            }
            if (typeof detail === 'object') {
                const normalized = {};
                Object.keys(detail).slice(0, 10).forEach(key => {
                    normalized[key] = normalizeActionPayload(detail[key], depth + 1);
                });
                return normalized;
            }
            if (typeof detail === 'string') {
                const compact = detail.trim();
                return compact.length > 300 ? `${compact.slice(0, 300)}...` : compact;
            }
            if (typeof detail === 'number' || typeof detail === 'boolean') {
                return detail;
            }
            return String(detail);
        }

        function getStoredViewMode() {
            try {
                const stored = localStorage.getItem(VIEW_MODE_STORAGE_KEY);
                if (stored === 'cards' || stored === 'table') {
                    return stored;
                }
            } catch (e) {
                return null;
            }
            return null;
        }

        function getActiveViewMode() {
            const container = document.getElementById('quotesTableContainer');
            const current = container && container.dataset.viewMode ? container.dataset.viewMode : '';
            return current === 'cards' ? 'cards' : 'table';
        }

        function applyViewMode(mode, { userInitiated = false } = {}) {
            if (!quotesTableContainerEl || !tableViewWrapperEl || !cardsViewWrapperEl) {
                return;
            }
            const nextMode = mode === 'cards' ? 'cards' : 'table';
            quotesTableContainerEl.dataset.viewMode = nextMode;
            tableViewWrapperEl.classList.toggle('hidden', nextMode === 'cards');
            cardsViewWrapperEl.classList.toggle('hidden', nextMode !== 'cards');
            document.querySelectorAll('[data-view-mode-btn]').forEach(btn => {
                const isActive = btn.dataset.viewModeBtn === nextMode;
                btn.classList.toggle('active', isActive);
                btn.setAttribute('aria-pressed', isActive ? 'true' : 'false');
            });
            if (userInitiated) {
                try {
                    localStorage.setItem(VIEW_MODE_STORAGE_KEY, nextMode);
                } catch (e) {
                    // ignore storage errors
                }
            }
        }

        function handleViewModeChange(mode) {
            applyViewMode(mode, { userInitiated: true });
            trackAction('view_mode_change', { mode });
        }

        function initViewMode() {
            const stored = getStoredViewMode();
            const suggested = window.innerWidth < 1024 ? 'cards' : 'table';
            applyViewMode(stored || suggested);
        }

        function handleViewModeResize() {
            if (getStoredViewMode()) {
                return;
            }
            const suggested = window.innerWidth < 1024 ? 'cards' : 'table';
            applyViewMode(suggested);
        }

        function trackAction(eventName, detail = {}, options = {}) {
            try {
                const normalizedEvent = (eventName || '').toString().trim() || 'client_event';
                const payload = normalizeActionPayload(detail);
                const params = new URLSearchParams();
                params.set('action', 'track_event');
                params.set('event', normalizedEvent);
                params.set('payload', JSON.stringify(payload));
                params.set('ajax', '1');
                const bodyString = params.toString();
                const canUseBeacon = options.useBeacon !== false
                    && typeof navigator !== 'undefined'
                    && typeof navigator.sendBeacon === 'function';
                if (canUseBeacon) {
                    const blob = new Blob([bodyString], { type: 'application/x-www-form-urlencoded' });
                    navigator.sendBeacon(ACTION_TRACK_ENDPOINT, blob);
                } else {
                    fetch(ACTION_TRACK_ENDPOINT, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: bodyString,
                        credentials: 'same-origin',
                        keepalive: options.keepalive === true
                    }).catch(() => { });
                }
            } catch (err) {
                console.warn('Failed to track action', err);
            }
        }

        function normalizeQuoteId(value) {
            if (typeof value === 'number' && Number.isFinite(value) && value > 0) {
                return value;
            }
            const parsed = parseInt(value, 10);
            return Number.isFinite(parsed) && parsed > 0 ? parsed : null;
        }

        function ensureValidQuoteId(rawValue, actionName = 'quote_action') {
            const normalizedId = normalizeQuoteId(rawValue);
            if (!normalizedId) {
                showMessage('رقم العرض غير صالح', 'error');
                trackAction(`${actionName}_invalid`, { quoteId: rawValue });
                return null;
            }
            return normalizedId;
        }

        if (messageCloseBtn) {
            messageCloseBtn.addEventListener('click', hideMessage);
        }
        if (messageContainer) {
            messageContainer.addEventListener('click', function (event) {
                if (event.target === messageContainer && messageContainer.dataset.locked !== '1') {
                    hideMessage();
                }
            });
        }

        if (noteComposerTextareaEl) {
            noteComposerTextareaEl.addEventListener('input', updateNoteComposerCounter);
            updateNoteComposerCounter();
        }
        if (noteComposerEl) {
            window.addEventListener('resize', repositionNoteComposer);
            window.addEventListener('scroll', repositionNoteComposer, true);
        }
        if (modalIframe) {
            modalIframe.addEventListener('load', () => {
                hideModalLoader();
            });
        }

        document.querySelectorAll('.quote-notes').forEach(wrapper => {
            enforceNotesVisibility(wrapper);
            refreshNotesMeta(wrapper);
        });

        // Initialize app
        document.addEventListener('DOMContentLoaded', function () {
            initViewMode();
            setTimeout(() => {
                document.getElementById('pageLoader').style.display = 'none';
            }, 800);
            if (Array.isArray(SERVER_ERROR_LOG_IDS) && SERVER_ERROR_LOG_IDS.length > 0 && typeof showMessage === 'function') {
                const lastLoggedId = SERVER_ERROR_LOG_IDS[SERVER_ERROR_LOG_IDS.length - 1];
                if (lastLoggedId) {
                    showMessage(`حدث خطأ غير متوقع وتم تسجيله. نرجو إرسال رمز الخطأ التالي للدعم الفني: ${lastLoggedId}`, 'error', { persistent: true });
                }
            }
        });
        window.addEventListener('error', function (event) {
            if (!event) return;
            trackAction('js_error', {
                message: event.message || (event.error && event.error.message) || '',
                source: event.filename || '',
                line: event.lineno || null,
                column: event.colno || null
            }, { useBeacon: true });
        });
        window.addEventListener('unhandledrejection', function (event) {
            if (!event) return;
            const reason = event.reason || {};
            trackAction('js_unhandled_rejection', {
                message: reason.message || (typeof reason === 'string' ? reason : ''),
                stack: reason.stack ? reason.stack.slice(0, 300) : ''
            }, { useBeacon: true });
        });
        function getStatusMetaById(statusId) {
            if (statusId === null || statusId === undefined || statusId === '') {
                return null;
            }
            return statusOptionsMap[String(statusId)] || null;
        }
        function getStatusMetaByLabel(label) {
            if (!label) return null;
            for (const [id, meta] of Object.entries(statusOptionsMap)) {
                const metaLabel = meta && typeof meta.label !== 'undefined' ? meta.label : '';
                if (metaLabel === label) {
                    return {
                        id: isNaN(parseInt(id, 10)) ? id : parseInt(id, 10),
                        ...meta
                    };
                }
            }
            return null;
        }
        function isQuotesContractsActionAllowed(statusMeta) {
            if (!quotesContractsRestrictAction) {
                return true;
            }
            if (!statusMeta) {
                return false;
            }
            const statusId = Number(statusMeta.id ?? 0);
            if (statusId > 0 && quotesContractsActionStatuses.includes(statusId)) {
                return true;
            }
            const categoryId = statusMeta.category_id !== undefined ? Number(statusMeta.category_id) : null;
            if (categoryId !== null && quotesContractsActionCategories.includes(categoryId)) {
                return true;
            }
            return false;
        }
        function inferIconForLabel(label) {
            const normalized = (label || '').toString().trim().toLowerCase();
            if (!normalized) return 'fa-minus-circle';
            if (normalized.includes('رفض')) return 'fa-times-circle';
            if (normalized.includes('موافق') || normalized.includes('قبول')) return 'fa-check-circle';
            if (normalized.includes('انتظار')) return 'fa-clock';
            if (normalized.includes('تعليق')) return 'fa-pause-circle';
            return 'fa-circle';
        }
        function normalizeStatusData(status) {
            if (!status) return null;
            return {
                id: status.id ?? null,
                label: status.label || '',
                color: status.color || '',
                text_color: status.text_color || '',
                icon: status.icon || inferIconForLabel(status.label),
                order: status.order ?? null,
                hover_color: status.hover_color || ''
            };
        }
        function setBadgeAppearance(trigger, color, textColor, isNoStatus = false) {
            if (!trigger) return;
            if (color) {
                trigger.style.backgroundColor = color;
                trigger.style.borderColor = color;
                trigger.style.color = textColor || '#ffffff';
                trigger.classList.remove('no-status');
            } else {
                trigger.style.backgroundColor = '';
                trigger.style.borderColor = '';
                trigger.style.color = '';
                trigger.classList.toggle('no-status', Boolean(isNoStatus));
            }
        }
        function getStatusControlsForQuote(quoteId) {
            return Array.from(document.querySelectorAll(`.status-control[data-quote-id="${quoteId}"]`));
        }
        function getPrimaryStatusControl(quoteId) {
            const activeMode = getActiveViewMode();
            const activeWrapper = activeMode === 'cards' ? cardsViewWrapperEl : tableViewWrapperEl;
            if (activeWrapper) {
                const scoped = activeWrapper.querySelector(`.status-control[data-quote-id="${quoteId}"]`);
                if (scoped) {
                    return scoped;
                }
            }
            const allControls = getStatusControlsForQuote(quoteId);
            return allControls.length ? allControls[0] : null;
        }
        function normalizeArabicStatusText(text) {
            if (!text) return '';
            return String(text)
                .trim()
                .toLowerCase()
                .replace(/[\u064B-\u065F\u0670]/g, '')
                .replace(/[أإآ]/g, 'ا')
                .replace(/ى/g, 'ي')
                .replace(/ؤ/g, 'و')
                .replace(/ئ/g, 'ي');
        }
        function isCancelledStatusLabel(label) {
            const normalized = normalizeArabicStatusText(label);
            if (!normalized) return false;
            if (normalized.includes('ملغ')) return true;
            return normalized.includes('cancel');
        }
        let progressStatusesCache = null;
        function getProgressStatuses() {
            if (progressStatusesCache) {
                return progressStatusesCache;
            }
            const list = Object.entries(statusOptionsMap || {})
                .map(([id, meta]) => ({
                    id: parseInt(id, 10),
                    label: (meta && meta.label ? String(meta.label).trim() : ''),
                    order: meta && meta.order !== undefined ? Number(meta.order) : 0
                }))
                .filter(status => status.label && !Number.isNaN(status.id) && !isCancelledStatusLabel(status.label))
                .sort((a, b) => {
                    if (a.order !== b.order) return a.order - b.order;
                    const labelCompare = a.label.localeCompare(b.label, 'ar');
                    if (labelCompare !== 0) return labelCompare;
                    return a.id - b.id;
                });
            progressStatusesCache = list;
            return list;
        }
        function buildStatusTimeline(currentIndex, statusActions = {}) {
            const timelineStatuses = getProgressStatuses();
            if (!timelineStatuses.length) {
                return '<div class="muted-text text-center">لا توجد حالات معرفة.</div>';
            }
            const items = timelineStatuses.map((status, idx) => {
                const label = status.label || '';
                let stateLabel = 'pending';
                let meta = 'قادمة';
                if (currentIndex !== null && currentIndex !== undefined && currentIndex >= 0) {
                    if (idx < currentIndex) {
                        stateLabel = 'completed';
                        meta = 'منتهية';
                    } else if (idx === currentIndex) {
                        stateLabel = 'current';
                        meta = 'جارية';
                    } else if (idx === currentIndex + 1) {
                        stateLabel = 'next';
                        meta = 'قادمة';
                    }
                }
                const actionMeta = status && status.id ? (statusActions[String(status.id)] || statusActions[status.id]) : null;
                const actionUser = actionMeta && actionMeta.user ? String(actionMeta.user).trim() : '';
                const actionTime = actionMeta && actionMeta.time ? String(actionMeta.time).trim() : '';
                const actionHtml = actionUser || actionTime
                    ? `
                        <div class="t-info">
                            ${actionUser ? `<span class="t-user">بواسطة ${escapeHtml(actionUser)}</span>` : ''}
                            ${actionTime ? `<span class="t-time">${escapeHtml(actionTime)}</span>` : ''}
                        </div>
                    `
                    : '';
                return `
                    <div class="timeline-item ${escapeHtml(stateLabel)}">
                        <div class="dot"></div>
                        <div>
                            <p class="t-title">${escapeHtml(label)}</p>
                            <p class="t-meta">${escapeHtml(meta)}</p>
                            ${actionHtml}
                        </div>
                    </div>
                `;
            }).join('');
            return `<div class="timeline">${items}</div>`;
        }
        function getStatusSteps(currentStatusId, currentStatusLabel) {
            const list = getProgressStatuses();
            const normalizedLabel = currentStatusLabel ? String(currentStatusLabel).trim() : '';
            const isCancelled = isCancelledStatusLabel(normalizedLabel);
            if (isCancelled) {
                return {
                    currentLabel: normalizedLabel || 'ملغي',
                    next: null,
                    currentIndex: null,
                    isCancelled: true
                };
            }
            let currentIndex = list.findIndex(status => String(status.id) === String(currentStatusId));
            if (currentIndex < 0 && normalizedLabel) {
                currentIndex = list.findIndex(status => status.label === normalizedLabel);
            }
            const currentLabel = currentIndex >= 0
                ? (list[currentIndex]?.label || normalizedLabel || '')
                : (normalizedLabel || 'لا يوجد حالة');
            const next = currentIndex >= 0 ? (list[currentIndex + 1] || null) : (list[0] || null);

            return { currentLabel, next, currentIndex, isCancelled: false };
        }
        function isTransitionAllowedForUser(currentStatusLabel, targetStatusLabel) {
            if (quotesContractsRestrictAction) {
                const targetMeta = targetStatusLabel ? getStatusMetaByLabel(targetStatusLabel) : null;
                if (!isQuotesContractsActionAllowed(targetMeta)) {
                    return false;
                }
            }
            if (canChangeQuoteStatus) {
                return true;
            }
            if (targetStatusLabel && isCancelledStatusLabel(targetStatusLabel)) {
                return !!canTransitionToCancelled;
            }
            const rules = statusTransitionRules[currentStatusLabel] || [];
            return rules.some(rule => {
                const allowed = rule && rule.allowed;
                const targets = rule && Array.isArray(rule.targets) ? rule.targets : [];
                return allowed && targets.includes(targetStatusLabel);
            });
        }
        function resolveUserNameById(userId) {
            const numericId = parseInt(userId, 10);
            if (!numericId || !Array.isArray(usersData)) {
                return '';
            }
            const user = usersData.find(u => u.id === numericId);
            return user ? (user.<?= $FIELDS['users']['name'] ?> || '') : '';
        }
        function getStatusActionMeta(quoteId, statusId) {
            if (!quoteId || !statusId) return null;
            const byQuote = statusActionsByQuote[String(quoteId)] || statusActionsByQuote[quoteId];
            if (!byQuote) return null;
            return byQuote[String(statusId)] || byQuote[statusId] || null;
        }
        function setStatusActionMeta(quoteId, statusId, meta) {
            if (!quoteId || !statusId || !meta) return;
            const quoteKey = String(quoteId);
            if (!statusActionsByQuote[quoteKey]) {
                statusActionsByQuote[quoteKey] = {};
            }
            statusActionsByQuote[quoteKey][String(statusId)] = meta;
        }
        function buildStatusMetaHtml(actionMeta) {
            if (!actionMeta) return '';
            const userName = actionMeta.user ? String(actionMeta.user).trim() : '';
            const timeText = actionMeta.time ? String(actionMeta.time).trim() : '';
            if (!userName && !timeText) return '';
            const parts = [];
            if (userName) {
                parts.push(`بواسطة ${escapeHtml(userName)}`);
            }
            if (timeText) {
                parts.push(escapeHtml(timeText));
            }
            return `<div class="status-meta">${parts.join(' • ')}</div>`;
        }
        function setSelectedStatusInModal(status) {
            if (!statusSelectedIdInput || !statusSelectedLabelInput) return;
            if (!status || status.id === undefined || status.id === null) {
                statusSelectedIdInput.value = '';
                statusSelectedLabelInput.value = '';
                return;
            }
            statusSelectedIdInput.value = String(status.id);
            statusSelectedLabelInput.value = status.label || '';
        }
        function renderStatusStackModal({ quoteId, currentStatusId, currentStatusLabel }) {
            if (!statusStackContainerEl) return;
            const { currentLabel, next, currentIndex, isCancelled } = getStatusSteps(currentStatusId, currentStatusLabel);
            const canAdvance = !!(next && !isCancelled && isTransitionAllowedForUser(currentLabel, next.label));
            if (canAdvance && next) {
                setSelectedStatusInModal(next);
            } else {
                setSelectedStatusInModal(null);
            }
            const statusActions = statusActionsByQuote[String(quoteId)] || {};
            const timelineHtml = buildStatusTimeline(currentIndex, statusActions);
            const nextLabel = next && next.label ? next.label : 'لا توجد حالة قادمة';
            const actionHtml = next
                ? `
                    <div class="status-modal-actions">
                        <div class="status-next-label">الحالة القادمة: <span>${escapeHtml(nextLabel)}</span></div>
                        <button class="status-action-btn" type="button" onclick="updateQuoteStatus()" ${canAdvance ? '' : 'disabled'}>
                            <i class="fas fa-check"></i>
                            إتمام الحالة التالية
                        </button>
                    </div>
                `
                : `
                    <div class="muted-text text-center" style="margin-top: 8px;">
                        لا توجد حالة قادمة لهذا العرض.
                    </div>
                `;

            const cancelledStatus = Object.values(statusOptionsMap).find(status => isCancelledStatusLabel(status.label));
            const showCancelBtn = !!(canTransitionToCancelled && cancelledStatus && !isCancelled);

            const cancelBtnHtml = showCancelBtn
                ? `
                    <button class="status-cancel-btn" type="button" onclick="cancelQuoteFromModal()">
                        <i class="fas fa-times-circle"></i>
                        إلغاء عرض السعر (تحويل لملغي)
                    </button>
                `
                : '';

            statusStackContainerEl.innerHTML = `
                ${actionHtml}
                ${cancelBtnHtml}
                ${timelineHtml}
            `;
            toggleRejectionReason();
        }
        function cancelQuoteFromModal() {
            const rawQuoteId = document.getElementById('statusQuoteId').value;
            const quoteId = ensureValidQuoteId(rawQuoteId, 'status_cancel');
            if (!quoteId) {
                showMessage('حدث خطأ في تحديد العرض', 'error');
                return;
            }
            const cancelledStatus = Object.values(statusOptionsMap).find(status => isCancelledStatusLabel(status.label));
            if (!cancelledStatus) {
                showMessage('لم يتم العثور على حالة إلغاء معرفة في النظام', 'error');
                return;
            }
            if (!confirm('هل أنت متأكد من رغبتك في إلغاء عرض السعر هذا وتحويل حالته إلى ملغي؟')) {
                return;
            }
            if (statusSelectedIdInput) statusSelectedIdInput.value = cancelledStatus.id;
            if (statusSelectedLabelInput) statusSelectedLabelInput.value = cancelledStatus.label;
            updateQuoteStatus();
        }
        function getSelectedStatusFromModal() {
            const statusIdValue = statusSelectedIdInput ? statusSelectedIdInput.value : '';
            const hasStatus = statusIdValue !== '';
            const statusId = hasStatus ? parseInt(statusIdValue, 10) : null;
            let statusLabel = statusSelectedLabelInput ? statusSelectedLabelInput.value : '';
            if (hasStatus && (!statusLabel || statusLabel.trim() === '')) {
                const statusMeta = getStatusMetaById(statusId);
                if (statusMeta && typeof statusMeta.label !== 'undefined') {
                    statusLabel = statusMeta.label || '';
                }
            }
            return { hasStatus, statusId, statusLabel };
        }
        function truncateText(text, maxLength = 30) {
            if (!text) return '';
            return text.length > maxLength ? text.slice(0, maxLength) + '...' : text;
        }
        function getNotesContainers(quoteId) {
            return Array.from(document.querySelectorAll(`.quote-notes[data-quote-id="${quoteId}"]`));
        }
        function getNotesContainer(quoteId) {
            const activeMode = getActiveViewMode();
            const activeWrapper = activeMode === 'cards' ? cardsViewWrapperEl : tableViewWrapperEl;
            if (activeWrapper) {
                const scoped = activeWrapper.querySelector(`.quote-notes[data-quote-id="${quoteId}"]`);
                if (scoped) {
                    return scoped;
                }
            }
            const fallback = getNotesContainers(quoteId);
            return fallback.length ? fallback[0] : null;
        }
        function getNotesVisibleLimit(wrapper) {
            if (!wrapper) {
                return 2;
            }
            const raw = parseInt(wrapper.dataset.visibleLimit || '2', 10);
            return Number.isFinite(raw) && raw > 0 ? raw : 2;
        }
        function enforceNotesVisibility(wrapper) {
            if (!wrapper) return;
            const stack = wrapper.querySelector('.note-stack');
            if (!stack) return;
            const isExpanded = wrapper.dataset.expanded === '1';
            const visibleLimit = getNotesVisibleLimit(wrapper);
            const cards = Array.from(stack.querySelectorAll('.note-card'));
            cards.forEach((card, index) => {
                if (isExpanded || index < visibleLimit) {
                    card.classList.remove('note-card-hidden');
                } else {
                    card.classList.add('note-card-hidden');
                }
            });
        }
        function refreshNotesMeta(wrapper) {
            if (!wrapper) return;
            const stack = wrapper.querySelector('.note-stack');
            const cards = stack ? Array.from(stack.querySelectorAll('.note-card')) : [];
            const visibleLimit = getNotesVisibleLimit(wrapper);
            const moreBtn = wrapper.querySelector('.notes-more-btn');
            if (moreBtn) {
                if (cards.length > visibleLimit) {
                    moreBtn.classList.remove('hidden');
                    moreBtn.textContent = wrapper.dataset.expanded === '1' ? 'عرض أقل' : 'عرض المزيد';
                } else {
                    moreBtn.classList.add('hidden');
                }
            }
        }
        function toggleNotesVisibility(quoteId) {
            const normalizedId = ensureValidQuoteId(quoteId, 'notes_toggle');
            if (!normalizedId) return;
            const wrappers = getNotesContainers(normalizedId);
            if (!wrappers.length) return;
            const expanded = wrappers.some(wrapper => wrapper.dataset.expanded === '1');
            const nextState = expanded ? '0' : '1';
            wrappers.forEach(wrapper => {
                wrapper.dataset.expanded = nextState;
                enforceNotesVisibility(wrapper);
                refreshNotesMeta(wrapper);
            });
            trackAction('notes_toggle', { quoteId: normalizedId, expanded: nextState === '1' });
        }
        function escapeHtml(value) {
            if (value === null || value === undefined) return '';
            return String(value)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/\"/g, '&quot;')
                .replace(/'/g, '&#039;');
        }
        function formatNoteTextForHtml(text) {
            return escapeHtml(text).replace(/(?:\r\n|\r|\n)/g, '<br>');
        }
        function createNoteCardElement(note) {
            const card = document.createElement('div');
            card.className = 'note-card';
            if (note && note.id !== undefined) {
                card.dataset.noteId = note.id;
            }
            const textDiv = document.createElement('div');
            textDiv.className = 'note-text';
            textDiv.innerHTML = note && note.text ? formatNoteTextForHtml(note.text) : '&mdash;';
            const metaDiv = document.createElement('div');
            metaDiv.className = 'note-meta';
            const authorSpan = document.createElement('span');
            authorSpan.className = 'note-author';
            authorSpan.textContent = note && note.author ? note.author : 'غير معروف';
            metaDiv.appendChild(authorSpan);
            if (note && note.created_at_formatted) {
                const separatorSpan = document.createElement('span');
                separatorSpan.className = 'note-separator';
                separatorSpan.innerHTML = '&bull;';
                metaDiv.appendChild(separatorSpan);
                const dateSpan = document.createElement('span');
                dateSpan.className = 'note-date';
                dateSpan.textContent = note.created_at_formatted;
                metaDiv.appendChild(dateSpan);
            }
            card.appendChild(textDiv);
            card.appendChild(metaDiv);
            return card;
        }
        function appendNoteToStack(quoteId, note) {
            const wrappers = getNotesContainers(quoteId);
            if (!wrappers.length) return;
            wrappers.forEach(wrapper => {
                const stack = wrapper.querySelector('.note-stack');
                if (!stack) return;
                const emptyState = wrapper.querySelector('.notes-empty');
                if (emptyState) {
                    emptyState.remove();
                }
                const card = createNoteCardElement(note || {});
                stack.prepend(card);
                enforceNotesVisibility(wrapper);
                refreshNotesMeta(wrapper);
            });
        }
        function repositionNoteComposer() {
            if (!noteComposerEl || noteComposerEl.classList.contains('hidden')) return;
            const panelWidth = noteComposerEl.offsetWidth || 320;
            const panelHeight = noteComposerEl.offsetHeight || 200;
            let top = window.scrollY + 16;
            let right = 16;
            if (noteComposerAnchorEl) {
                const rect = noteComposerAnchorEl.getBoundingClientRect();
                const preferredTop = rect.bottom + window.scrollY + 10;
                const maxTop = window.scrollY + window.innerHeight - panelHeight - 16;
                top = Math.min(preferredTop, maxTop);
                if (top < window.scrollY + 16) {
                    top = Math.max(window.scrollY + 16, rect.top + window.scrollY - panelHeight - 10);
                }
                if (top < window.scrollY + 16) {
                    top = window.scrollY + 16;
                }
                right = Math.max(16, window.innerWidth - rect.right - 16);
            } else {
                top = window.scrollY + (window.innerHeight - panelHeight) / 2;
            }
            if (top + panelHeight > window.scrollY + window.innerHeight - 16) {
                top = window.scrollY + window.innerHeight - panelHeight - 16;
            }
            if (top < window.scrollY + 16) {
                top = window.scrollY + 16;
            }
            noteComposerEl.style.top = `${Math.round(top)}px`;
            noteComposerEl.style.right = `${Math.round(right)}px`;
            noteComposerEl.style.left = 'auto';
        }
        function openNoteComposer(quoteId, triggerEl) {
            if (!noteComposerEl || !noteComposerTextareaEl) return;
            const normalizedId = ensureValidQuoteId(quoteId, 'note_composer');
            if (!normalizedId) return;
            noteComposerQuoteId = normalizedId;
            noteComposerAnchorEl = triggerEl || null;
            noteComposerSubmitting = false;
            noteComposerEl.dataset.quoteId = String(normalizedId);
            noteComposerEl.setAttribute('aria-modal', 'true');
            noteComposerTextareaEl.value = '';
            updateNoteComposerCounter();
            noteComposerEl.classList.remove('hidden');
            noteComposerEl.classList.remove('active');
            repositionNoteComposer();
            requestAnimationFrame(() => {
                noteComposerEl.classList.add('active');
                setTimeout(() => noteComposerTextareaEl.focus(), 80);
            });
            trackAction('note_composer_open', { quoteId: normalizedId });
        }
        function closeNoteComposer() {
            if (!noteComposerEl || noteComposerEl.classList.contains('hidden')) return;
            noteComposerEl.classList.remove('active');
            noteComposerEl.setAttribute('aria-modal', 'false');
            noteComposerQuoteId = null;
            noteComposerAnchorEl = null;
            noteComposerSubmitting = false;
            noteComposerEl.dataset.quoteId = '';
            if (noteComposerTextareaEl) {
                noteComposerTextareaEl.value = '';
                updateNoteComposerCounter();
            }
            const saveBtn = noteComposerEl.querySelector('.note-composer-save');
            if (saveBtn) {
                saveBtn.disabled = false;
                saveBtn.classList.remove('opacity-70', 'cursor-not-allowed');
            }
            setTimeout(() => {
                if (!noteComposerEl.classList.contains('active')) {
                    noteComposerEl.classList.add('hidden');
                }
            }, 220);
        }
        function updateNoteComposerCounter() {
            if (!noteComposerCounterEl || !noteComposerTextareaEl) return;
            const length = noteComposerTextareaEl.value.length;
            noteComposerCounterEl.textContent = `${length} / ${NOTE_MAX_LENGTH}`;
        }
        function submitNoteComposer() {
            if (!noteComposerEl || !noteComposerTextareaEl) return;
            const quoteId = noteComposerQuoteId || parseInt(noteComposerEl.dataset.quoteId || '0', 10);
            const normalizedId = ensureValidQuoteId(quoteId, 'note_submit');
            if (!normalizedId) {
                return;
            }
            const noteText = noteComposerTextareaEl.value.trim();
            if (!noteText) {
                showMessage('يرجى إدخال نص للملاحظة', 'error');
                noteComposerTextareaEl.focus();
                return;
            }
            if (noteText.length > NOTE_MAX_LENGTH) {
                showMessage('تجاوزت الحد الأقصى لعدد الأحرف', 'error');
                return;
            }
            if (noteComposerSubmitting) {
                return;
            }
            noteComposerSubmitting = true;
            const saveBtn = noteComposerEl.querySelector('.note-composer-save');
            if (saveBtn) {
                saveBtn.disabled = true;
                saveBtn.classList.add('opacity-70', 'cursor-not-allowed');
            }
            const formData = new FormData();
            formData.append('action', 'add_note');
            formData.append('quote_id', normalizedId);
            formData.append('note_text', noteText);
            formData.append('ajax', '1');
            trackAction('note_submit_start', { quoteId: normalizedId, noteLength: noteText.length });

            fetch(window.location.href, {
                method: 'POST',
                body: formData
            })
                .then(response => response.json())
                .then(data => {
                    noteComposerSubmitting = false;
                    if (saveBtn) {
                        saveBtn.disabled = false;
                        saveBtn.classList.remove('opacity-70', 'cursor-not-allowed');
                    }
                    if (data && data.success) {
                        if (data.note) {
                            appendNoteToStack(normalizedId, data.note);
                        }
                        closeNoteComposer();
                        trackAction('note_submit_success', { quoteId: normalizedId });
                        showMessage(data.message || 'تمت إضافة الملاحظة بنجاح', 'success');
                    } else {
                        trackAction('note_submit_failure', { quoteId: normalizedId, message: (data && data.message) || 'تعذر إضافة الملاحظة' });
                        showMessage((data && data.message) || 'تعذر إضافة الملاحظة', 'error');
                    }
                })
                .catch(error => {
                    noteComposerSubmitting = false;
                    if (saveBtn) {
                        saveBtn.disabled = false;
                        saveBtn.classList.remove('opacity-70', 'cursor-not-allowed');
                    }
                    console.error('Failed to add note:', error);
                    trackAction('note_submit_error', { quoteId: normalizedId, message: error.message || String(error) });
                    showMessage('حدث خطأ أثناء إضافة الملاحظة', 'error');
                });
        }
        function selectRowElement(row) {
            if (!row) return;
            if (selectedRowElement && selectedRowElement !== row) {
                selectedRowElement.classList.remove('row-selected');
            }
            selectedRowElement = row;
            row.classList.add('row-selected');
        }
        function clearRowSelection() {
            if (!selectedRowElement) return;
            selectedRowElement.classList.remove('row-selected');
            selectedRowElement = null;
        }
        let activeStatusControl = null;
        function closeAllStatusMenus() {
            document.querySelectorAll('.status-menu.show').forEach(menu => {
                menu.classList.remove('show');
                menu.style.top = '';
                menu.style.left = '';
                menu.style.right = '';
                menu.style.minWidth = '';
                menu.style.visibility = '';
            });
            activeStatusControl = null;
        }
        function positionStatusMenu(control) {
            const menu = control ? control.querySelector('.status-menu') : null;
            const trigger = control ? control.querySelector('.status-trigger') : null;
            if (!menu || !trigger) return;
            const rect = trigger.getBoundingClientRect();
            const desiredMinWidth = Math.max(rect.width + 24, 220);
            menu.style.minWidth = `${desiredMinWidth}px`;
            const menuHeight = menu.offsetHeight || 0;
            const menuWidth = menu.offsetWidth || desiredMinWidth;
            let top = rect.bottom + 8;
            if (top + menuHeight > window.innerHeight - 8) {
                top = rect.top - menuHeight - 8;
                if (top < 8) {
                    top = Math.max(8, window.innerHeight - menuHeight - 8);
                }
            }
            let left = rect.right - menuWidth;
            if (left < 8) left = 8;
            if (left + menuWidth > window.innerWidth - 8) {
                left = Math.max(8, window.innerWidth - menuWidth - 8);
            }
            menu.style.top = `${top}px`;
            menu.style.left = `${left}px`;
            menu.style.right = 'auto';
        }
        function repositionActiveStatusMenu() {
            if (!activeStatusControl) return;
            const menu = activeStatusControl.querySelector('.status-menu');
            if (!menu || !menu.classList.contains('show')) return;
            menu.style.visibility = 'hidden';
            positionStatusMenu(activeStatusControl);
            menu.style.visibility = '';
        }
        function toggleStatusMenu(control) {
            if (!control) return;
            const menu = control.querySelector('.status-menu');
            if (!menu) return;
            const isOpen = menu.classList.contains('show') && activeStatusControl === control;
            if (isOpen) {
                closeAllStatusMenus();
                return;
            }
            menu.querySelectorAll('.status-option').forEach(option => {
                option.style.display = '';
            });
            closeAllStatusMenus();
            activeStatusControl = control;
            menu.style.visibility = 'hidden';
            menu.classList.add('show');
            positionStatusMenu(control);
            menu.style.visibility = '';
        }
        function handleStatusSelection(control, optionElement) {
            if (!control || !optionElement) return;
            const quoteId = parseInt(control.dataset.quoteId, 10);
            if (!quoteId) {
                closeAllStatusMenus();
                return;
            }
            const statusIdValue = optionElement.dataset.statusId;
            const hasStatusId = statusIdValue !== undefined && statusIdValue !== null && statusIdValue !== '';
            const statusId = hasStatusId ? parseInt(statusIdValue, 10) : null;
            const statusLabel = optionElement.dataset.statusLabel || '';
            const reasonContainer = control.parentElement.querySelector('.status-reason');
            const currentReason = reasonContainer ? (reasonContainer.getAttribute('title') || '') : '';
            let rejectionReason = '';
            const requiresRejection = statusLabel && statusLabel.toLowerCase().includes('رفض');
            if (requiresRejection) {
                const userInput = prompt('يرجى إدخال سبب الرفض', currentReason);
                if (userInput === null) {
                    return;
                }
                rejectionReason = userInput.trim();
                if (!rejectionReason) {
                    showMessage('يرجى إدخال سبب الرفض', 'error');
                    return;
                }
            }
            submitStatusUpdate({
                quoteId,
                statusId,
                statusLabel,
                rejectionReason,
                control
            });
        }
        function submitStatusUpdate({ quoteId, statusId, statusLabel, rejectionReason = '', control = null, onSuccess = null }) {
            const formData = new FormData();
            formData.append('action', 'update_status');
            formData.append('quote_id', quoteId);
            if (statusId === null || Number.isNaN(statusId)) {
                formData.append('status_id', '');
            } else {
                formData.append('status_id', statusId);
            }
            formData.append('rejection_reason', rejectionReason);
            formData.append('ajax', '1');
            trackAction('status_update_request', {
                quoteId,
                statusId,
                statusLabel,
                rejectionReasonLength: rejectionReason.length
            });

            fetch(window.location.href, {
                method: 'POST',
                body: formData
            })
                .then(response => response.json())
                .then(data => {
                    trackAction('status_update_response', {
                        quoteId,
                        success: Boolean(data && data.success),
                        statusId,
                        statusLabel,
                        message: data && data.message ? data.message : ''
                    });
                    if (data.success) {
                        let statusData = null;
                        if (data.status) {
                            statusData = normalizeStatusData({
                                id: data.status.id ?? statusId,
                                label: data.status.label ?? statusLabel,
                                color: data.status.color,
                                text_color: data.status.text_color,
                                icon: data.status.icon,
                                order: data.status.order,
                                hover_color: data.status.hover_color
                            });
                        } else if (statusId === null || statusId === undefined || statusId === '') {
                            statusData = null;
                        } else {
                            const existingMeta = getStatusMetaById(statusId) || { label: statusLabel };
                            statusData = normalizeStatusData({ id: statusId, ...existingMeta });
                        }
                        const latestReason = data.rejection_reason || (statusLabel && statusLabel.toLowerCase().includes('رفض') ? rejectionReason : '');
                        const availableControls = getStatusControlsForQuote(quoteId);
                        if (availableControls.length) {
                            applyStatusToAllControls(quoteId, statusData, latestReason);
                            closeAllStatusMenus();
                        } else {
                            window.location.reload();
                        }
                        if (statusId !== null && statusId !== undefined && statusId !== '') {
                            const actionUserId = data.action_user_id || currentUserId;
                            const actionUserName = actionUserId ? (resolveUserNameById(actionUserId) || currentUserName || '') : (currentUserName || '');
                            const actionTimeText = data.action_time_formatted || formatDateTimeWithTimeJs(data.action_time || new Date());
                            if (actionUserName || actionTimeText) {
                                setStatusActionMeta(quoteId, statusId, {
                                    user: actionUserName,
                                    time: actionTimeText,
                                    action: statusLabel || ''
                                });
                            }
                        }
                        if (typeof onSuccess === 'function') {
                            onSuccess(statusData);
                        }
                        showMessage(data.message, 'success');
                    } else {
                        trackAction('status_update_failure', {
                            quoteId,
                            statusId,
                            statusLabel,
                            message: data.message || 'خطأ في التحديث'
                        });
                        showMessage(data.message || 'حدث خطأ في التحديث', 'error');
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    trackAction('status_update_error', { quoteId, statusId, statusLabel, message: error.message || String(error) });
                    showMessage('حدث خطأ في التحديث', 'error');
                });
        }
        function applyStatusToAllControls(quoteId, statusData, rejectionReason) {
            const controls = getStatusControlsForQuote(quoteId);
            if (!controls.length) {
                return;
            }
            controls.forEach(ctrl => applyStatusToControl(ctrl, statusData, rejectionReason));
        }
        function applyStatusToControl(control, statusData, rejectionReason) {
            const trigger = control.querySelector('.status-trigger');
            if (!trigger) return;

            const hasStatus = Boolean(statusData && statusData.label);
            const labelText = hasStatus ? statusData.label : 'لا يوجد حالة';
            const statusIdValue = hasStatus && statusData.id !== undefined && statusData.id !== null ? String(statusData.id) : '';
            const color = hasStatus ? (statusData.color || '') : '';
            const textColor = hasStatus ? (statusData.text_color || '') : '';
            const iconClass = hasStatus ? (statusData.icon || inferIconForLabel(statusData.label)) : 'fa-minus-circle';
            const statusOrder = hasStatus && statusData.order !== undefined && statusData.order !== null ? statusData.order : '';

            if (trigger.dataset) {
                trigger.dataset.statusId = statusIdValue;
                trigger.dataset.statusColor = color;
                trigger.dataset.statusTextColor = textColor;
                trigger.dataset.statusIcon = iconClass;
                trigger.dataset.statusOrder = statusOrder;
                trigger.dataset.statusHoverColor = statusData && statusData.hover_color ? statusData.hover_color : '';
            }
            control.dataset.currentStatusId = statusIdValue;
            control.dataset.currentStatusOrder = statusOrder;

            setBadgeAppearance(trigger, color, textColor, !hasStatus);

            const iconEl = trigger.querySelector('.status-icon');
            if (iconEl) {
                iconEl.className = `status-icon fas ${iconClass}`;
            }
            const labelEl = trigger.querySelector('.status-label-text');
            if (labelEl) {
                labelEl.textContent = labelText;
            }

            const menu = control.querySelector('.status-menu');
            if (menu) {
                menu.querySelectorAll('.status-option').forEach(option => {
                    const optionId = option.dataset.statusId || '';
                    const optionMatches = optionId === '' ? statusIdValue === '' : optionId === statusIdValue;
                    option.classList.toggle('selected', optionMatches);
                });
            }

            updateStatusReason(control, labelText, rejectionReason);
        }
        function updateStatusReason(control, statusLabel, rejectionReason) {
            const reasonContainer = control.parentElement.querySelector('.status-reason');
            if (!reasonContainer) return;

            const normalizedLabel = (statusLabel || '').toString().toLowerCase();
            const needsReason = normalizedLabel.includes('رفض');
            if (needsReason && rejectionReason) {
                const truncated = truncateText(rejectionReason, 30);
                reasonContainer.textContent = truncated;
                reasonContainer.classList.remove('hidden');
                reasonContainer.classList.add('text-red-600');
                reasonContainer.setAttribute('title', rejectionReason);
            } else {
                reasonContainer.textContent = '';
                reasonContainer.classList.add('hidden');
                reasonContainer.classList.remove('text-red-600');
                reasonContainer.removeAttribute('title');
            }
        }
        function findStatusIdByLabel(statusLabel) {
            const meta = getStatusMetaByLabel(statusLabel);
            if (!meta) return null;
            return typeof meta.id === 'number' || /^[0-9]+$/.test(meta.id)
                ? parseInt(meta.id, 10)
                : meta.id;
        }
        document.addEventListener('click', function (event) {
            const trigger = event.target.closest('.status-trigger');
            if (trigger) {
                event.preventDefault();
                const control = trigger.closest('.status-control');
                const quoteId = control ? control.dataset.quoteId : null;
                const reasonContainer = control ? control.parentElement.querySelector('.status-reason') : null;
                const rejectionReason = reasonContainer ? (reasonContainer.getAttribute('title') || '') : '';
                const currentLabel = control ? (control.querySelector('.status-label-text')?.textContent || '').trim() : '';
                openStatusModal(quoteId, currentLabel, rejectionReason);
                return;
            }
            if (!event.target.closest('.status-control')) {
                closeAllStatusMenus();
            }
        });
        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape') {
                closeAllStatusMenus();
            }
        });
        window.addEventListener('resize', repositionActiveStatusMenu);
        window.addEventListener('resize', function () {
            if (viewModeResizeTimeout) {
                clearTimeout(viewModeResizeTimeout);
            }
            viewModeResizeTimeout = setTimeout(handleViewModeResize, 200);
        });
        window.addEventListener('scroll', repositionActiveStatusMenu, true);
        // Status Modal Functions
        function openStatusModal(quoteId, currentStatus, rejectionReason) {
            const normalizedId = ensureValidQuoteId(quoteId, 'status_modal');
            if (!normalizedId) return;
            trackAction('status_modal_open', { quoteId: normalizedId, currentStatus });
            document.getElementById('statusQuoteId').value = normalizedId;
            const control = getPrimaryStatusControl(normalizedId);
            let currentLabel = '';
            let currentId = '';

            if (control) {
                currentLabel = (control.querySelector('.status-label-text')?.textContent || '').trim();
                currentId = control.dataset.currentStatusId || control.querySelector('.status-trigger')?.dataset.statusId || '';
            } else if (currentStatus !== undefined && currentStatus !== null && currentStatus !== '') {
                const numericCandidate = parseInt(currentStatus, 10);
                if (!Number.isNaN(numericCandidate) && String(numericCandidate) === String(currentStatus).trim()) {
                    currentId = String(numericCandidate);
                    const metaById = getStatusMetaById(numericCandidate);
                    currentLabel = metaById && metaById.label ? metaById.label : '';
                } else {
                    currentLabel = String(currentStatus).trim();
                    const metaByLabel = getStatusMetaByLabel(currentLabel);
                    if (metaByLabel && metaByLabel.id !== undefined && metaByLabel.id !== null) {
                        currentId = String(metaByLabel.id);
                    }
                }
            }

            if (statusCurrentLabelEl) {
                statusCurrentLabelEl.textContent = currentLabel ? `الحالة الحالية: ${currentLabel}` : 'الحالة الحالية: لا يوجد حالة';
            }

            const rejectionInput = document.getElementById('rejectionReason');
            if (rejectionInput) {
                rejectionInput.value = rejectionReason || '';
            }

            renderStatusStackModal({
                quoteId: normalizedId,
                currentStatusId: currentId,
                currentStatusLabel: currentLabel
            });
            document.getElementById('statusModal').classList.remove('hidden');
        }
        function closeStatusModal() {
            document.getElementById('statusModal').classList.add('hidden');
        }
        function toggleRejectionReason() {
            const rejectionDiv = document.getElementById('rejectionReasonDiv');
            if (!rejectionDiv) return;
            const { statusLabel } = getSelectedStatusFromModal();
            const normalizedLabel = (statusLabel || '').toLowerCase();

            if (normalizedLabel.includes('رفض')) {
                rejectionDiv.classList.remove('hidden');
            } else {
                rejectionDiv.classList.add('hidden');
                document.getElementById('rejectionReason').value = '';
            }
        }
        function updateQuoteStatus() {
            const rawQuoteId = document.getElementById('statusQuoteId').value;
            const quoteId = ensureValidQuoteId(rawQuoteId, 'status_update');
            const { hasStatus, statusId, statusLabel } = getSelectedStatusFromModal();
            const statusMeta = hasStatus ? getStatusMetaById(statusId) : null;
            const resolvedLabel = hasStatus
                ? ((statusMeta && typeof statusMeta.label !== 'undefined') ? statusMeta.label : statusLabel)
                : '';
            const rejectionReason = document.getElementById('rejectionReason').value.trim();

            if (!quoteId) {
                showMessage('حدث خطأ في تحديد العرض', 'error');
                return;
            }
            if (!hasStatus) {
                showMessage('لا توجد حالة قادمة لهذا العرض', 'error');
                return;
            }
            if (hasStatus && (!statusMeta || !resolvedLabel)) {
                showMessage('يرجى اختيار حالة صحيحة', 'error');
                return;
            }
            const control = getPrimaryStatusControl(quoteId);
            if (!control) {
                showMessage('خطأ في جلب بيانات العرض', 'error');
                return;
            }
            const requiresRejection = resolvedLabel && resolvedLabel.toLowerCase().includes('رفض');
            if (requiresRejection && !rejectionReason) {
                showMessage('يرجى إدخال سبب الرفض', 'error');
                return;
            }
            trackAction('status_update_submit', {
                quoteId,
                statusId,
                statusLabel: hasStatus ? resolvedLabel : 'لا يوجد حالة',
                requiresRejection,
                rejectionReasonLength: rejectionReason.length
            });
            submitStatusUpdate({
                quoteId,
                statusId,
                statusLabel: hasStatus ? resolvedLabel : 'لا يوجد حالة',
                rejectionReason: requiresRejection ? rejectionReason : '',
                control,
                onSuccess: () => closeStatusModal()
            });
        }
        // Status modal interactions are handled via modal option clicks.
        // Filter functions
        function isSortableColumn(type) {
            if (type === 'price' && !canSortByPrice) {
                return false;
            }
            return ['number', 'date', 'price', 'client', 'brand', 'user', 'status'].includes(type);
        }
        function cloneFilters(filters) {
            try {
                return JSON.parse(JSON.stringify(filters || {}));
            } catch (e) {
                return {
                    number: [],
                    date: { from: null, to: null },
                    client: [],
                    brand: [],
                    user: [],
                    status: []
                };
            }
        }
        function buildSortControls(type) {
            if (!isSortableColumn(type)) return '';
            const isCurrent = currentSortBy === type;
            const ascActive = isCurrent && currentSortDir === 'asc';
            const descActive = isCurrent && currentSortDir === 'desc';
            return `
                <div class="mb-4">
                    <div class="text-sm font-semibold text-medium-gray mb-2.5">الترتيب لهذا العامود</div>
                    <div class="grid grid-cols-2 gap-2">
                        <button type="button" class="sort-chip ${ascActive ? 'active' : ''}" onclick="setSortAndApply('${type}', 'asc')">
                            <i class="fas fa-arrow-up"></i>
                            <span>تصاعدي</span>
                        </button>
                        <button type="button" class="sort-chip ${descActive ? 'active' : ''}" onclick="setSortAndApply('${type}', 'desc')">
                            <i class="fas fa-arrow-down"></i>
                            <span>تنازلي</span>
                        </button>
                    </div>
                </div>
            `;
        }
        function submitFiltersForm() {
            if (submittingFilters) return;
            submittingFilters = true;
            const form = document.createElement('form');
            form.method = 'POST';
            form.style.display = 'none';

            const actionInput = document.createElement('input');
            actionInput.name = 'action';
            actionInput.value = 'filter';
            form.appendChild(actionInput);

            const filtersInput = document.createElement('input');
            filtersInput.name = 'filters';
            filtersInput.value = JSON.stringify(activeFilters);
            form.appendChild(filtersInput);

            if (isShowingCancelled) {
                const showCancelledInput = document.createElement('input');
                showCancelledInput.name = 'show_cancelled';
                showCancelledInput.value = '1';
                form.appendChild(showCancelledInput);
            }

            if (currentSortBy) {
                const sortByInput = document.createElement('input');
                sortByInput.name = 'sort_by';
                sortByInput.value = currentSortBy;
                form.appendChild(sortByInput);

                const sortDirInput = document.createElement('input');
                sortDirInput.name = 'sort_dir';
                sortDirInput.value = currentSortDir;
                form.appendChild(sortDirInput);
            }

            document.body.appendChild(form);
            form.submit();
        }
        function setSortAndApply(column, direction) {
            if (!isSortableColumn(column)) {
                return;
            }
            currentSortBy = column || '';
            currentSortDir = direction === 'asc' ? 'asc' : 'desc';
            trackAction('table_sort', { column: currentSortBy, direction: currentSortDir });
            submitFiltersForm();
        }
        async function ensureFilterOptionsLoaded() {
            if (filterOptionsCache) {
                return filterOptionsCache;
            }
            if (filterOptionsLoading) {
                return filterOptionsLoading;
            }
            filterOptionsLoading = (async () => {
                try {
                    const formData = new FormData();
                    formData.append('action', 'filter_options');
                    if (isShowingCancelled) {
                        formData.append('show_cancelled', '1');
                    }
                    formData.append('ajax', '1');
                    const response = await fetch(MQ_LOG_ENDPOINT || window.location.href, {
                        method: 'POST',
                        body: formData,
                        credentials: 'same-origin'
                    });
                    const text = await response.text();
                    let parsed = null;
                    try {
                        parsed = text ? JSON.parse(text) : null;
                    } catch (err) {
                        parsed = null;
                    }
                    if (parsed && parsed.success && parsed.options) {
                        filterOptionsCache = parsed.options;
                    }
                } catch (err) {
                    filterOptionsCache = null;
                } finally {
                    const resolved = filterOptionsCache;
                    filterOptionsLoading = null;
                    return resolved;
                }
            })();
            return filterOptionsLoading;
        }
        async function openFilter(type) {
            draftFilters = cloneFilters(activeFilters);
            currentFilterType = type;
            const filterTitles = {
                number: 'فلتر رقم العرض',
                date: 'فلتر التاريخ',
                client: 'فلتر العميل',
                brand: 'فلتر البراند',
                user: 'فلتر المستخدم',
                status: 'فلتر الحالة'
            };
            document.getElementById('filterTitle').textContent = filterTitles[type];
            const filterBody = document.getElementById('filterBody');
            const sortControls = buildSortControls(type);
            const filterSource = draftFilters && draftFilters[type] !== undefined ? draftFilters : activeFilters;
            document.getElementById('filterModal').classList.remove('hidden');

            if (type === 'date') {
                filterBody.innerHTML = `
                    ${sortControls}
                    <div class="mb-5">
                        <label class="block text-sm font-semibold text-medium-gray mb-2.5">فترة زمنية مخصصة</label>
                        <div class="flex gap-2.5 items-center mb-4">
                            <input type="text" id="dateFrom" placeholder="من تاريخ" readonly
                                   class="flex-1 p-3 border border-border rounded-md text-sm text-center">
                            <span>-</span>
                            <input type="text" id="dateTo" placeholder="إلى تاريخ" readonly
                                   class="flex-1 p-3 border border-border rounded-md text-sm text-center">
                        </div>
                        <div class="grid grid-cols-2 gap-2">
                            <div class="date-option" onclick="setQuickDateFilter('today')">اليوم</div>
                            <div class="date-option" onclick="setQuickDateFilter('yesterday')">أمس</div>
                            <div class="date-option" onclick="setQuickDateFilter('thisWeek')">هذا الأسبوع</div>
                            <div class="date-option" onclick="setQuickDateFilter('lastWeek')">الأسبوع الماضي</div>
                            <div class="date-option" onclick="setQuickDateFilter('thisMonth')">هذا الشهر</div>
                            <div class="date-option" onclick="setQuickDateFilter('lastMonth')">الشهر الماضي</div>
                            <div class="date-option" onclick="setQuickDateFilter('last30Days')">آخر 30 يوم</div>
                            <div class="date-option" onclick="setQuickDateFilter('last90Days')">آخر 90 يوم</div>
                        </div>
                    </div>
                `;
                setTimeout(() => {
                    datePickerFrom = flatpickr("#dateFrom", {
                        locale: "ar",
                        dateFormat: "Y-m-d",
                        defaultDate: filterSource.date?.from,
                        onChange: function (selectedDates, dateStr) {
                            if (!draftFilters) {
                                draftFilters = cloneFilters(activeFilters);
                            }
                            if (!draftFilters.date) {
                                draftFilters.date = { from: null, to: null };
                            }
                            draftFilters.date.from = dateStr;
                        }
                    });
                    datePickerTo = flatpickr("#dateTo", {
                        locale: "ar",
                        dateFormat: "Y-m-d",
                        defaultDate: filterSource.date?.to,
                        onChange: function (selectedDates, dateStr) {
                            if (!draftFilters) {
                                draftFilters = cloneFilters(activeFilters);
                            }
                            if (!draftFilters.date) {
                                draftFilters.date = { from: null, to: null };
                            }
                            draftFilters.date.to = dateStr;
                        }
                    });
                }, 100);
            } else {
                filterBody.innerHTML = `
                    ${sortControls}
                    <div class="text-center text-sm text-medium-gray py-6">جاري تحميل الخيارات...</div>
                `;

                const optionsCache = await ensureFilterOptionsLoaded();
                const availableQuotes = Array.isArray(quotes) ? quotes : [];
                let options = [];

                if (optionsCache && Array.isArray(optionsCache[type]) && optionsCache[type].length) {
                    options = [...optionsCache[type]];
                } else {
                    switch (type) {
                        case 'number':
                            options = [...new Set(availableQuotes.map(quote => convertToEnglishNumbers(String(getQuoteNumberValue(quote) || quote.id))).filter(value => value !== ''))];
                            options.sort((a, b) => {
                                const aNum = parseInt(a, 10);
                                const bNum = parseInt(b, 10);
                                if (!Number.isNaN(aNum) && !Number.isNaN(bNum)) {
                                    return bNum - aNum;
                                }
                                return a.localeCompare(b, undefined, { numeric: true });
                            });
                            break;
                        case 'client':
                            options = [...new Set(availableQuotes.map(quote => {
                                const client = quote.<?= $FIELDS['quotes']['client'] ?>;
                                return client && client.length ? client[0].value || 'غير محدد' : 'غير محدد';
                            }).filter(name => name !== 'غير محدد'))].sort();
                            break;
                        case 'brand':
                            options = [...new Set(availableQuotes.map(quote => {
                                const brand = quote.<?= $FIELDS['quotes']['brand'] ?>;
                                if (brand && brand.length) {
                                    const brandData = brand[0];
                                    if (brandData && brandData.value) {
                                        return typeof brandData.value === 'object' && brandData.value.value ? brandData.value.value : brandData.value;
                                    }
                                }
                                return 'غير محدد';
                            }).filter(name => name !== 'غير محدد'))].sort();
                            break;
                        case 'user':
                            const users = <?= safeJsonForJs($users, '[]') ?>;
                            options = [...new Set(availableQuotes.map(quote => {
                                const userArray = quote.<?= $FIELDS['quotes']['createdBy'] ?>;
                                if (userArray && userArray.length) {
                                    const userId = userArray[0].id;
                                    const user = users.find(u => u.id === userId);
                                    return user ? (user.<?= $FIELDS['users']['name'] ?> || 'غير محدد') : 'غير محدد';
                                }
                                return 'غير محدد';
                            }).filter(name => name !== 'غير محدد'))].sort();
                            break;
                        case 'status':
                            options = <?= safeJsonForJs($statusFilterLabels, '[]') ?>;
                            break;
                    }
                }
                const optionsMarkup = options.map(option => {
                    const source = draftFilters && draftFilters[type] !== undefined ? draftFilters : activeFilters;
                    const sourceValues = Array.isArray(source[type]) ? source[type] : [];
                    const isActive = sourceValues.includes(option);
                    const safeValue = escapeHtml(JSON.stringify(option));
                    const labelText = type === 'number' ? `#${option}` : option;
                    const safeLabel = escapeHtml(labelText);
                    return `
                        <div class="filter-option ${isActive ? 'selected' : ''}" data-value="${safeValue}" onclick="toggleFilterOptionFromElement(event, '${type}')">
                            <input type="checkbox" ${isActive ? 'checked' : ''} class="accent-gold transform scale-110">
                            <label>${safeLabel}</label>
                        </div>
                    `;
                }).join('');
                filterBody.innerHTML = `
                    ${sortControls}
                    <div class="mb-4">
                        <input type="text" placeholder="البحث..." oninput="filterOptions(this.value)"
                               class="w-full p-3 border border-border rounded-md text-sm outline-none transition-all duration-300 focus:border-gold focus:ring-2 focus:ring-gold-light">
                    </div>
                    <div id="filterOptions" class="flex flex-col gap-2">
                        ${optionsMarkup}
                    </div>
                `;
            }
        }
        function closeFilter() {
            document.getElementById('filterModal').classList.add('hidden');
            currentFilterType = null;
            draftFilters = null;
            if (datePickerFrom) {
                datePickerFrom.destroy();
                datePickerFrom = null;
            }
            if (datePickerTo) {
                datePickerTo.destroy();
                datePickerTo = null;
            }
        }
        function toggleFilterOption(evt, value, overrideType = null) {
            const filterType = overrideType || currentFilterType;
            if (!filterType) return;
            if (!draftFilters) {
                draftFilters = cloneFilters(activeFilters);
            }
            if (!draftFilters[filterType]) {
                draftFilters[filterType] = filterType === 'date' ? { from: null, to: null } : [];
            }
            const normalizedValue = typeof value === 'string' ? value : (value !== null && value !== undefined ? String(value) : '');
            const isActive = draftFilters[filterType].includes(normalizedValue);

            if (isActive) {
                draftFilters[filterType] = draftFilters[filterType].filter(item => item !== normalizedValue);
            } else {
                draftFilters[filterType] = [...draftFilters[filterType], normalizedValue];
            }
            const optionEl = evt && (evt.currentTarget || evt.target)
                ? (evt.currentTarget || evt.target).closest('.filter-option')
                : null;
            if (optionEl) {
                const checkbox = optionEl.querySelector('input[type="checkbox"]');
                if (checkbox) {
                    checkbox.checked = !isActive;
                }
                optionEl.classList.toggle('selected', !isActive);
            }
            trackAction('filter_option_toggle', { filterType, value: normalizedValue, active: !isActive });
        }
        function toggleFilterOptionFromElement(evt, filterType) {
            const raw = evt && evt.currentTarget ? evt.currentTarget.dataset.value : '';
            let parsed = raw;
            try {
                parsed = raw ? JSON.parse(raw) : raw;
            } catch (e) {
                parsed = raw;
            }
            toggleFilterOption(evt, parsed, filterType);
        }
        function resetCurrentFilterValues() {
            const filterType = currentFilterType;
            if (!filterType) return;
            if (!draftFilters) {
                draftFilters = cloneFilters(activeFilters);
            }
            if (filterType === 'date') {
                draftFilters.date = { from: null, to: null };
                if (datePickerFrom) datePickerFrom.clear();
                if (datePickerTo) datePickerTo.clear();
            } else {
                draftFilters[filterType] = [];
                document.querySelectorAll('#filterOptions .filter-option').forEach(option => {
                    option.classList.remove('selected');
                    const checkbox = option.querySelector('input[type="checkbox"]');
                    if (checkbox) {
                        checkbox.checked = false;
                    }
                });
            }
        }
        function filterOptions(searchTerm) {
            const options = document.querySelectorAll('#filterOptions .filter-option');
            options.forEach(option => {
                const text = option.textContent.toLowerCase();
                const show = text.includes(searchTerm.toLowerCase());
                option.style.display = show ? 'flex' : 'none';
            });
        }
        function setQuickDateFilter(period) {
            const today = new Date();
            let fromDate, toDate;
            switch (period) {
                case 'today':
                    fromDate = toDate = today;
                    break;
                case 'yesterday':
                    fromDate = toDate = new Date(today.getTime() - 24 * 60 * 60 * 1000);
                    break;
                case 'thisWeek':
                    fromDate = new Date(today.getFullYear(), today.getMonth(), today.getDate() - today.getDay());
                    toDate = today;
                    break;
                case 'lastWeek':
                    fromDate = new Date(today.getFullYear(), today.getMonth(), today.getDate() - today.getDay() - 7);
                    toDate = new Date(today.getFullYear(), today.getMonth(), today.getDate() - today.getDay() - 1);
                    break;
                case 'thisMonth':
                    fromDate = new Date(today.getFullYear(), today.getMonth(), 1);
                    toDate = today;
                    break;
                case 'lastMonth':
                    fromDate = new Date(today.getFullYear(), today.getMonth() - 1, 1);
                    toDate = new Date(today.getFullYear(), today.getMonth(), 0);
                    break;
                case 'last30Days':
                    fromDate = new Date(today.getTime() - 30 * 24 * 60 * 60 * 1000);
                    toDate = today;
                    break;
                case 'last90Days':
                    fromDate = new Date(today.getTime() - 90 * 24 * 60 * 60 * 1000);
                    toDate = today;
                    break;
            }
            if (fromDate && toDate) {
                const fromStr = fromDate.toISOString().split('T')[0];
                const toStr = toDate.toISOString().split('T')[0];

                if (!draftFilters) {
                    draftFilters = cloneFilters(activeFilters);
                }
                if (!draftFilters.date) {
                    draftFilters.date = { from: null, to: null };
                }
                draftFilters.date.from = fromStr;
                draftFilters.date.to = toStr;

                if (datePickerFrom) datePickerFrom.setDate(fromStr);
                if (datePickerTo) datePickerTo.setDate(toStr);
            }
        }
        function applyFilters(commitDraft = false) {
            if (commitDraft && draftFilters) {
                activeFilters = cloneFilters(draftFilters);
            }
            trackAction('filters_apply', {
                filters: activeFilters,
                showCancelled: isShowingCancelled,
                currentFilterType,
                sortBy: currentSortBy || null,
                sortDir: currentSortDir || null
            });
            submitFiltersForm();
        }
        function clearAllFilters() {
            activeFilters = {
                number: [],
                date: { from: null, to: null },
                client: [],
                brand: [],
                user: [],
                status: []
            };
            draftFilters = cloneFilters(activeFilters);
            trackAction('filters_cleared', { showCancelled: isShowingCancelled });

            // Reload page without filters while preserving cancelled toggle
            const params = new URLSearchParams();
            if (isShowingCancelled) {
                params.set('show_cancelled', '1');
            }
            const targetUrl = params.toString()
                ? `${window.location.pathname}?${params.toString()}`
                : window.location.pathname;
            window.location.href = targetUrl;
        }
        function toggleCancelledQuotes() {
            const params = new URLSearchParams(window.location.search);
            if (isShowingCancelled) {
                params.delete('show_cancelled');
            } else {
                params.set('show_cancelled', '1');
            }
            trackAction('toggle_cancelled_quotes', { nextState: !isShowingCancelled });
            const targetUrl = params.toString()
                ? `${window.location.pathname}?${params.toString()}`
                : window.location.pathname;
            window.location.href = targetUrl;
        }
        function setLoadMoreIndicator(visible) {
            if (!loadMoreIndicatorEl) return;
            loadMoreIndicatorEl.classList.toggle('hidden', !visible);
        }
        function ensureCardsGrid() {
            let grid = document.getElementById('quoteCardsGrid');
            if (!grid) {
                const empty = document.getElementById('quotesCardsEmpty');
                if (empty && empty.parentNode) {
                    empty.parentNode.removeChild(empty);
                }
                grid = document.createElement('div');
                grid.id = 'quoteCardsGrid';
                grid.className = 'quote-cards-grid';
                if (cardsViewWrapperEl) {
                    cardsViewWrapperEl.appendChild(grid);
                }
            }
            return grid;
        }
        async function loadMoreQuotes() {
            if (isLoadingMore || !hasMoreQuotes || !nextQuotesCursor) {
                return;
            }
            isLoadingMore = true;
            setLoadMoreIndicator(true);
            try {
                const formData = new FormData();
                formData.append('action', 'load_more');
                formData.append('cursor', JSON.stringify(nextQuotesCursor));
                formData.append('filters', JSON.stringify(activeFilters));
                formData.append('sort_by', currentSortBy || '');
                formData.append('sort_dir', currentSortDir || 'desc');
                formData.append('index_offset', String(Array.isArray(quotes) ? quotes.length : 0));
                if (isShowingCancelled) {
                    formData.append('show_cancelled', '1');
                }
                formData.append('ajax', '1');

                const response = await fetch(MQ_LOG_ENDPOINT || window.location.href, {
                    method: 'POST',
                    body: formData,
                    credentials: 'same-origin'
                });
                const text = await response.text();
                let parsed = null;
                try {
                    parsed = text ? JSON.parse(text) : null;
                } catch (err) {
                    parsed = null;
                }
                if (!parsed || !parsed.success) {
                    hasMoreQuotes = false;
                    return;
                }

                const tableBody = document.getElementById('quotesTableBody');
                if (tableBody && parsed.html_table) {
                    const emptyRow = document.getElementById('quotesEmptyRow');
                    if (emptyRow) {
                        emptyRow.remove();
                    }
                    tableBody.insertAdjacentHTML('beforeend', parsed.html_table);
                }

                const cardsGrid = ensureCardsGrid();
                if (cardsGrid && parsed.html_cards) {
                    cardsGrid.insertAdjacentHTML('beforeend', parsed.html_cards);
                }

                if (Array.isArray(parsed.quotes)) {
                    if (!Array.isArray(quotes)) {
                        quotes = [];
                    }
                    parsed.quotes.forEach(item => quotes.push(item));
                }
                if (quotes.length === 0) {
                    const emptyRow = document.getElementById('quotesEmptyRow');
                    if (emptyRow) {
                        emptyRow.classList.remove('hidden');
                    }
                    const emptyCards = document.getElementById('quotesCardsEmpty');
                    if (emptyCards) {
                        emptyCards.classList.remove('hidden');
                    }
                }
                if (parsed.status_actions && typeof parsed.status_actions === 'object') {
                    Object.keys(parsed.status_actions).forEach(quoteKey => {
                        const actionMap = parsed.status_actions[quoteKey];
                        if (!actionMap || typeof actionMap !== 'object') {
                            return;
                        }
                        if (!statusActionsByQuote[quoteKey]) {
                            statusActionsByQuote[quoteKey] = {};
                        }
                        Object.keys(actionMap).forEach(statusKey => {
                            statusActionsByQuote[quoteKey][statusKey] = actionMap[statusKey];
                        });
                    });
                }

                hasMoreQuotes = !!parsed.has_more;
                nextQuotesCursor = parsed.next_cursor || null;
                if (!nextQuotesCursor) {
                    hasMoreQuotes = false;
                }
            } catch (err) {
                hasMoreQuotes = false;
            } finally {
                isLoadingMore = false;
                setLoadMoreIndicator(false);
                ensureViewportFilled();
            }
        }
        function shouldAutoLoadMore() {
            if (!hasMoreQuotes || isLoadingMore || !nextQuotesCursor) {
                return false;
            }
            const pageHeight = document.documentElement.scrollHeight;
            return pageHeight <= window.innerHeight + 120;
        }
        function ensureViewportFilled() {
            if (shouldAutoLoadMore()) {
                loadMoreQuotes();
            }
        }
        function initInfiniteScroll() {
            if (!loadMoreSentinelEl) {
                return;
            }
            if ('IntersectionObserver' in window) {
                const observer = new IntersectionObserver(entries => {
                    entries.forEach(entry => {
                        if (entry.isIntersecting) {
                            loadMoreQuotes();
                        }
                    });
                }, { rootMargin: '200px' });
                observer.observe(loadMoreSentinelEl);
            } else {
                let scrollTimeout = null;
                window.addEventListener('scroll', () => {
                    if (scrollTimeout) return;
                    scrollTimeout = setTimeout(() => {
                        scrollTimeout = null;
                        const rect = loadMoreSentinelEl.getBoundingClientRect();
                        if (rect.top <= window.innerHeight + 200) {
                            loadMoreQuotes();
                        }
                    }, 150);
                });
            }
        }
        document.addEventListener('DOMContentLoaded', () => {
            initInfiniteScroll();
            ensureViewportFilled();
        });
        // Quote actions
        function createNewQuote() {
            if (!canCreateQuote) {
                trackAction('quote_create_denied', { reason: 'permission' });
                showMessage('لا تملك صلاحية إنشاء عرض سعر جديد.', 'warning', { persistent: false, autoCloseDelay: 4000 });
                return;
            }
            trackAction('quote_create_modal_open');
            const baseUrl = 'https:///system/q/1.php';
            openModal('إنشاء عرض سعر جديد', baseUrl);
        }
        function viewQuote(quoteId) {
            const normalizedId = ensureValidQuoteId(quoteId, 'quote_view');
            if (!normalizedId) {
                return;
            }
            trackAction('quote_view_modal_open', { quoteId: normalizedId });
            openModal(`عرض السعر #${normalizedId}`, `https:///system/q/view.php?quote_id=${normalizedId}`);
        }
        function editQuote(quoteId) {
            const normalizedId = ensureValidQuoteId(quoteId, 'quote_edit');
            if (!normalizedId) {
                return;
            }
            trackAction('quote_edit_modal_open', { quoteId: normalizedId });
            const baseUrl = 'https:///system/q/1.php';
            openModal(`تعديل عرض السعر #${normalizedId}`, `${baseUrl}?quote_id=${normalizedId}`);
        }
        function openPayments(quoteId) {
            const normalizedId = ensureValidQuoteId(quoteId, 'payments_open');
            if (!normalizedId) {
                return;
            }
            if (!canAccessPayments) {
                trackAction('payments_access_denied', { quoteId: normalizedId });
                showMessage('لا تملك صلاحية عرض الدفعات.', 'warning', { persistent: false, autoCloseDelay: 4000 });
                return;
            }
            trackAction('payments_open_modal', { quoteId: normalizedId });
            openModal(`سداد الدفعات #${normalizedId}`, `https:///system/q/payments.php?quote_id=${normalizedId}`);
        }
        function closeMenusForQuote(type, quoteId) {
            document.querySelectorAll(`.hover-menu[data-menu-type="${type}"][data-quote-id="${quoteId}"]`).forEach(menu => {
                menu.classList.remove('show');
            });
        }
        function toggleHoverMenu(type, quoteId, triggerEl = null) {
            const normalizedId = ensureValidQuoteId(quoteId, `${type}_menu_toggle`);
            if (!normalizedId) {
                return;
            }
            const triggerContainer = triggerEl ? triggerEl.closest('.hover-container') : null;
            let menu = triggerContainer ? triggerContainer.querySelector(`.hover-menu[data-menu-type="${type}"]`) : null;
            if (!menu) {
                menu = document.querySelector(`.hover-menu[data-menu-type="${type}"][data-quote-id="${normalizedId}"]`) || document.getElementById(`${type}Menu${normalizedId}`);
            }
            document.querySelectorAll('.hover-menu').forEach(menuEl => {
                if (menuEl !== menu) {
                    menuEl.classList.remove('show');
                }
            });
            if (!menu) {
                trackAction('hover_menu_missing', { type, quoteId: normalizedId });
                return;
            }
            const isOpen = menu.classList.toggle('show');
            trackAction('hover_menu_toggle', { type, quoteId: normalizedId, open: isOpen, viewMode: getActiveViewMode() });
        }
        function viewWordQuote(quoteId) {
            const normalizedId = ensureValidQuoteId(quoteId, 'word_menu_view');
            if (!normalizedId) return;
            if (!canWordQuoteMain) {
                trackAction('word_access_denied', { quoteId: normalizedId, document: 'quote' });
                showMessage('لا تملك صلاحية Word لعرض السعر.', 'warning', { persistent: false, autoCloseDelay: 4000 });
                return;
            }
            checkAndCreateWordFile(normalizedId);
            closeMenusForQuote('word', normalizedId);
        }
        function viewContractQuote(quoteId) {
            const normalizedId = ensureValidQuoteId(quoteId, 'word_contract_menu');
            if (!normalizedId) return;
            if (!canWordSalesContract) {
                trackAction('word_access_denied', { quoteId: normalizedId, document: 'contract' });
                showMessage('لا تملك صلاحية Word لعقد المبيعات.', 'warning', { persistent: false, autoCloseDelay: 4000 });
                return;
            }
            checkAndCreateContractFile(normalizedId);
            closeMenusForQuote('word', normalizedId);
        }
        function viewPDFQuote(quoteId) {
            const normalizedId = ensureValidQuoteId(quoteId, 'pdf_menu_view');
            if (!normalizedId) return;
            downloadPDF(normalizedId);
            closeMenusForQuote('pdf', normalizedId);
        }
        function viewPDFContract(quoteId) {
            const normalizedId = ensureValidQuoteId(quoteId, 'pdf_contract_menu');
            if (!normalizedId) return;
            exportAsContract(normalizedId);
            closeMenusForQuote('pdf', normalizedId);
        }
        function checkAndCreateWordFile(quoteId) {
            const normalizedId = ensureValidQuoteId(quoteId, 'word_editor');
            if (!normalizedId) return;
            if (!canWordQuoteMain) {
                trackAction('word_access_denied', { quoteId: normalizedId, document: 'quote_direct' });
                showMessage('لا تملك صلاحية Word لعرض السعر.', 'warning', { persistent: false, autoCloseDelay: 4000 });
                return;
            }
            trackAction('word_editor_open', { quoteId: normalizedId, document: 'quote' });
            const wordFileUrl = `https:///system/docs/dx.php?file=${normalizedId}&editor=1`;
            openModal(`تعديل Word #${normalizedId}`, wordFileUrl);
        }
        function checkAndCreateContractFile(quoteId) {
            const normalizedId = ensureValidQuoteId(quoteId, 'word_contract');
            if (!normalizedId) return;
            if (!canWordSalesContract) {
                trackAction('word_access_denied', { quoteId: normalizedId, document: 'contract_direct' });
                showMessage('لا تملك صلاحية Word لعقد المبيعات.', 'warning', { persistent: false, autoCloseDelay: 4000 });
                return;
            }
            trackAction('word_editor_open', { quoteId: normalizedId, document: 'contract' });
            const wordFileUrl = `https:///system/docs/dx.php?file=${normalizedId}_contract&editor=1`;
            openModal(`تعديل Word #${normalizedId}`, wordFileUrl);
        }
        function openDeliveryWord(quoteId) {
            const normalizedId = ensureValidQuoteId(quoteId, 'word_delivery');
            if (!normalizedId) return;
            if (!canWordHandover) {
                trackAction('word_access_denied', { quoteId: normalizedId, document: 'delivery' });
                showMessage('لا تملك صلاحية Word لمحضر الاستلام.', 'warning', { persistent: false, autoCloseDelay: 4000 });
                return;
            }
            trackAction('word_editor_open', { quoteId: normalizedId, document: 'delivery' });
            const wordFileUrl = `https:///system/docs/dx.php?file=${normalizedId}_JVF&editor=1`;
            openModal(`محضر استلام (Word) #${normalizedId}`, wordFileUrl);
            closeMenusForQuote('word', normalizedId);
        }
        function openGuaranteeWord(quoteId) {
            const normalizedId = ensureValidQuoteId(quoteId, 'word_guarantee');
            if (!normalizedId) return;
            if (!canWordMaintenanceHandover) {
                trackAction('word_access_denied', { quoteId: normalizedId, document: 'guarantee' });
                showMessage('لا تملك صلاحية Word لمحضر الصيانة.', 'warning', { persistent: false, autoCloseDelay: 4000 });
                return;
            }
            trackAction('word_editor_open', { quoteId: normalizedId, document: 'guarantee' });
            const wordFileUrl = `https:///system/docs/dx.php?file=${normalizedId}_BE9&editor=1`;
            openModal(`محضر صيانة (Word) #${normalizedId}`, wordFileUrl);
            closeMenusForQuote('word', normalizedId);
        }
        async function createNewWordFile(quoteId) {
            if (!canWordQuoteMain) {
                showMessage('لا تملك صلاحية Word لعرض السعر.', 'warning', { persistent: false, autoCloseDelay: 4000 });
                return;
            }
            try {
                const data = await sendTemplateProcessorRequest('generate', quoteId);
                if (data && data.success) {
                    const wordFileUrl = `https:///system/docs/dx.php?file=${quoteId}&editor=1`;
                    openModal(`تعديل Word #${quoteId}`, wordFileUrl);
                } else {
                    await logIssueAndShowMessage(
                        'خطأ في إنشاء المستند' + (data && data.error ? `: ${data.error}` : ''),
                        'template_processor_reported_failure',
                        {
                            action: 'generate',
                            quoteId,
                            payload: data || null
                        }
                    );
                    openModal(`تعديل عرض السعر #${quoteId}`, `https:///system/q/1.php?quote_id=${quoteId}`);
                }
            } catch (error) {
                console.error('Error generating Word file:', error);
                if (error && error.logId) {
                    showMessageWithLogCode(error.message || 'خطأ في إنشاء المستند', error.logId);
                } else {
                    await logIssueAndShowMessage(
                        error && error.message ? error.message : 'خطأ في إنشاء المستند',
                        'generate_word_unhandled_error',
                        { action: 'generate', quoteId, error: error && (error.message || String(error)) }
                    );
                }
                openModal(`تعديل عرض السعر #${quoteId}`, `https:///system/q/1.php?quote_id=${quoteId}`);
            }
        }
        // createGuarantee

        async function createGuarantee(quoteId) {
            const normalizedId = ensureValidQuoteId(quoteId, 'pdf_guarantee');
            if (!normalizedId) return;
            if (!canPdfMaintenanceHandover) {
                trackAction('pdf_access_denied', { quoteId: normalizedId, mode: 'guarantee' });
                showMessage('لا تملك صلاحية PDF لمحضر الصيانة.', 'warning', { persistent: false, autoCloseDelay: 4000 });
                return;
            }
            trackAction('pdf_generate_start', { quoteId: normalizedId, mode: 'guarantee' });
            showMessage('جاري إنشاء ملف PDF...', 'loading', { persistent: true, showSpinner: true });
            try {
                const data = await sendTemplateProcessorRequest('generate_PDF_Guarantee', normalizedId);
                if (data && data.success) {
                    trackAction('pdf_generate_success', { quoteId: normalizedId, mode: 'guarantee', fileUrl: data.file_url || null });
                    showMessage('تم إنشاء ملف PDF بنجاح!', 'success');
                    window.open(data.file_url, '_blank');
                } else {
                    const logId = await logIssueAndShowMessage(
                        'خطأ في إنشاء ملف PDF' + (data && data.error ? `: ${data.error}` : ''),
                        'template_processor_reported_failure',
                        {
                            action: 'generate_PDF_Guarantee',
                            quoteId: normalizedId,
                            payload: data || null
                        }
                    );
                    trackAction('pdf_generate_failure', { quoteId: normalizedId, mode: 'guarantee', payload: data || null, logId });
                }
            } catch (error) {
                console.error('Error generating PDF:', error);
                let logId = error && error.logId ? error.logId : null;
                if (logId) {
                    showMessageWithLogCode(error.message || 'خطأ في إنشاء ملف PDF', logId);
                } else {
                    logId = await logIssueAndShowMessage(
                        error && error.message ? error.message : 'خطأ في إنشاء ملف PDF',
                        'pdf_generate_unhandled_error',
                        { action: 'generate_PDF_Guarantee', quoteId: normalizedId, error: error && (error.message || String(error)) }
                    );
                }
                trackAction('pdf_generate_error', { quoteId: normalizedId, mode: 'guarantee', message: error && error.message || String(error), logId });
            }
        }
        async function createDeliveryFile(quoteId) {
            const normalizedId = ensureValidQuoteId(quoteId, 'pdf_delivery');
            if (!normalizedId) return;
            if (!canPdfHandover) {
                trackAction('pdf_access_denied', { quoteId: normalizedId, mode: 'delivery' });
                showMessage('لا تملك صلاحية PDF لمحضر الاستلام.', 'warning', { persistent: false, autoCloseDelay: 4000 });
                return;
            }
            trackAction('pdf_generate_start', { quoteId: normalizedId, mode: 'delivery' });
            showMessage('جاري إنشاء ملف PDF...', 'loading', { persistent: true, showSpinner: true });
            try {
                const data = await sendTemplateProcessorRequest('generate_PDF_deliver', normalizedId);
                if (data && data.success) {
                    trackAction('pdf_generate_success', { quoteId: normalizedId, mode: 'delivery', fileUrl: data.file_url || null });
                    showMessage('تم إنشاء ملف PDF بنجاح!', 'success');
                    window.open(data.file_url, '_blank');
                } else {
                    const logId = await logIssueAndShowMessage(
                        'خطأ في إنشاء ملف PDF' + (data && data.error ? `: ${data.error}` : ''),
                        'template_processor_reported_failure',
                        {
                            action: 'generate_PDF_deliver',
                            quoteId: normalizedId,
                            payload: data || null
                        }
                    );
                    trackAction('pdf_generate_failure', { quoteId: normalizedId, mode: 'delivery', payload: data || null, logId });
                }
            } catch (error) {
                console.error('Error generating PDF:', error);
                let logId = error && error.logId ? error.logId : null;
                if (logId) {
                    showMessageWithLogCode(error.message || 'خطأ في إنشاء ملف PDF', logId);
                } else {
                    logId = await logIssueAndShowMessage(
                        error && error.message ? error.message : 'خطأ في إنشاء ملف PDF',
                        'pdf_generate_unhandled_error',
                        { action: 'generate_PDF_deliver', quoteId: normalizedId, error: error && (error.message || String(error)) }
                    );
                }
                trackAction('pdf_generate_error', { quoteId: normalizedId, mode: 'delivery', message: error && error.message || String(error), logId });
            }
        }
        function handleDeliveryPdfClick(quoteId) {
            const normalizedId = ensureValidQuoteId(quoteId, 'pdf_delivery_menu');
            if (!normalizedId) return;
            closeMenusForQuote('pdf', normalizedId);
            createDeliveryFile(normalizedId);
        }
        function handleGuaranteePdfClick(quoteId) {
            const normalizedId = ensureValidQuoteId(quoteId, 'pdf_guarantee_menu');
            if (!normalizedId) return;
            closeMenusForQuote('pdf', normalizedId);
            createGuarantee(normalizedId);
        }
        // function createDeliveryFile(quoteId) {
        //     const formData = new FormData();
        //     formData.append('action', 'export_contract');
        //     formData.append('quote_id', quoteId);

        //     fetch('https:///system/docs/template_processor.php', {
        //         method: 'POST',
        //         body: formData
        //     })
        //     .then(response => response.json())
        //     .then(data => {
        //         if (data.success) {
        //             showMessage('تم إنشاء العقد بنجاح!', 'success');
        //             setTimeout(() => {
        //                 const contractFileUrl = `https:///system/docs/dx.php?file=${quoteId}_contract&editor=1`;
        //                 openModal(`عقد Word #${quoteId}`, contractFileUrl);
        //             }, 1000);
        //         } else {
        //             showMessage('خطأ في إنشاء العقد: ' + data.error, 'error');
        //         }
        //     })
        //     .catch(error => {
        //         console.error('Error generating contract file:', error);
        //         showMessage('خطأ في إنشاء العقد: ' + error.message, 'error');
        //     });
        // }


        async function createContractFromTemplate(quoteId) {
            const normalizedId = ensureValidQuoteId(quoteId, 'contract_template');
            if (!normalizedId) return;
            trackAction('contract_template_start', { quoteId: normalizedId });
            showMessage('جاري إنشاء العقد...', 'loading', { persistent: true, showSpinner: true });
            try {
                const data = await sendTemplateProcessorRequest('export_contract', normalizedId);
                if (data && data.success) {
                    trackAction('contract_template_success', { quoteId: normalizedId });
                    showMessage('تم إنشاء العقد بنجاح!', 'success');
                    setTimeout(() => {
                        const contractFileUrl = `https:///system/docs/dx.php?file=${normalizedId}_contract&editor=1`;
                        openModal(`عقد Word #${normalizedId}`, contractFileUrl);
                    }, 1000);
                } else {
                    const logId = await logIssueAndShowMessage(
                        'خطأ في إنشاء العقد' + (data && data.error ? `: ${data.error}` : ''),
                        'template_processor_reported_failure',
                        {
                            action: 'export_contract',
                            quoteId: normalizedId,
                            payload: data || null
                        }
                    );
                    trackAction('contract_template_failure', { quoteId: normalizedId, payload: data || null, logId });
                }
            } catch (error) {
                console.error('Error generating contract file:', error);
                let logId = error && error.logId ? error.logId : null;
                if (logId) {
                    showMessageWithLogCode(error.message || 'خطأ في إنشاء العقد', logId);
                } else {
                    logId = await logIssueAndShowMessage(
                        error && error.message ? error.message : 'خطأ في إنشاء العقد',
                        'contract_generate_unhandled_error',
                        { action: 'export_contract', quoteId: normalizedId, error: error && (error.message || String(error)) }
                    );
                }
                trackAction('contract_template_error', { quoteId: normalizedId, message: error && error.message || String(error), logId });
            }
        }
        function customActionQuote(quoteId) {
            const normalizedId = ensureValidQuoteId(quoteId, 'quote_print');
            if (!normalizedId) return;
            trackAction('quote_print_open', { quoteId: normalizedId });
            openModal(`طباعة عرض السعر #${normalizedId}`, `https:///system/q/6.php?quote_id=${normalizedId}`);
        }
        // Modal functions
        function showModalLoader(text = 'جارٍ التحميل...') {
            if (modalLoader) {
                if (modalLoaderTextEl) {
                    modalLoaderTextEl.textContent = text;
                }
                modalLoader.classList.remove('hidden');
            }
        }
        function hideModalLoader() {
            if (modalLoader) {
                modalLoader.classList.add('hidden');
            }
        }
        function openModal(title, url, options = {}) {
            const { loadingText = 'جارٍ التحميل...' } = options || {};
            document.getElementById('modalTitle').textContent = title;
            if (modalIframe) {
                showModalLoader(loadingText);
                modalIframe.src = url;
            }
            document.getElementById('quoteModal').classList.remove('hidden');
        }
        function closeModal() {
            document.getElementById('quoteModal').classList.add('hidden');
            if (modalIframe) {
                modalIframe.src = 'about:blank';
            }
            hideModalLoader();
            window.location.reload();
        }
        // Utility functions
        function extractFirstScalarValue(value, depth = 0) {
            if (depth > 3) {
                return '';
            }
            if (Array.isArray(value)) {
                for (const item of value) {
                    const resolved = extractFirstScalarValue(item, depth + 1);
                    if (resolved !== '' && resolved !== null && typeof resolved !== 'undefined') {
                        return resolved;
                    }
                }
                return '';
            }
            if (value && typeof value === 'object') {
                if (Object.prototype.hasOwnProperty.call(value, 'value')) {
                    return extractFirstScalarValue(value.value, depth + 1);
                }
                for (const key of Object.keys(value)) {
                    const resolved = extractFirstScalarValue(value[key], depth + 1);
                    if (resolved !== '' && resolved !== null && typeof resolved !== 'undefined') {
                        return resolved;
                    }
                }
                return '';
            }
            if (value === 0) return 0;
            if (typeof value === 'number' || typeof value === 'string') {
                return value;
            }
            return '';
        }
        function getQuoteNumberValue(quote) {
            if (!quote) return '';
            const candidates = [
                quote.<?= $FIELDS['quotes']['quoteNumber'] ?>,
                quote.<?= $FIELDS['quotes']['generated'] ?>,
                quote.id
            ];
            for (const candidate of candidates) {
                const resolved = extractFirstScalarValue(candidate);
                if (resolved !== '' && resolved !== null && typeof resolved !== 'undefined') {
                    return resolved;
                }
            }
            return '';
        }
        function formatDateTimeWithTimeJs(value) {
            if (!value) return '';
            const date = value instanceof Date ? value : new Date(value);
            if (Number.isNaN(date.getTime())) return '';
            const day = String(date.getDate()).padStart(2, '0');
            const month = String(date.getMonth() + 1).padStart(2, '0');
            const year = date.getFullYear();
            const hours24 = date.getHours();
            const period = hours24 >= 12 ? 'PM' : 'AM';
            const periodLabel = period === 'AM' ? 'صباحاً' : 'مساءً';
            const hours12 = ((hours24 + 11) % 12) + 1;
            const hours = String(hours12).padStart(2, '0');
            const minutes = String(date.getMinutes()).padStart(2, '0');
            const formatted = `${day}/${month}/${year} - ${hours}:${minutes} ${period} (${periodLabel})`;
            return convertToEnglishNumbers(formatted);
        }
        function convertToEnglishNumbers(str) {
            if (!str) return '';
            const arabicNumbers = '٠١٢٣٤٥٦٧٨٩';
            const englishNumbers = '0123456789';
            return str.toString().replace(/[٠-٩]/g, char => englishNumbers[arabicNumbers.indexOf(char)]);
        }
        function delay(ms) {
            return new Promise(resolve => setTimeout(resolve, ms));
        }
        function isRetryableTemplateStatus(status) {
            return status && TEMPLATE_RETRYABLE_STATUS.has(status);
        }
        async function reportFileIssue(reason, payload = {}) {
            const result = { logId: null, recorded: false };
            try {
                const formData = new FormData();
                formData.append('action', 'log_issue');
                formData.append('reason', reason);
                formData.append('payload', JSON.stringify(payload));
                formData.append('ajax', '1');
                const response = await fetch(MQ_LOG_ENDPOINT || window.location.href, {
                    method: 'POST',
                    body: formData,
                    credentials: 'same-origin'
                });
                const responseText = await response.text();
                let parsed = null;
                if (responseText) {
                    try {
                        parsed = JSON.parse(responseText);
                    } catch (parseErr) {
                        parsed = null;
                    }
                }
                result.recorded = !!(response && response.ok);
                if (parsed && typeof parsed === 'object') {
                    if (typeof parsed.success !== 'undefined') {
                        result.recorded = !!parsed.success;
                    }
                    result.logId = parsed.log_id || parsed.logId || parsed.id || null;
                }
            } catch (loggingError) {
                console.warn('Failed to log issue', loggingError);
                result.error = loggingError;
            }
            return result;
        }

        function buildSupportErrorMessage(baseMessage, logId = null, loggingFailed = false) {
            const prefix = (baseMessage || 'حدث خطأ غير متوقع').toString().trim() || 'حدث خطأ غير متوقع';
            if (logId) {
                return `${prefix}. تم تسجيل بيانات الخطأ. نرجو إرسال رمز الخطأ التالي للدعم الفني: ${logId}`;
            }
            if (loggingFailed) {
                return `${prefix}. تعذر تسجيل تفاصيل الخطأ، يرجى المحاولة مرة أخرى أو التواصل مع الدعم الفني.`;
            }
            return `${prefix}. تم تسجيل تفاصيل الخطأ، في حال استمرار المشكلة يرجى التواصل مع الدعم الفني.`;
        }

        async function logIssueAndShowMessage(baseMessage, reason, payload = {}, options = {}) {
            const logResult = await reportFileIssue(reason, payload);
            const finalMessage = buildSupportErrorMessage(baseMessage, logResult.logId, logResult.recorded === false);
            showMessage(finalMessage, 'error', Object.assign({ persistent: true }, options.messageOptions || {}));
            return logResult.logId;
        }

        function showMessageWithLogCode(baseMessage, logId, options = {}) {
            const finalMessage = buildSupportErrorMessage(baseMessage, logId, !logId);
            showMessage(finalMessage, 'error', Object.assign({ persistent: true }, options));
        }
        async function sendTemplateProcessorRequest(action, quoteId, extraFields = {}) {
            let lastError = null;
            for (let attempt = 1; attempt <= TEMPLATE_REQUEST_MAX_ATTEMPTS; attempt++) {
                const formData = new FormData();
                formData.append('action', action);
                if (typeof quoteId !== 'undefined' && quoteId !== null) {
                    formData.append('quote_id', quoteId);
                }
                Object.entries(extraFields).forEach(([key, value]) => {
                    if (value !== undefined && value !== null) {
                        formData.append(key, value);
                    }
                });

                let response;
                let responseText = '';
                try {
                    response = await fetch(TEMPLATE_PROCESSOR_ENDPOINT, {
                        method: 'POST',
                        body: formData
                    });
                    responseText = await response.text();
                } catch (networkError) {
                    lastError = { type: 'network', message: networkError.message };
                    if (attempt < TEMPLATE_REQUEST_MAX_ATTEMPTS) {
                        await delay(TEMPLATE_REQUEST_RETRY_DELAY_MS);
                        continue;
                    }
                    const logResult = await reportFileIssue('network_failure', { action, quoteId, message: networkError.message });
                    const error = new Error('تعذر الوصول إلى خادم إنشاء المستندات، حاول مرة أخرى لاحقاً.');
                    if (logResult && logResult.logId) {
                        error.logId = logResult.logId;
                    }
                    throw error;
                }

                if (!response || !response.ok) {
                    const status = response ? response.status : null;
                    lastError = { type: 'http', status };
                    if (attempt < TEMPLATE_REQUEST_MAX_ATTEMPTS && isRetryableTemplateStatus(status)) {
                        await delay(TEMPLATE_REQUEST_RETRY_DELAY_MS);
                        continue;
                    }
                    const logResult = await reportFileIssue('http_error', {
                        action,
                        quoteId,
                        status,
                        preview: responseText ? responseText.slice(0, 400) : ''
                    });
                    const error = new Error('الخدمة مشغولة حالياً، حاول مجدداً بعد قليل.');
                    if (logResult && logResult.logId) {
                        error.logId = logResult.logId;
                    }
                    throw error;
                }

                if (!responseText) {
                    lastError = { type: 'empty_response' };
                    if (attempt < TEMPLATE_REQUEST_MAX_ATTEMPTS) {
                        await delay(TEMPLATE_REQUEST_RETRY_DELAY_MS);
                        continue;
                    }
                    const logResult = await reportFileIssue('empty_response', { action, quoteId });
                    const error = new Error('لم نتلقَّ أي رد من خدمة إنشاء المستندات.');
                    if (logResult && logResult.logId) {
                        error.logId = logResult.logId;
                    }
                    throw error;
                }

                try {
                    return JSON.parse(responseText);
                } catch (parseError) {
                    lastError = { type: 'parse_error', message: parseError.message };
                    const status = response.status;
                    if (attempt < TEMPLATE_REQUEST_MAX_ATTEMPTS && isRetryableTemplateStatus(status)) {
                        await delay(TEMPLATE_REQUEST_RETRY_DELAY_MS);
                        continue;
                    }
                    const logResult = await reportFileIssue('invalid_response_format', {
                        action,
                        quoteId,
                        status,
                        preview: responseText.slice(0, 400)
                    });
                    const error = new Error('استجابة غير صالحة من خدمة إنشاء المستندات.');
                    if (logResult && logResult.logId) {
                        error.logId = logResult.logId;
                    }
                    throw error;
                }
            }

            const logResult = await reportFileIssue('template_processor_unknown_error', {
                action,
                quoteId,
                lastError
            });
            const fallbackError = new Error((lastError && lastError.message) ? lastError.message : 'حدث خطأ غير متوقع في إنشاء الملفات.');
            if (logResult && logResult.logId) {
                fallbackError.logId = logResult.logId;
            }
            throw fallbackError;
        }
        function showMessage(message, type = 'info', options = {}) {
            if (!messageContainer || !messageTextEl) return;
            const { persistent = false, showSpinner = type === 'loading', autoCloseDelay = type === 'error' ? 5000 : 3000 } = options;
            if (messageTimeout) {
                clearTimeout(messageTimeout);
                messageTimeout = null;
            }
            const iconMap = {
                success: 'fa-check-circle',
                error: 'fa-times-circle',
                info: 'fa-info-circle',
                warning: 'fa-exclamation-triangle',
                loading: 'fa-circle-notch'
            };
            messageContainer.dataset.type = type || '';
            messageContainer.dataset.locked = persistent ? '1' : '';
            messageContainer.classList.remove('hidden');
            messageTextEl.textContent = message || '';

            if (messageSpinnerEl) {
                messageSpinnerEl.classList.toggle('hidden', !showSpinner);
            }
            if (messageIconEl) {
                const iconClass = iconMap[type] || iconMap.info;
                messageIconEl.className = `message-icon fas ${iconClass}`;
                messageIconEl.classList.toggle('hidden', showSpinner);
            }
            if (messageCloseBtn) {
                messageCloseBtn.classList.toggle('hidden', persistent && showSpinner);
            }
            if (!persistent) {
                messageTimeout = setTimeout(() => {
                    hideMessage();
                }, autoCloseDelay);
            }
        }
        function hideMessage() {
            if (messageTimeout) {
                clearTimeout(messageTimeout);
                messageTimeout = null;
            }
            if (!messageContainer) return;
            messageContainer.classList.add('hidden');
            messageContainer.dataset.type = '';
            messageContainer.dataset.locked = '';
        }
        function sortTable(column) {
            const newDir = currentSortBy === column && currentSortDir === 'desc' ? 'asc' : 'desc';
            currentSortBy = column;
            currentSortDir = newDir;
            trackAction('table_sort', { column, direction: newDir });
            submitFiltersForm();
        }
        // Event listeners
        document.addEventListener('click', function (e) {
            const clickedRow = e.target.closest('tr.table-row');
            if (clickedRow) {
                selectRowElement(clickedRow);
            } else if (!quotesTableContainerEl || !quotesTableContainerEl.contains(e.target)) {
                clearRowSelection();
            }
            if (e.target === document.getElementById('filterModal')) {
                closeFilter();
            }
            if (e.target === document.getElementById('quoteModal')) {
                closeModal();
            }
            if (e.target === document.getElementById('statusModal')) {
                closeStatusModal();
            }

            if (noteComposerEl && !noteComposerEl.classList.contains('hidden')) {
                if (!noteComposerEl.contains(e.target) && !e.target.closest('.notes-add-btn')) {
                    closeNoteComposer();
                }
            }

            // Close hover menus when clicking outside
            if (!e.target.closest('.hover-container') && !e.target.closest('.status-control')) {
                document.querySelectorAll('.hover-menu').forEach(menu => {
                    menu.classList.remove('show');
                });
            }
        });
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') {
                if (noteComposerEl && !noteComposerEl.classList.contains('hidden')) {
                    closeNoteComposer();
                    return;
                }
                if (messageContainer && !messageContainer.classList.contains('hidden') && messageContainer.dataset.locked !== '1') {
                    hideMessage();
                    return;
                }
                if (!document.getElementById('quoteModal').classList.contains('hidden')) {
                    closeModal();
                } else if (!document.getElementById('filterModal').classList.contains('hidden')) {
                    closeFilter();
                } else if (!document.getElementById('statusModal').classList.contains('hidden')) {
                    closeStatusModal();
                }
            }
        });
        async function downloadPDF(quoteId) {
            const normalizedId = ensureValidQuoteId(quoteId, 'pdf_generate');
            if (!normalizedId) return;
            closeMenusForQuote('pdf', normalizedId);
            trackAction('pdf_generate_start', { quoteId: normalizedId, mode: 'view' });
            showMessage('جاري إنشاء ملف PDF...', 'loading', { persistent: true, showSpinner: true });
            try {
                const data = await sendTemplateProcessorRequest('generate_pdf', normalizedId);
                if (data && data.success) {
                    trackAction('pdf_generate_success', { quoteId: normalizedId, fileUrl: data.file_url || null });
                    showMessage('تم إنشاء ملف PDF بنجاح!', 'success');
                    openModal(`عرض PDF #${normalizedId}`, data.file_url, { loadingText: 'جارٍ تحميل ملف PDF...' });
                } else {
                    const logId = await logIssueAndShowMessage(
                        'خطأ في إنشاء ملف PDF' + (data && data.error ? `: ${data.error}` : ''),
                        'template_processor_reported_failure',
                        {
                            action: 'generate_pdf',
                            quoteId: normalizedId,
                            payload: data || null
                        }
                    );
                    trackAction('pdf_generate_failure', { quoteId: normalizedId, payload: data || null, logId });
                }
            } catch (error) {
                console.error('Error generating PDF:', error);
                let logId = error && error.logId ? error.logId : null;
                if (logId) {
                    showMessageWithLogCode(error.message || 'خطأ في إنشاء ملف PDF', logId);
                } else {
                    logId = await logIssueAndShowMessage(
                        error && error.message ? error.message : 'خطأ في إنشاء ملف PDF',
                        'pdf_generate_unhandled_error',
                        { action: 'generate_pdf', quoteId: normalizedId, error: error && (error.message || String(error)) }
                    );
                }
                trackAction('pdf_generate_error', { quoteId: normalizedId, message: error && error.message || String(error), logId });
            }
        }
        async function regenerateBaseDocuments(quoteId) {
            const normalizedId = ensureValidQuoteId(quoteId, 'documents_regenerate_fallback');
            if (!normalizedId) {
                return false;
            }
            try {
                const wordResult = await sendTemplateProcessorRequest('generate', normalizedId);
                if (!wordResult || !wordResult.success) {
                    trackAction('documents_regenerate_fallback_word_failed', { quoteId: normalizedId, payload: wordResult || null });
                    return false;
                }
                const pdfResult = await sendTemplateProcessorRequest('generate_pdf', normalizedId);
                if (!pdfResult || !pdfResult.success) {
                    trackAction('documents_regenerate_fallback_pdf_failed', { quoteId: normalizedId, payload: pdfResult || null });
                    return false;
                }
                return true;
            } catch (error) {
                trackAction('documents_regenerate_fallback_error', { quoteId: normalizedId, message: error && error.message || String(error) });
                return false;
            }
        }
        async function refreshPDF(quoteId) {
            const normalizedId = ensureValidQuoteId(quoteId, 'documents_regenerate');
            if (!normalizedId) return;
            const targetRow = document.querySelector(`tr[data-quote-id="${normalizedId}"]`);
            if (targetRow) {
                targetRow.classList.add('row-processing');
            }
            trackAction('documents_regenerate_start', { quoteId: normalizedId });
            showMessage('جاري إنشاء ملفات جديدة...', 'loading', { persistent: true, showSpinner: true });
            const attemptFallback = async (reason) => {
                trackAction('documents_regenerate_fallback_start', { quoteId: normalizedId, reason });
                const fallbackOk = await regenerateBaseDocuments(normalizedId);
                if (fallbackOk) {
                    trackAction('documents_regenerate_fallback_success', { quoteId: normalizedId });
                    showMessage('تم إنشاء ملفات Word وPDF عبر المسار البديل. أعد المحاولة لاحقاً لتحديث العقد إذا لزم الأمر.', 'success');
                } else {
                    trackAction('documents_regenerate_fallback_failure', { quoteId: normalizedId, reason });
                }
                return fallbackOk;
            };
            try {
                const data = await sendTemplateProcessorRequest('Refresh_pdf_word', normalizedId);
                if (data && data.success) {
                    trackAction('documents_regenerate_success', { quoteId: normalizedId });
                    showMessage('تم إنشاء ملفات Word & PDF بنجاح!', 'success');
                } else {
                    const fallbackSucceeded = await attemptFallback('primary_response_failed');
                    if (!fallbackSucceeded) {
                        const logId = await logIssueAndShowMessage(
                            'خطأ في إنشاء الملفات' + (data && data.error ? `: ${data.error}` : ''),
                            'template_processor_reported_failure',
                            {
                                action: 'Refresh_pdf_word',
                                quoteId: normalizedId,
                                payload: data || null
                            }
                        );
                        trackAction('documents_regenerate_failure', { quoteId: normalizedId, payload: data || null, logId });
                    }
                }
            } catch (error) {
                console.error('Error generating files:', error);
                let logId = error && error.logId ? error.logId : null;
                const fallbackSucceeded = await attemptFallback(error && error.message ? error.message : 'exception');
                if (!fallbackSucceeded) {
                    if (logId) {
                        showMessageWithLogCode(error.message || 'خطأ في إنشاء الملفات', logId);
                    } else {
                        logId = await logIssueAndShowMessage(
                            error && error.message ? error.message : 'خطأ في إنشاء الملفات',
                            'documents_regenerate_unhandled_error',
                            { action: 'Refresh_pdf_word', quoteId: normalizedId, error: error && (error.message || String(error)) }
                        );
                    }
                    trackAction('documents_regenerate_error', { quoteId: normalizedId, message: error && error.message || String(error), logId });
                }
            } finally {
                if (targetRow) {
                    targetRow.classList.remove('row-processing');
                }
            }
        }
        function exportAsContract(quoteId) {
            const normalizedId = ensureValidQuoteId(quoteId, 'contract_export');
            if (!normalizedId) return;
            closeMenusForQuote('pdf', normalizedId);
            const targetUrl = `https:///system/q/3.php?quote_id=${normalizedId}&contract_flow=1`;
            trackAction('contract_export', { quoteId: normalizedId, mode: 'contract_flow_3_to_7' });
            openModal(`تصدير كعقد #${normalizedId}`, targetUrl);
            closeMenusForQuote('pdf', normalizedId);
        }
    </script>
</body>

</html>