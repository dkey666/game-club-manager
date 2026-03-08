<?php
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('X-XSS-Protection: 1; mode=block');
header('Referrer-Policy: strict-origin-when-cross-origin');

// CORS для Telegram WebApp
$allowedOrigins = ['https://web.telegram.org', 'https://telegram.org'];
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (in_array($origin, $allowedOrigins) || strpos($origin, '.t.me') !== false) {
    header("Access-Control-Allow-Origin: $origin");
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, X-Telegram-Init-Data, X-CSRF-Token');
    header('Access-Control-Allow-Credentials: true');
}

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}
// Включаем APCu для кеширования (если доступен)
if (!function_exists('apcu_fetch')) {
    function apcu_fetch($key, &$success = null) {
        $success = false;
        return false;
    }
    function apcu_store($key, $var, $ttl = 0) {
        return false;
    }
}

// Инвалидация кеша при изменении данных
function invalidateUserCache($userId) {
    apcu_delete("user_{$userId}");
    apcu_delete("points_{$userId}");
}

function invalidateTasksCache() {
    apcu_delete('all_tasks');
}

function invalidateLeaderboardCache() {
    for ($i = 0; $i < 20; $i += 5) {
        apcu_delete("leaderboard_{$i}_5");
    }
}

// IP validation
$ip = $_SERVER['REMOTE_ADDR'] ?? '';
if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false && 
    !in_array($ip, ['127.0.0.1', '::1'])) {
    http_response_code(403);
    exit(json_encode(['error' => 'Invalid IP']));
}
session_start();

function generateCSRFToken() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function validateCSRFToken($token) {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}
ini_set('display_errors', 0);
error_reporting(0);

require_once __DIR__ . '/config.php';
date_default_timezone_set(Config::get('TIMEZONE', 'Asia/Tashkent'));
ini_set('display_errors', 1);
error_reporting(E_ALL);

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

function loadMailerDependencies() {
    static $loaded = false;

    if ($loaded) {
        return;
    }

    $composerAutoload = __DIR__ . '/vendor/autoload.php';
    if (file_exists($composerAutoload)) {
        require_once $composerAutoload;
        $loaded = true;
        return;
    }

    $legacyMailer = __DIR__ . '/phpmailer/src/PHPMailer.php';
    if (file_exists($legacyMailer)) {
        require_once __DIR__ . '/phpmailer/src/Exception.php';
        require_once __DIR__ . '/phpmailer/src/PHPMailer.php';
        require_once __DIR__ . '/phpmailer/src/SMTP.php';
        $loaded = true;
        return;
    }

    throw new RuntimeException('PHPMailer is not installed. Run "composer install" before using email features.');
}

function sanitizeOutput($data) {
    if (is_null($data)) {
        return null;
    }
    if (is_array($data)) {
        return array_map('sanitizeOutput', $data);
    }
    if (is_string($data)) {
        return htmlspecialchars($data, ENT_QUOTES, 'UTF-8');
    }
    return $data;
}

function getValidatedInput() {
    $input = file_get_contents('php://input');
    
    if (strlen($input) > 10240) {
        http_response_code(413);
        exit(json_encode(['error' => 'Payload too large']));
    }
    
    if (empty($input)) {
        http_response_code(400);
        exit(json_encode(['error' => 'Empty request']));
    }
    
    $data = json_decode($input, true);
    
    if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) {
        http_response_code(400);
        exit(json_encode(['error' => 'Invalid JSON']));
    }
    
    array_walk_recursive($data, function(&$value) {
        if (is_string($value)) {
            $value = trim($value);
            if (preg_match('/<script|javascript:|on\w+=/i', $value)) {
                http_response_code(400);
                exit(json_encode(['error' => 'XSS detected']));
            }
        }
    });
    
    return $data;
}

function logSuspiciousActivity($userId, $action, $details = '') {
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
    $logEntry = date('Y-m-d H:i:s') . " | User: $userId | IP: $ip | Action: $action | Details: $details | UA: $userAgent\n";
    
    // Ограничиваем размер лог-файла (макс 1MB)
    $logFile = 'security.log';
    if (file_exists($logFile) && filesize($logFile) > 1048576) {
        rename($logFile, 'security_' . date('Y-m-d') . '.log');
    }
    
    file_put_contents($logFile, $logEntry, FILE_APPEND);
}

function sendVerificationEmail($email, $code) {
    loadMailerDependencies();

    $mail = new PHPMailer(true);
    $config = Config::getEmailConfig();
    
    try {
        $mail->isSMTP();
        $mail->SMTPDebug = 0;
        $mail->Host = $config['host'];
        $mail->SMTPAuth = true;
        $mail->Username = $config['username'];
        $mail->Password = $config['password'];
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        $mail->Port = $config['port'];
        $mail->CharSet = 'UTF-8';
        
        $mail->setFrom($config['from'], $config['from_name']);
        $mail->addAddress($email);
        
        $mail->Subject = 'Verification code - ' . APP_SHORT_NAME;
        $mail->isHTML(true);
        $safeCode = htmlspecialchars($code, ENT_QUOTES, 'UTF-8');
        $mail->Body = "
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset='UTF-8'>
            <meta name='viewport' content='width=device-width, initial-scale=1.0'>
        </head>
        <body style='margin:0;padding:0;background:linear-gradient(135deg,#667eea 0%,#764ba2 100%);font-family:-apple-system,BlinkMacSystemFont,\"Segoe UI\",Arial,sans-serif'>
            <table width='100%' cellpadding='0' cellspacing='0' style='min-height:100vh;padding:40px 20px'>
                <tr>
                    <td align='center'>
                        <table width='600' cellpadding='0' cellspacing='0' style='background:#fff;border-radius:20px;overflow:hidden;box-shadow:0 20px 60px rgba(0,0,0,0.3)'>
                            <!-- Header -->
                            <tr>
                                <td style='background:linear-gradient(135deg,#667eea,#764ba2);padding:40px;text-align:center'>
                                    <h1 style='margin:0;color:#fff;font-size:32px;font-weight:700;text-shadow:0 2px 10px rgba(0,0,0,0.2)'>🎮 " . htmlspecialchars(APP_SHORT_NAME, ENT_QUOTES, 'UTF-8') . "</h1>
                                    <p style='margin:10px 0 0;color:rgba(255,255,255,0.9);font-size:16px'>Game Club Platform</p>
                                </td>
                            </tr>
                            <!-- Content -->
                            <tr>
                                <td style='padding:50px 40px;text-align:center'>
                                    <h2 style='margin:0 0 20px;color:#2d3748;font-size:24px;font-weight:600'>Подтверждение Email</h2>
                                    <p style='margin:0 0 30px;color:#718096;font-size:16px;line-height:1.6'>Используйте код ниже для подтверждения вашего email адреса</p>
                                    
                                    <!-- Code Box -->
                                    <div style='background:linear-gradient(135deg,#667eea,#764ba2);border-radius:16px;padding:30px;margin:0 0 30px;box-shadow:0 10px 30px rgba(102,126,234,0.3)'>
                                        <div style='font-size:48px;font-weight:700;color:#fff;letter-spacing:12px;text-shadow:0 2px 10px rgba(0,0,0,0.2)'>$safeCode</div>
                                    </div>
                                    
                                    <!-- Timer -->
                                    <div style='background:#fef3c7;border-left:4px solid #f59e0b;padding:16px;border-radius:8px;margin:0 0 20px'>
                                        <p style='margin:0;color:#92400e;font-size:14px;font-weight:600'>⏱️ Код действителен 2 минуты</p>
                                    </div>
                                    
                                    <p style='margin:0;color:#718096;font-size:14px;line-height:1.6'>После подтверждения вы получите <strong style='color:#667eea'>+500 сум</strong> бонусом!</p>
                                </td>
                            </tr>
                            <!-- Footer -->
                            <tr>
                                <td style='background:#f7fafc;padding:30px;text-align:center;border-top:1px solid #e2e8f0'>
                                    <p style='margin:0 0 10px;color:#a0aec0;font-size:13px'>Если вы не запрашивали этот код, просто проигнорируйте это письмо</p>
                                    <p style='margin:0;color:#cbd5e0;font-size:12px'>© 2026 " . htmlspecialchars(APP_NAME, ENT_QUOTES, 'UTF-8') . ".</p>
                                </td>
                            </tr>
                        </table>
                    </td>
                </tr>
            </table>
        </body>
        </html>
        ";
        
        $result = $mail->send();
        file_put_contents('email_send.log', date('H:i:s') . " - $email: " . ($result ? 'OK' : 'FAIL') . "\n", FILE_APPEND);
        return $result;
    } catch (Exception $e) {
        file_put_contents('email_send.log', date('H:i:s') . " - ERROR: {$e->getMessage()}\n", FILE_APPEND);
        return false;
    }
}

function generateVerificationCode() {
    return str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
}

$ADMINS = Config::getAdmins();

// ДОБАВЛЯЕМ функцию валидации Telegram Web App
function validateTelegramWebApp($initData, $botToken, $maxAge = 86400) {
    if (empty($initData)) {
        return false;
    }
    
    try {
        parse_str($initData, $data);
        
        if (!isset($data['hash'])) {
            return false;
        }
        
        // Проверка времени жизни данных (по умолчанию 24 часа)
        if (isset($data['auth_date'])) {
            $authTime = intval($data['auth_date']);
            if (time() - $authTime > $maxAge) {
                return false;
            }
        }
        
        $hash = $data['hash'];
        unset($data['hash']);
        
        ksort($data);
        $dataCheckString = '';
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $value = json_encode($value);
            }
            $dataCheckString .= $key . '=' . $value . "\n";
        }
        $dataCheckString = rtrim($dataCheckString, "\n");
        
        $secretKey = hash_hmac('sha256', $botToken, 'WebAppData', true);
        $calculatedHash = hash_hmac('sha256', $dataCheckString, $secretKey);
        
        return hash_equals($calculatedHash, $hash);
    } catch (Exception $e) {
        return false;
    }
}

// Инициализация БД
function initDB() {
    $dbName = Config::get('DB_NAME', 'club.db');
    
    if (!preg_match('/^[a-zA-Z0-9_.-]+\.db$/', $dbName)) {
        throw new Exception('Invalid database name');
    }
    
    $db = new SQLite3($dbName, SQLITE3_OPEN_READWRITE | SQLITE3_OPEN_CREATE);
    $db->busyTimeout(5000);
    $db->exec('PRAGMA journal_mode=WAL');
    $db->exec('PRAGMA foreign_keys=ON');
    
    // Таблица пользователей
    $db->exec("CREATE TABLE IF NOT EXISTS users (
        telegram_id INTEGER PRIMARY KEY,
        username TEXT,
        first_name TEXT,
        name TEXT,
        phone TEXT,
        points INTEGER DEFAULT 0,
        points_registered BOOLEAN DEFAULT 0,
        ip_address TEXT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
    
    // Добавьте в функцию initDB() новую таблицу:
     $db->exec("CREATE TABLE IF NOT EXISTS user_notifications (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL,
        type TEXT NOT NULL,
        message TEXT NOT NULL,
        data TEXT,
        delivered INTEGER DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");
    
    $db->exec("CREATE TABLE IF NOT EXISTS email_verifications (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL,
        email TEXT NOT NULL,
        code TEXT NOT NULL,
        verified INTEGER DEFAULT 0,
        expires_at DATETIME NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        UNIQUE(user_id)
    )");
    
    $db->exec("CREATE TABLE IF NOT EXISTS booking_messages (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        booking_id INTEGER NOT NULL,
        chat_id INTEGER NOT NULL,
        message_id INTEGER NOT NULL,
        status_text TEXT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
    
    try {
        @$db->exec("ALTER TABLE booking_messages ADD COLUMN status_text TEXT");
    } catch (Exception $e) {}
    
    // Добавляем поля email в users
    try {
        @$db->exec("ALTER TABLE users ADD COLUMN email TEXT");
    } catch (Exception $e) {}
    
    try {
        @$db->exec("ALTER TABLE users ADD COLUMN email_verified INTEGER DEFAULT 0");
    } catch (Exception $e) {}
    
    try {
        @$db->exec("ALTER TABLE users ADD COLUMN email TEXT");
    } catch (Exception $e) {}
    
    try {
        @$db->exec("ALTER TABLE users ADD COLUMN email_verified INTEGER DEFAULT 0");
    } catch (Exception $e) {}
    
    // НОВОЕ: Добавляем поля для Google аккаунта
    try {
        @$db->exec("ALTER TABLE users ADD COLUMN google_id TEXT");
    } catch (Exception $e) {}
    
    try {
        @$db->exec("ALTER TABLE users ADD COLUMN google_email TEXT");
    } catch (Exception $e) {}
    
    try {
        @$db->exec("ALTER TABLE users ADD COLUMN google_name TEXT");
    } catch (Exception $e) {}
    
    try {
        @$db->exec("ALTER TABLE users ADD COLUMN google_connected INTEGER DEFAULT 0");
    } catch (Exception $e) {}
    
    $db->exec("INSERT OR IGNORE INTO admin_limits (id, monthly_spent, last_reset_month) 
               VALUES (1, 0, strftime('%Y-%m', 'now'))");
    
    // Таблица компьютеров
    $db->exec("CREATE TABLE IF NOT EXISTS computers (
        id INTEGER PRIMARY KEY,
        status TEXT DEFAULT 'free'
    )");
    
    // Таблица заданий
    $db->exec("CREATE TABLE IF NOT EXISTS tasks (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        title TEXT NOT NULL,
        description TEXT,
        link TEXT,
        points INTEGER NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
    
    // Таблица бронирований
    $db->exec("CREATE TABLE IF NOT EXISTS bookings (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER,
        computer_id INTEGER,
        user_name TEXT,
        user_phone TEXT,
        status TEXT DEFAULT 'pending',
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
    
    // Добавляем колонки если таблица уже существует
    try {
        @$db->exec("ALTER TABLE bookings ADD COLUMN status TEXT DEFAULT 'pending'");
    } catch (Exception $e) {}
    
    // Таблица транзакций баллов
    $db->exec("CREATE TABLE IF NOT EXISTS points_transactions (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER,
        amount INTEGER,
        action TEXT,
        reason TEXT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
    
    // Таблица выполненных заданий
    $db->exec("CREATE TABLE IF NOT EXISTS user_tasks (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER,
        task_id INTEGER,
        completed_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        UNIQUE(user_id, task_id)
    )");
    
    // Таблица кликов по ссылкам заданий
    $db->exec("CREATE TABLE IF NOT EXISTS task_clicks (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER,
        task_id INTEGER,
        clicked_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        UNIQUE(user_id, task_id)
    )");
    
    // Таблица рефералов
    $db->exec("CREATE TABLE IF NOT EXISTS referrals (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        referrer_id INTEGER,
        referred_id INTEGER,
        reward INTEGER DEFAULT 0,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        UNIQUE(referrer_id, referred_id)
    )");
    
    // Таблица кликов
    $db->exec("CREATE TABLE IF NOT EXISTS user_clicks (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER,
        clicks_today INTEGER DEFAULT 0,
        earned_today INTEGER DEFAULT 0,
        last_reset DATE,
        UNIQUE(user_id)
    )");
    $db->exec("CREATE TABLE IF NOT EXISTS admin_limits (
        id INTEGER PRIMARY KEY,
        monthly_spent INTEGER DEFAULT 0,
        last_reset_month TEXT
    )");
    
    // Создаем компьютеры если не существуют
    for ($i = 1; $i <= 30; $i++) {
        $db->exec("INSERT OR IGNORE INTO computers (id, status) VALUES ($i, 'free')");
    }
    
    return $db;
}


// Отправка в Telegram
function sendTelegramMessage($chatId, $message, $keyboard = null) {
    $url = "https://api.telegram.org/bot" . BOT_TOKEN . "/sendMessage";
    $data = [
        'chat_id' => $chatId,
        'text' => $message,
        'parse_mode' => 'HTML'
    ];
    if ($keyboard) $data['reply_markup'] = json_encode($keyboard);
    
    $options = [
        'http' => [
            'method' => 'POST',
            'header' => 'Content-Type: application/x-www-form-urlencoded',
            'content' => http_build_query($data)
        ]
    ];
    
    $response = @file_get_contents($url, false, stream_context_create($options));
    $result = json_decode($response, true);
    
    return $result['result']['message_id'] ?? null;
}

function deleteTelegramMessage($chatId, $messageId) {
    $url = "https://api.telegram.org/bot" . BOT_TOKEN . "/deleteMessage";
    $data = ['chat_id' => $chatId, 'message_id' => $messageId];
    
    $options = [
        'http' => [
            'method' => 'POST',
            'header' => 'Content-Type: application/x-www-form-urlencoded',
            'content' => http_build_query($data)
        ]
    ];
    
    @file_get_contents($url, false, stream_context_create($options));
}

function sendNotification($userId, $message) {
    $url = "https://api.telegram.org/bot" . BOT_TOKEN . "/sendMessage";
    $data = [
        'chat_id' => $userId,
        'text' => $message
    ];
    
    $options = [
        'http' => [
            'method' => 'POST',
            'header' => 'Content-Type: application/x-www-form-urlencoded',
            'content' => http_build_query($data)
        ]
    ];
    
    file_get_contents($url, false, stream_context_create($options));
}

function editTelegramMessage($chatId, $messageId, $text) {
    $url = "https://api.telegram.org/bot" . BOT_TOKEN . "/editMessageText";
    $data = [
        'chat_id' => $chatId,
        'message_id' => $messageId,
        'text' => $text,
        'parse_mode' => 'HTML'
    ];
    
    $options = [
        'http' => [
            'method' => 'POST',
            'header' => 'Content-Type: application/x-www-form-urlencoded',
            'content' => http_build_query($data)
        ]
    ];
    
    @file_get_contents($url, false, stream_context_create($options));
}

function getRankByPoints($points) {
    if ($points >= 1000000) return ['name' => 'Властелин Арены', 'icon' => 'arena_lord.png', 'level' => 10, 'minPoints' => 1000000];
    if ($points >= 600000) return ['name' => 'Легенда', 'icon' => 'legend.png', 'level' => 9, 'minPoints' => 600000];
    if ($points >= 300000) return ['name' => 'Чемпион II', 'icon' => 'champion2.png', 'level' => 8, 'minPoints' => 300000];
    if ($points >= 150000) return ['name' => 'Чемпион I', 'icon' => 'champion1.png', 'level' => 7, 'minPoints' => 150000];
    if ($points >= 80000) return ['name' => 'Воин III', 'icon' => 'warrior3.png', 'level' => 6, 'minPoints' => 80000];
    if ($points >= 40000) return ['name' => 'Воин II', 'icon' => 'warrior2.png', 'level' => 5, 'minPoints' => 40000];
    if ($points >= 20000) return ['name' => 'Воин I', 'icon' => 'warrior1.png', 'level' => 4, 'minPoints' => 20000];
    if ($points >= 10000) return ['name' => 'Страж II', 'icon' => 'guard2.png', 'level' => 3, 'minPoints' => 10000];
    if ($points >= 5000) return ['name' => 'Страж I', 'icon' => 'guard1.png', 'level' => 2, 'minPoints' => 5000];
    return ['name' => 'Новичок I', 'icon' => 'novice.png', 'level' => 1, 'minPoints' => 0];
}

function checkRankUp($db, $userId) {
    // Получаем старый ранг из специальной таблицы
    $oldRankLevel = $db->querySingle("SELECT rank_level FROM user_ranks WHERE user_id = $userId");
    
    // Получаем текущие баллы и новый ранг
    $points = $db->querySingle("SELECT points FROM users WHERE telegram_id = $userId") ?: 0;
    $newRank = getRankByPoints($points);
    
    // Если старого ранга нет - инициализируем
    if ($oldRankLevel === false) {
        $db->exec("CREATE TABLE IF NOT EXISTS user_ranks (
            user_id INTEGER PRIMARY KEY,
            rank_level INTEGER,
            rank_name TEXT
        )");
        $db->exec("INSERT OR REPLACE INTO user_ranks (user_id, rank_level, rank_name) VALUES ($userId, {$newRank['level']}, '{$newRank['name']}')");
        return null; // Первая инициализация, уведомление не нужно
    }
    
    // Проверяем повышение
    if ($newRank['level'] > $oldRankLevel) {
        $oldRank = getRankByPoints($newRank['minPoints'] - 1); // Получаем предыдущий ранг
        
        // Обновляем ранг
        $db->exec("UPDATE user_ranks SET rank_level = {$newRank['level']}, rank_name = '{$newRank['name']}' WHERE user_id = $userId");
        
        // Создаём уведомление
        $message = "🎉 Поздравляем с повышением ранга!\n\n📊 {$oldRank['name']} ➜ {$newRank['name']}\n💎 Уровень {$newRank['level']}";
        $data = json_encode(['oldRank' => $oldRank, 'newRank' => $newRank]);
        $db->exec("INSERT INTO user_notifications (user_id, type, message, data) VALUES ($userId, 'rank_up', '" . $db->escapeString($message) . "', '" . $db->escapeString($data) . "')");
        
        return ['old' => $oldRank, 'new' => $newRank];
    }
    
    return null;
}

function getAllRanks() {
    return [
        ['name' => 'Новичок I', 'icon' => 'novice.png', 'level' => 1, 'range' => '0 – 4,999 сум'],
        ['name' => 'Страж I', 'icon' => 'guard1.png', 'level' => 2, 'range' => '5,000 – 9,999 сум'],
        ['name' => 'Страж II', 'icon' => 'guard2.png', 'level' => 3, 'range' => '10,000 – 19,999 сум'],
        ['name' => 'Воин I', 'icon' => 'warrior1.png', 'level' => 4, 'range' => '20,000 – 39,999 сум'],
        ['name' => 'Воин II', 'icon' => 'warrior2.png', 'level' => 5, 'range' => '40,000 – 79,999 сум'],
        ['name' => 'Воин III', 'icon' => 'warrior3.png', 'level' => 6, 'range' => '80,000 – 149,999 сум'],
        ['name' => 'Чемпион I', 'icon' => 'champion1.png', 'level' => 7, 'range' => '150,000 – 299,999 сум'],
        ['name' => 'Чемпион II', 'icon' => 'champion2.png', 'level' => 8, 'range' => '300,000 – 599,999 сум'],
        ['name' => 'Легенда', 'icon' => 'legend.png', 'level' => 9, 'range' => '600,000 – 999,999 сум'],
        ['name' => 'Властелин Арены', 'icon' => 'arena_lord.png', 'level' => 10, 'range' => '1,000,000+ сум']
    ];
}

function checkRateLimit($identifier, $action, $maxRequests = 10, $periodSeconds = 60) {
    global $db;
    
    if (!$db) {
        error_log("DB not initialized in checkRateLimit");
        return;
    }
    
    static $tableCreated = false;
    if (!$tableCreated) {
        try {
            $db->exec("CREATE TABLE IF NOT EXISTS rate_limits (
                cache_key TEXT PRIMARY KEY,
                attempts INTEGER DEFAULT 0,
                reset_time INTEGER
            )");
            $tableCreated = true;
        } catch (Exception $e) {
            error_log("Rate limit error: " . $e->getMessage());
            return;
        }
    }
    
    $now = time();
    $cacheKey = md5($identifier . $action);
    
    try {
        $stmt = $db->prepare("SELECT attempts, reset_time FROM rate_limits WHERE cache_key = ?");
        $stmt->bindValue(1, $cacheKey, SQLITE3_TEXT);
        $data = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
        
        if (!$data || $now >= intval($data['reset_time'])) {
            $stmt = $db->prepare("INSERT OR REPLACE INTO rate_limits (cache_key, attempts, reset_time) VALUES (?, 1, ?)");
            $stmt->bindValue(1, $cacheKey, SQLITE3_TEXT);
            $stmt->bindValue(2, $now + $periodSeconds, SQLITE3_INTEGER);
            $stmt->execute();
            return;
        }
        
        $attempts = intval($data['attempts']);
        
        if ($attempts >= $maxRequests) {
            $timeLeft = intval($data['reset_time']) - $now;
            http_response_code(429);
            echo json_encode(['success' => false, 'error' => 'Подождите ' . $timeLeft . ' сек']);
            exit;
        }
        
        $stmt = $db->prepare("UPDATE rate_limits SET attempts = attempts + 1 WHERE cache_key = ?");
        $stmt->bindValue(1, $cacheKey, SQLITE3_TEXT);
        $stmt->execute();
        
    } catch (Exception $e) {
        error_log("Rate limit error: " . $e->getMessage());
        return;
    }
}

$db = initDB();
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$method = $_SERVER['REQUEST_METHOD'];

// Роутинг
$action = $_GET['action'] ?? '';
// Проверка Telegram авторизации
$initData = $_SERVER['HTTP_X_TELEGRAM_INIT_DATA'] ?? '';
$isValidRequest = false;

if ($initData) {
    $isValidRequest = validateTelegramWebApp($initData, BOT_TOKEN);
}

// Для критичных операций
$criticalActions = ['admin_points_modify', 'admin_task_create', 'emergency_reset_balances', 'admin_computer_status', 'admin_user', 'admin_task_delete'];
if (in_array($action, $criticalActions) && !$isValidRequest) {
    http_response_code(403);
    exit(json_encode(['error' => 'Unauthorized']));
}

switch ($action) {
    case 'test':
        echo json_encode(['status' => 'OK', 'message' => 'API работает', 'time' => date('Y-m-d H:i:s')]);
        break;
    
    case 'user':
        $userId = intval($_GET['userId']);
        
        // Кешируем запрос на 5 секунд
        $cacheKey = "user_{$userId}";
        $cached = apcu_fetch($cacheKey, $success);
        if ($success) {
            header('Content-Type: application/json');
            echo $cached;
            break;
        }
        
        $stmt = $db->prepare("SELECT * FROM users WHERE telegram_id = ?");
        $stmt->bindValue(1, $userId, SQLITE3_INTEGER);
        $user = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
        
        if ($user) {
            $user['points_registered'] = intval($user['points_registered']);
            $user['points'] = intval($user['points']);
        }
        
        $response = json_encode(sanitizeOutput($user));
        apcu_store($cacheKey, $response, 5); // Кеш 5 сек
        
        header('Content-Type: application/json');
        echo $response;
        break;
        
    case 'update_user_email':
        $data = getValidatedInput();
        $userId = intval($data['userId']);
        $email = $db->escapeString(trim($data['email']));
        
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            echo json_encode(['success' => false, 'message' => 'Неверный email']);
            break;
        }
        
        $db->exec("UPDATE users SET email = '$email' WHERE telegram_id = $userId");
        
        $code = generateVerificationCode();
        $expiresAt = date('Y-m-d H:i:s', time() + 120);
        $db->exec("INSERT OR REPLACE INTO email_verifications (user_id, email, code, expires_at) VALUES ($userId, '$email', '$code', '$expiresAt')");
        
        echo json_encode(['success' => true]);
        break;    
        
    case 'resend_verification':
        $userId = intval($_GET['userId']);
        
        file_put_contents('resend.log', date('H:i:s') . " - User: $userId\n", FILE_APPEND);
        
        $user = $db->querySingle("SELECT email, email_verified FROM users WHERE telegram_id = $userId", true);
        
        if (!$user || !$user['email']) {
            file_put_contents('resend.log', "No email found\n", FILE_APPEND);
            echo json_encode(['success' => false, 'message' => 'Email не найден']);
            break;
        }
        
        if ($user['email_verified'] == 1) {
            file_put_contents('resend.log', "Already verified\n", FILE_APPEND);
            echo json_encode(['success' => false, 'message' => 'Email уже подтвержден']);
            break;
        }
        
        $code = generateVerificationCode();
        $expiresAt = date('Y-m-d H:i:s', time() + 120);
        
        file_put_contents('resend.log', "Code: $code, Email: {$user['email']}\n", FILE_APPEND);
        
        $db->exec("UPDATE email_verifications SET code = '$code', expires_at = '$expiresAt', verified = 0 WHERE user_id = $userId");
        
        file_put_contents('resend.log', "Sending email...\n", FILE_APPEND);
        
        $success = sendVerificationEmail($user['email'], $code);
        
        file_put_contents('resend.log', "Sent: " . ($success ? 'YES' : 'NO') . "\n", FILE_APPEND);
        
        echo json_encode(['success' => $success]);
        break;    
        
    case 'user_register_points':
        $data = getValidatedInput();
        $userId = intval($data['userId']);
        
        // Rate limiting по IP (защита от массовой регистрации)
        $ipHash = hash('sha256', $_SERVER['REMOTE_ADDR'] ?? 'unknown');
        checkRateLimit("ip_{$ipHash}", 'register', 3, 3600);
        
        $name = $db->escapeString(trim($data['name']));
        $phone = $db->escapeString(trim($data['phone']));
        $email = isset($data['email']) && !empty($data['email']) ? $db->escapeString(trim($data['email'])) : null;
        
        // Логируем начало
        file_put_contents('registration.log', "\n=== " . date('H:i:s') . " ===\n", FILE_APPEND);
        file_put_contents('registration.log', "User: $userId, Email: " . ($email ?: 'none') . "\n", FILE_APPEND);
        
        function validatePhone($phone) {
            $cleanPhone = preg_replace('/[^+\d]/', '', $phone);
            if (!str_starts_with($cleanPhone, '+')) return false;
            $digits = substr($cleanPhone, 1);
            $digitCount = strlen($digits);
            if ($digitCount < 10 || $digitCount > 15) return false;
            if (preg_match('/(\d)\1{6,}/', $digits)) return false;
            return true;
        }
        
        if (!preg_match('/^\+998\d{9}$/', preg_replace('/\s+/', '', $phone))) {
            file_put_contents('registration.log', "FAILED: Invalid phone format\n", FILE_APPEND);
            echo json_encode(['success' => false, 'message' => 'Неверный формат телефона']);
            break;
        }
        
        if (!preg_match('/^[\p{L}\s]{2,50}$/u', $name)) {
            http_response_code(400);
            exit(json_encode(['error' => 'Invalid name format']));
        }
        
        if (strlen($name) < 2 || strlen($name) > 50) {
            file_put_contents('registration.log', "FAILED: Invalid name length\n", FILE_APPEND);
            echo json_encode(['success' => false, 'message' => 'Имя должно содержать от 2 до 50 символов']);
            break;
        }
        
        if (!preg_match('/[a-zA-Zа-яА-ЯёЁўўқғҳҲ]/', $name)) {
            echo json_encode(['success' => false, 'message' => 'Имя должно содержать буквы']);
            break;
        }
        
        if ($email && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            echo json_encode(['success' => false, 'message' => 'Неверный формат email']);
            break;
        }
        
        $stmt = $db->prepare("SELECT points_registered FROM users WHERE telegram_id = ?");
        $stmt->bindValue(1, $userId, SQLITE3_INTEGER);
        $existing = $stmt->execute()->fetchArray()['points_registered'] ?? null;
        if ($existing) {
            file_put_contents('registration.log', "FAILED: Already registered\n", FILE_APPEND);
            echo json_encode(['success' => false, 'message' => 'Уже зарегистрирован']);
            break;
        }
        
        
        file_put_contents('registration.log', "Validation passed, inserting...\n", FILE_APPEND);
        $stmt = $db->prepare("SELECT telegram_id FROM users WHERE phone = ? AND points_registered = 1 AND telegram_id != ?");
        $stmt->bindValue(1, $phone, SQLITE3_TEXT);
        $stmt->bindValue(2, $userId, SQLITE3_INTEGER);
        $phoneExists = $stmt->execute()->fetchArray()['telegram_id'] ?? null;
        if ($phoneExists) {
            echo json_encode(['success' => false, 'message' => 'На этот номер уже зарегистрирован другой аккаунт']);
            break;
        }
        
        // Регистрируем
        $emailField = $email ? "'$email'" : "NULL";
        if ($email) {
            $stmt = $db->prepare("INSERT OR REPLACE INTO users (telegram_id, name, phone, email, points, points_registered, email_verified) VALUES (?, ?, ?, ?, 1000, 1, 0)");
            $stmt->bindValue(1, $userId, SQLITE3_INTEGER);
            $stmt->bindValue(2, $name, SQLITE3_TEXT);
            $stmt->bindValue(3, $phone, SQLITE3_TEXT);
            $stmt->bindValue(4, $email, SQLITE3_TEXT);
        } else {
            $stmt = $db->prepare("INSERT OR REPLACE INTO users (telegram_id, name, phone, points, points_registered, email_verified) VALUES (?, ?, ?, 1000, 1, 0)");
            $stmt->bindValue(1, $userId, SQLITE3_INTEGER);
            $stmt->bindValue(2, $name, SQLITE3_TEXT);
            $stmt->bindValue(3, $phone, SQLITE3_TEXT);
        }
        $stmt->execute();
        
        $stmt = $db->prepare("INSERT INTO points_transactions (user_id, amount, action, reason) VALUES (?, 1000, 'add', 'Регистрационный бонус')");
        $stmt->bindValue(1, $userId, SQLITE3_INTEGER);
        $stmt->execute();
        invalidateUserCache($userId);
        invalidateLeaderboardCache();
        
        file_put_contents('registration.log', "Registered OK\n", FILE_APPEND);
        
        // Отправляем email
        $emailSent = false;
        if ($email) {
            file_put_contents('registration.log', "Has email, generating code...\n", FILE_APPEND);
            
            $code = generateVerificationCode();
            $expiresAt = date('Y-m-d H:i:s', time() + 120);
            
            file_put_contents('registration.log', "Code: $code, Expires: $expiresAt\n", FILE_APPEND);
            
            $stmt = $db->prepare("INSERT OR REPLACE INTO email_verifications (user_id, email, code, expires_at) VALUES (?, ?, ?, ?)");
            $stmt->bindValue(1, $userId, SQLITE3_INTEGER);
            $stmt->bindValue(2, $email, SQLITE3_TEXT);
            $stmt->bindValue(3, $code, SQLITE3_TEXT);
            $stmt->bindValue(4, $expiresAt, SQLITE3_TEXT);
            $stmt->execute();
            
            file_put_contents('registration.log', "DB updated, calling sendVerificationEmail...\n", FILE_APPEND);
            
            $emailSent = sendVerificationEmail($email, $code);
            
            file_put_contents('registration.log', "Email sent: " . ($emailSent ? 'YES' : 'NO') . "\n", FILE_APPEND);
        } else {
            file_put_contents('registration.log', "No email provided\n", FILE_APPEND);
        }
        
        echo json_encode(['success' => true, 'emailSent' => $emailSent]);
        break;
        
    case 'points':
        $userId = intval($_GET['userId']);
        
        $cacheKey = "points_{$userId}";
        $cached = apcu_fetch($cacheKey, $success);
        if ($success) {
            header('Content-Type: application/json');
            echo $cached;
            break;
        }
        
        $stmt = $db->prepare("SELECT points FROM users WHERE telegram_id = ?");
        $stmt->bindValue(1, $userId, SQLITE3_INTEGER);
        $points = $stmt->execute()->fetchArray()['points'] ?? 0;
        
        $response = json_encode(['points' => intval($points ?: 0)]);
        apcu_store($cacheKey, $response, 3);
        
        header('Content-Type: application/json');
        echo $response;
        break;
        
    case 'computers':
        $computers = [];
        $result = $db->query("SELECT * FROM computers ORDER BY id");
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $computers[] = $row;
        }
        echo json_encode($computers);
        break;
    case 'get_all_user_notifications':
        $userId = intval($_GET['userId']);
        
        // Проверяем существование таблицы
        $tableExists = $db->querySingle("SELECT name FROM sqlite_master WHERE type='table' AND name='user_notifications'");
        
        if (!$tableExists) {
            echo json_encode(['notifications' => []]);
            break;
        }
        
        $notifications = [];
        // Получаем ВСЕ уведомления пользователя (и прочитанные, и непрочитанные)
        // но только за последние 7 дней
        $sevenDaysAgo = date('Y-m-d H:i:s', time() - (7 * 24 * 60 * 60));
        
        $result = $db->query("SELECT * FROM user_notifications 
                             WHERE user_id = $userId 
                             AND created_at > '$sevenDaysAgo'
                             ORDER BY created_at DESC");
        
        if ($result) {
            while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
                $notifications[] = $row;
            }
        }
        
        echo json_encode(['notifications' => $notifications]);
        break;
    case 'mark_notifications_read':
        $data = getValidatedInput();
        $userId = intval($data['userId']);
        $notificationIds = $data['notificationIds'] ?? [];
            
        if (empty($notificationIds)) {
            echo json_encode(['success' => true]);
            break;
        }
            
        // Преобразуем массив ID в строку для SQL
        $idsString = implode(',', array_map('intval', $notificationIds));
            
            // Отмечаем уведомления как доставленные (прочитанные)
        $result = $db->exec("UPDATE user_notifications 
                            SET delivered = 1 
                            WHERE user_id = $userId 
                            AND id IN ($idsString)");
            
        echo json_encode(['success' => $result !== false]);
        break;
        
    case 'user_rank':
        $userId = intval($_GET['userId']);
        
        // Используем текущий баланс вместо totalEarned
        $stmt = $db->prepare("SELECT points FROM users WHERE telegram_id = ?");
        $stmt->bindValue(1, $userId, SQLITE3_INTEGER);
        $currentPoints = $stmt->execute()->fetchArray()['points'] ?? 0;
        $rank = getRankByPoints($currentPoints);
        
        echo json_encode([
            'totalEarned' => intval($currentPoints),
            'rank' => $rank,
            'shouldApplyRankStyles' => true
        ]);
        break;
    
    case 'user_full_data':
        $userId = intval($_GET['userId']);
        
        $stmt = $db->prepare("SELECT * FROM users WHERE telegram_id = ?");
        $stmt->bindValue(1, $userId, SQLITE3_INTEGER);
        $result = $stmt->execute();
        $user = $result->fetchArray(SQLITE3_ASSOC);
        $stmt = $db->prepare("SELECT points FROM users WHERE telegram_id = ?");
        $stmt->bindValue(1, $userId, SQLITE3_INTEGER);
        $points = $stmt->execute()->fetchArray()['points'] ?? 0;
        
        // Используем текущий баланс
        $rank = getRankByPoints($points);
        
        echo json_encode([
            'user' => $user,
            'points' => intval($points),
            'rank' => $rank,
            'totalEarned' => intval($points),
            'shouldApplyRankStyles' => true
        ]);
        break;    
    
    case 'debug_user_rank':
        $userId = intval($_GET['userId']);
        
        // Проверяем существование пользователя
        $user = $db->querySingle("SELECT * FROM users WHERE telegram_id = $userId", true);
        
        // Проверяем транзакции
        $transactions = [];
        $result = $db->query("SELECT * FROM points_transactions WHERE user_id = $userId ORDER BY created_at DESC LIMIT 5");
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $transactions[] = $row;
        }
        
        // Считаем заработок
        $totalEarned = $db->querySingle("
            SELECT COALESCE(SUM(amount), 0) 
            FROM points_transactions 
            WHERE user_id = $userId 
            AND action = 'add'
            AND reason != 'Регистрационный бонус'
        ") ?: 0;
        
        echo json_encode([
            'user' => $user,
            'transactions' => $transactions,
            'totalEarned' => $totalEarned,
            'rank' => getRankByPoints($totalEarned)
        ]);
        break;
    
    case 'all_ranks':
        echo json_encode(getAllRanks());
        break;    
    case 'debug_notifications':
        $userId = intval($_GET['userId']);
        
        // Проверяем существование таблицы
        $tableExists = $db->querySingle("SELECT name FROM sqlite_master WHERE type='table' AND name='user_notifications'");
        
        // Получаем все уведомления пользователя
        $notifications = [];
        if ($tableExists) {
            $result = $db->query("SELECT * FROM user_notifications WHERE user_id = $userId ORDER BY created_at DESC");
            while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
                $notifications[] = $row;
            }
        }
        
        // Получаем общее количество уведомлений
        $totalCount = $tableExists ? $db->querySingle("SELECT COUNT(*) FROM user_notifications WHERE user_id = $userId") : 0;
        
        echo json_encode([
            'tableExists' => $tableExists ? true : false,
            'totalCount' => $totalCount,
            'notifications' => $notifications,
            'userId' => $userId
        ]);
        break;
    
    case 'get_config':
        // Возвращаем только публичную конфигурацию для фронтенда
        echo json_encode([
            'adminIds' => Config::getAdmins(),
            'hallsConfig' => [
                'main' => [
                    'name' => 'Main',
                    'computers' => 10,
                    'layout' => [5, 5]
                ],
                'vip' => [
                    'name' => 'VIP', 
                    'computers' => 5,
                    'layout' => [3, 2]
                ],
                'tournament' => [
                    'name' => 'Tournament',
                    'computers' => 15,
                    'layout' => [5, 3]
                ]
            ]
        ]);
        break;
    
    case 'test_add_notification':
        $userId = intval($_GET['userId']);
        
        // Создаем таблицу если не существует
        $db->exec("CREATE TABLE IF NOT EXISTS user_notifications (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            type TEXT NOT NULL,
            message TEXT NOT NULL,
            data TEXT DEFAULT '',
            delivered INTEGER DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )");
        
        // Добавляем тестовое уведомление
        $testMessage = $db->escapeString("🧪 Тестовое уведомление " . date('H:i:s'));
        $insertResult = $db->exec("INSERT INTO user_notifications (user_id, type, message, data) 
                                   VALUES ($userId, 'test', '$testMessage', '')");
        
        $insertId = $db->lastInsertRowID();
        
        echo json_encode([
            'success' => $insertResult ? true : false,
            'insertId' => $insertId,
            'message' => $testMessage
        ]);
        break;
        
    case 'tasks':
        $cacheKey = 'all_tasks';
        $cached = apcu_fetch($cacheKey, $success);
        if ($success) {
            header('Content-Type: application/json');
            echo $cached;
            break;
        }
        
        $tasks = [];
        $result = $db->query("SELECT * FROM tasks ORDER BY created_at DESC");
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $tasks[] = $row;
        }
        
        $response = json_encode($tasks);
        apcu_store($cacheKey, $response, 60); // Кеш 60 сек
        
        header('Content-Type: application/json');
        echo $response;
        break;
        
    case 'task_complete':
        $data = getValidatedInput();
        $userId = intval($data['userId']);
        $taskId = intval($data['taskId']);
        
        checkRateLimit("user_{$userId}", 'task_complete', 10, 60);
        
        // Проверяем, не выполнено ли уже задание
        $stmt = $db->prepare("SELECT id FROM user_tasks WHERE user_id = ? AND task_id = ?");
        $stmt->bindValue(1, $userId, SQLITE3_INTEGER);
        $stmt->bindValue(2, $taskId, SQLITE3_INTEGER);
        $completed = $stmt->execute()->fetchArray()['id'] ?? null;
        if ($completed) {
            echo json_encode(['success' => false, 'message' => 'Задание уже выполнено']);
            break;
        }
        
        $stmt = $db->prepare("SELECT * FROM tasks WHERE id = ?");
        $stmt->bindValue(1, $taskId, SQLITE3_INTEGER);
        $result = $stmt->execute();
        $task = $result->fetchArray(SQLITE3_ASSOC);
        if (!$task) {
            echo json_encode(['success' => false, 'message' => 'Задание не найдено']);
            break;
        }
        
        // Проверяем клик по ссылке (если ссылка есть)
        if ($task['link']) {
            $stmt = $db->prepare("SELECT id FROM task_clicks WHERE user_id = ? AND task_id = ?");
            $stmt->bindValue(1, $userId, SQLITE3_INTEGER);
            $stmt->bindValue(2, $taskId, SQLITE3_INTEGER);
            $clicked = $stmt->execute()->fetchArray()['id'] ?? null;
            if (!$clicked) {
                echo json_encode([
                    'success' => false, 
                    'message' => 'Сначала перейдите по ссылке задания!'
                ]);
                break;
            }
        }
    
    // Отмечаем задание как выполненное
    $stmt = $db->prepare("INSERT INTO user_tasks (user_id, task_id) VALUES (?, ?)");
    $stmt->bindValue(1, $userId, SQLITE3_INTEGER);
    $stmt->bindValue(2, $taskId, SQLITE3_INTEGER);
    $stmt->execute();
    
    $stmt = $db->prepare("UPDATE users SET points = points + ? WHERE telegram_id = ?");
    $stmt->bindValue(1, $task['points'], SQLITE3_INTEGER);
    $stmt->bindValue(2, $userId, SQLITE3_INTEGER);
    $stmt->execute();
    
    $stmt = $db->prepare("INSERT INTO points_transactions (user_id, amount, action, reason) VALUES (?, ?, 'add', ?)");
    $stmt->bindValue(1, $userId, SQLITE3_INTEGER);
    $stmt->bindValue(2, $task['points'], SQLITE3_INTEGER);
    $stmt->bindValue(3, 'Выполнено задание: ' . $task['title'], SQLITE3_TEXT);
    $stmt->execute();
    invalidateUserCache($userId);
    invalidateLeaderboardCache();
    
    // Отправляем уведомление
    $message = "🎉 Задание выполнено!\n\n" .
              "📝 {$task['title']}\n" .
              "💰 Получено: {$task['points']} сум";
    sendNotification($userId, $message);
    
    echo json_encode(['success' => true, 'points' => $task['points']]);
    break;
        
    case 'task_click':
        $data = getValidatedInput();
        $userId = intval($data['userId']);
        $taskId = intval($data['taskId']);
        
        $stmt = $db->prepare("INSERT OR IGNORE INTO task_clicks (user_id, task_id) VALUES (?, ?)");
        $stmt->bindValue(1, $userId, SQLITE3_INTEGER);
        $stmt->bindValue(2, $taskId, SQLITE3_INTEGER);
        $stmt->execute();
        
        echo json_encode(['success' => true]);
        break;    
        
    case 'booking_request':
        $data = getValidatedInput();
        $userId = intval($data['userId']);
        $computerId = intval($data['computerId']);
        $userName = $data['userName'];
        $userPhone = $data['userPhone'];
        
        $stmt = $db->prepare("SELECT status FROM computers WHERE id = ?");
        $stmt->bindValue(1, $computerId, SQLITE3_INTEGER);
        $computer = $stmt->execute()->fetchArray()['status'] ?? null;
        if ($computer !== 'free') {
            echo json_encode(['success' => false, 'message' => 'ПК уже занят']);
            break;
        }
        
        $stmt = $db->prepare("INSERT INTO bookings (user_id, computer_id, user_name, user_phone, status) VALUES (?, ?, ?, ?, 'pending')");
        $stmt->bindValue(1, $userId, SQLITE3_INTEGER);
        $stmt->bindValue(2, $computerId, SQLITE3_INTEGER);
        $stmt->bindValue(3, $userName, SQLITE3_TEXT);
        $stmt->bindValue(4, $userPhone, SQLITE3_TEXT);
        $stmt->execute();
        $bookingId = $db->lastInsertRowID();
        
        $hallNames = [1 => 'Онлайн игры', 2 => 'Онлайн + CS 1.6', 3 => 'Оффлайн'];
        $hall = ceil($computerId / 10);
        
        $keyboard = [
            'inline_keyboard' => [[
                ['text' => '✅ Подтвердить', 'callback_data' => "confirm_booking_$bookingId"],
                ['text' => '❌ Отклонить', 'callback_data' => "reject_booking_$bookingId"]
            ]]
        ];
        
        $message = "🔔 Новый запрос на бронирование!\n\n" .
                  "👤 Клиент: $userName\n" .
                  "📞 Телефон: $userPhone\n" .
                  "🖥️ ПК №$computerId ({$hallNames[$hall]})\n" .
                  "🆔 ID: $bookingId";
        
        foreach ($ADMINS as $adminId) {
            $msgId = sendTelegramMessage($adminId, $message, $keyboard);
            if ($msgId) {
                $db->exec("INSERT INTO booking_messages (booking_id, chat_id, message_id) VALUES ($bookingId, $adminId, $msgId)");
            }
        }
        
        echo json_encode(['success' => true, 'bookingId' => $bookingId]);
        break;
        
    case 'handle_booking_callback':
        $data = getValidatedInput();
        $callbackData = $data['callback_data'];
        $chatId = intval($data['chat_id']);
        $messageId = intval($data['message_id']);
        
        if (preg_match('/(confirm|reject)_booking_(\d+)/', $callbackData, $matches)) {
            $action = $matches[1];
            $bookingId = intval($matches[2]);
            
            $booking = $db->querySingle("SELECT * FROM bookings WHERE id = $bookingId", true);
            
            if (!$booking) {
                echo json_encode(['success' => false, 'message' => 'Бронирование не найдено']);
                break;
            }
            
            if ($booking['status'] !== 'pending') {
                $statusText = $booking['status'] === 'approved' ? '✅ Уже одобрено' : '❌ Уже отклонено';
                $db->exec("UPDATE booking_messages SET status_text = '$statusText' WHERE booking_id = $bookingId");
                
                $messages = [];
                $result = $db->query("SELECT * FROM booking_messages WHERE booking_id = $bookingId");
                while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
                    $messages[] = $row;
                }
                
                foreach ($messages as $msg) {
                    $newText = $booking['status'] === 'approved' 
                        ? "✅ Бронирование одобрено\n\n👤 {$booking['user_name']}\n📞 {$booking['user_phone']}\n🖥️ ПК №{$booking['computer_id']}"
                        : "❌ Бронирование отклонено\n\n👤 {$booking['user_name']}\n📞 {$booking['user_phone']}\n🖥️ ПК №{$booking['computer_id']}";
                    
                    editTelegramMessage($msg['chat_id'], $msg['message_id'], $newText);
                }
                
                echo json_encode(['success' => false, 'message' => $statusText]);
                break;
            }
            
            if ($action === 'confirm') {
                $db->exec("UPDATE bookings SET status = 'approved' WHERE id = $bookingId");
                $db->exec("UPDATE computers SET status = 'booked' WHERE id = {$booking['computer_id']}");
                $statusText = '✅ Одобрено';
                $newMessage = "✅ Бронирование одобрено\n\n👤 {$booking['user_name']}\n📞 {$booking['user_phone']}\n🖥️ ПК №{$booking['computer_id']}";
                
                sendNotification($booking['user_id'], "✅ Ваше бронирование ПК №{$booking['computer_id']} одобрено!");
            } else {
                $db->exec("UPDATE bookings SET status = 'rejected' WHERE id = $bookingId");
                $statusText = '❌ Отклонено';
                $newMessage = "❌ Бронирование отклонено\n\n👤 {$booking['user_name']}\n📞 {$booking['user_phone']}\n🖥️ ПК №{$booking['computer_id']}";
                
                sendNotification($booking['user_id'], "❌ Ваше бронирование ПК №{$booking['computer_id']} отклонено");
            }
            
            $db->exec("UPDATE booking_messages SET status_text = '$statusText' WHERE booking_id = $bookingId");
            
            $messages = [];
            $result = $db->query("SELECT * FROM booking_messages WHERE booking_id = $bookingId");
            while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
                $messages[] = $row;
            }
            
            foreach ($messages as $msg) {
                editTelegramMessage($msg['chat_id'], $msg['message_id'], $newMessage);
            }
            
            echo json_encode(['success' => true, 'message' => $statusText]);
        }
        break;    
        
    case 'admin_points_modify':
        $data = getValidatedInput();
        $userId = intval($data['userId']);
        $amount = intval($data['amount']);
        $action = $data['action'];
        $reason = $data['reason'];
        
        // Валидация action
        if (!in_array($action, ['add', 'subtract'])) {
            echo json_encode(['success' => false, 'message' => 'Неверное действие']);
            break;
        }
        
        // ДОБАВЬ ЛОГИРОВАНИЕ:
        if ($amount > 50000 || ($action === 'add' && $amount > 10000)) {
            logSuspiciousActivity($userId, 'large_points_modification', "Action: $action, Amount: $amount, Reason: $reason");
        }
        
        // Проверяем лимиты только для начисления
        if ($action === 'add') {
            // Лимит за раз - 100000
            if ($amount > 100000) {
                echo json_encode(['success' => false, 'message' => 'Максимум 100.000 сум за раз']);
                break;
            }
            
            // Получаем текущий месячный лимит
            $currentMonth = date('Y-m');
            $limitData = $db->querySingle("SELECT monthly_spent, last_reset_month FROM admin_limits WHERE id = 1", true);
            
            // Сбрасываем лимит если новый месяц
            if ($limitData['last_reset_month'] !== $currentMonth) {
                $db->exec("UPDATE admin_limits SET monthly_spent = 0, last_reset_month = '$currentMonth' WHERE id = 1");
                $monthlySpent = 0;
            } else {
                $monthlySpent = intval($limitData['monthly_spent']);
            }
            
            // Проверяем месячный лимит
            if ($monthlySpent + $amount > 1500000) {
                $remaining = 1500000 - $monthlySpent;
                echo json_encode(['success' => false, 'message' => "Месячный лимит превышен. Осталось: $remaining сум"]);
                break;
            }
            
            // Увеличиваем потраченную сумму
            $db->exec("UPDATE admin_limits SET monthly_spent = monthly_spent + $amount WHERE id = 1");
        }
        
        // Начисляем/списываем баллы
        if ($action === 'add') {
            $stmt = $db->prepare("UPDATE users SET points = points + ? WHERE telegram_id = ?");
            $stmt->bindValue(1, $amount, SQLITE3_INTEGER);
            $stmt->bindValue(2, $userId, SQLITE3_INTEGER);
            $stmt->execute();
            $message = "💰 Вам начислено $amount сум!\nПричина: $reason";
        } else {
            $stmt = $db->prepare("UPDATE users SET points = points - ? WHERE telegram_id = ?");
            $stmt->bindValue(1, $amount, SQLITE3_INTEGER);
            $stmt->bindValue(2, $userId, SQLITE3_INTEGER);
            $stmt->execute();
            $message = "💸 С вашего счета списано $amount сум\nПричина: $reason";
        }
        
        $stmt = $db->prepare("INSERT INTO points_transactions (user_id, amount, action, reason) VALUES (?, ?, ?, ?)");
        $stmt->bindValue(1, $userId, SQLITE3_INTEGER);
        $stmt->bindValue(2, $amount, SQLITE3_INTEGER);
        $stmt->bindValue(3, $action, SQLITE3_TEXT);
        $stmt->bindValue(4, $reason, SQLITE3_TEXT);
        $stmt->execute();
        invalidateUserCache($userId);
        invalidateLeaderboardCache();
        
        sendNotification($userId, $message);
        
        echo json_encode(['success' => true]);
        break;
        
    case 'admin_points_history_paginated':
        $userId = intval($_GET['userId']);
        $page = intval($_GET['page'] ?? 1);
        $limit = 20;
        $offset = ($page - 1) * $limit;
        try {
            $history = [];
            $result = $db->query("SELECT * FROM points_transactions WHERE user_id = $userId ORDER BY created_at DESC LIMIT $limit OFFSET $offset");
            while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
                $history[] = $row;
            }
            
            $total = $db->querySingle("SELECT COUNT(*) FROM points_transactions WHERE user_id = $userId");
            $totalPages = ceil($total / $limit);
            
            echo json_encode([
                'history' => $history,
                'currentPage' => $page,
                'totalPages' => $totalPages
            ]);
        } catch (Exception $e) {
            echo json_encode(['error' => $e->getMessage()]);
        }
        break;    
        
    case 'admin_check_limits':
        $currentMonth = date('Y-m');
        $limitData = $db->querySingle("SELECT monthly_spent, last_reset_month FROM admin_limits WHERE id = 1", true);
        
        if ($limitData['last_reset_month'] !== $currentMonth) {
            $monthlySpent = 0;
            $db->exec("UPDATE admin_limits SET monthly_spent = 0, last_reset_month = '$currentMonth' WHERE id = 1");
        } else {
            $monthlySpent = intval($limitData['monthly_spent']);
        }
        
        echo json_encode([
            'monthlySpent' => $monthlySpent,
            'monthlyLimit' => 1500000,
            'remaining' => 1500000 - $monthlySpent,
            'singleLimit' => 100000
        ]);
        break;
        
    case 'full_history':
        $page = intval($_GET['page'] ?? 1);
        $limit = 50;
        $userId = $_GET['user_id'] ?? null;
        $adminOnly = isset($_GET['admin_only']);
        
        $whereClause = "";
        if ($userId) {
            $whereClause = "WHERE pt.user_id = " . intval($userId);
        } elseif ($adminOnly) {
            $whereClause = "WHERE pt.reason != 'За клики' AND pt.reason NOT LIKE '%клик%'";
        }
        
        $offset = ($page - 1) * $limit;
        
        try {
            $history = [];
            // ИСПРАВЛЕНИЕ: Добавляем $whereClause в SQL запрос!
            $result = $db->query("
                SELECT pt.*, u.name as user_name, u.first_name 
                FROM points_transactions pt 
                LEFT JOIN users u ON pt.user_id = u.telegram_id 
                $whereClause
                ORDER BY pt.created_at DESC 
                LIMIT $limit OFFSET $offset
            ");
            
            while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
                $row['user_name'] = $row['user_name'] ?: $row['first_name'] ?: null;
                $history[] = $row;
            }
            
            // ИСПРАВЛЕНИЕ: Также добавляем WHERE для подсчета общего количества
            $totalQuery = "SELECT COUNT(*) FROM points_transactions pt";
            if ($whereClause) {
                $totalQuery .= " " . $whereClause;
            }
            $total = $db->querySingle($totalQuery);
            $totalPages = ceil($total / $limit);
            
            echo json_encode([
                'history' => $history,
                'currentPage' => $page,
                'totalPages' => $totalPages
            ]);
        } catch (Exception $e) {
            echo json_encode(['error' => $e->getMessage()]);
        }
        
        break;
    
    case 'bot_statistics':
        try {
            $currentMonth = date('Y-m');
            $today = date('Y-m-d');
            
            $stats = [
                'totalTurnover' => $db->querySingle("SELECT SUM(amount) FROM points_transactions") ?: 0,
                'totalAdded' => $db->querySingle("SELECT SUM(amount) FROM points_transactions WHERE action = 'add'") ?: 0,
                'totalSubtracted' => $db->querySingle("SELECT SUM(amount) FROM points_transactions WHERE action = 'subtract'") ?: 0,
                'activeUsers' => $db->querySingle("SELECT COUNT(*) FROM users WHERE points > 0") ?: 0,
                'monthlyAdded' => $db->querySingle("SELECT SUM(amount) FROM points_transactions WHERE action = 'add' AND strftime('%Y-%m', created_at) = '$currentMonth'") ?: 0,
                'monthlySubtracted' => $db->querySingle("SELECT SUM(amount) FROM points_transactions WHERE action = 'subtract' AND strftime('%Y-%m', created_at) = '$currentMonth'") ?: 0,
                'monthlyAddedCount' => $db->querySingle("SELECT COUNT(*) FROM points_transactions WHERE action = 'add' AND strftime('%Y-%m', created_at) = '$currentMonth'") ?: 0,
                'monthlySubtractedCount' => $db->querySingle("SELECT COUNT(*) FROM points_transactions WHERE action = 'subtract' AND strftime('%Y-%m', created_at) = '$currentMonth'") ?: 0,
                'todayOperations' => $db->querySingle("SELECT COUNT(*) FROM points_transactions WHERE DATE(created_at) = '$today'") ?: 0,
                'todayTurnover' => $db->querySingle("SELECT SUM(amount) FROM points_transactions WHERE DATE(created_at) = '$today'") ?: 0,
                'averageBalance' => intval($db->querySingle("SELECT AVG(points) FROM users WHERE points > 0") ?: 0)
            ];
            
            $stats['monthlyNet'] = $stats['monthlyAdded'] - $stats['monthlySubtracted'];
            
            echo json_encode($stats);
        } catch (Exception $e) {
            echo json_encode(['error' => $e->getMessage()]);
        }
        break;   
        
    case 'admin_task_create':
        $data = getValidatedInput();
        $title = $data['title'];
        $description = $data['description'];
        $link = $data['link'];
        $points = intval($data['points']);
        
        $db->exec("INSERT INTO tasks (title, description, link, points) 
                   VALUES ('$title', '$description', '$link', $points)");
        invalidateTasksCache();           
        
        echo json_encode(['success' => true, 'taskId' => $db->lastInsertRowID()]);
        break;
        
    case 'admin_user':
        $identifier = trim($_GET['identifier'] ?? '');
        $cleanIdentifier = ltrim($identifier, '@');
        
        $stmt = $db->prepare("SELECT * FROM users WHERE phone LIKE ? OR username = ? OR name LIKE ?");
        $stmt->bindValue(1, '%' . $cleanIdentifier . '%', SQLITE3_TEXT);
        $stmt->bindValue(2, $cleanIdentifier, SQLITE3_TEXT);
        $stmt->bindValue(3, '%' . $cleanIdentifier . '%', SQLITE3_TEXT);
        $user = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
        
        echo json_encode(sanitizeOutput($user) ?: null);
        break;
        
    case 'admin_points_history':
        $userId = $_GET['userId'];
        $history = [];
        $result = $db->query("SELECT * FROM points_transactions WHERE user_id = " . intval($userId) . " ORDER BY created_at DESC");
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $history[] = $row;
        }
        echo json_encode($history);
        break;
        
    case 'admin_bookings':
        $page = intval($_GET['page'] ?? 1);
        $offset = ($page - 1) * 20;
        $bookings = [];
        $result = $db->query("SELECT * FROM bookings ORDER BY created_at DESC LIMIT 20 OFFSET $offset");
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $bookings[] = $row;
        }
        $total = $db->querySingle("SELECT COUNT(*) FROM bookings");
        echo json_encode(['bookings' => $bookings, 'currentPage' => $page, 'totalPages' => ceil($total/20)]);
        break;
        
    case 'admin_users':
        $page = intval($_GET['page'] ?? 1);
        $offset = ($page - 1) * 20;
        $users = [];
        $result = $db->query("SELECT * FROM users ORDER BY created_at DESC LIMIT 20 OFFSET $offset");
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $users[] = $row;
        }
        $total = $db->querySingle("SELECT COUNT(*) FROM users");
        echo json_encode(['users' => $users, 'currentPage' => $page, 'totalPages' => ceil($total/20)]);
        break;
    
    case 'admin_computer_status':
        $input = file_get_contents('php://input');
        $data = json_decode($input, true);
        
        if (!$data) {
            echo json_encode(['success' => false, 'message' => 'Некорректный JSON']);
            break;
        }
        
        $computerId = intval($data['computerId']);
        $status = $data['status'];
        
        // Проверяем валидность статуса
        $validStatuses = ['free', 'occupied', 'booked'];
        if (!in_array($status, $validStatuses)) {
            echo json_encode(['success' => false, 'message' => 'Неверный статус']);
            break;
        }
        
        try {
            $result = $stmt = $db->prepare("UPDATE computers SET status = ? WHERE id = ?");
            $stmt->bindValue(1, $status, SQLITE3_TEXT);
            $stmt->bindValue(2, $computerId, SQLITE3_INTEGER);
            $stmt->execute();
            
            if ($result !== false) {
                echo json_encode(['success' => true]);
            } else {
                echo json_encode(['success' => false, 'message' => 'Ошибка обновления БД']);
            }
        } catch (Exception $e) {
            file_put_contents('admin_debug.txt', 
                date('Y-m-d H:i:s') . " - Exception: " . $e->getMessage() . "\n", FILE_APPEND);
            echo json_encode(['success' => false, 'message' => 'Исключение: ' . $e->getMessage()]);
        }
        break;
        
    case 'referral_data':
        $userId = intval($_GET['userId']);
        
        try {
            $referrals = [];
            $result = $db->query("SELECT u.name, u.first_name, r.reward, r.created_at FROM referrals r 
                                 JOIN users u ON u.telegram_id = r.referred_id 
                                 WHERE r.referrer_id = $userId ORDER BY r.created_at DESC");
            
            if ($result) {
                while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
                    $referrals[] = [
                        'name' => $row['name'] ?: $row['first_name'] ?: 'Пользователь',
                        'reward' => intval($row['reward']),
                        'created_at' => $row['created_at']
                    ];
                }
            }
            
            $count = $db->querySingle("SELECT COUNT(*) FROM referrals WHERE referrer_id = $userId") ?: 0;
            $earnings = $db->querySingle("SELECT SUM(reward) FROM referrals WHERE referrer_id = $userId") ?: 0;
            
            echo json_encode([
                'success' => true,
                'count' => intval($count),
                'earnings' => intval($earnings),
                'referrals' => $referrals
            ]);
            
        } catch (Exception $e) {
            echo json_encode([
                'success' => false,
                'count' => 0,
                'earnings' => 0,
                'referrals' => [],
                'error' => $e->getMessage()
            ]);
        }
        break;

    case 'process_referral':
        $data = getValidatedInput();
        $referrerId = intval($data['referrerId']);
        $newUserId = intval($data['newUserId']);
        
        // Проверяем, не реферал ли уже этот пользователь
        $stmt = $db->prepare("SELECT id FROM referrals WHERE referred_id = ?");
        $stmt->bindValue(1, $newUserId, SQLITE3_INTEGER);
        $existing = $stmt->execute()->fetchArray()['id'] ?? null;
        if ($existing) {
            echo json_encode(['success' => false, 'message' => 'Уже является рефералом']);
            break;
        }
        
        // Определяем награду (1000 за первого, 500 за остальных)
        $stmt = $db->prepare("SELECT COUNT(*) FROM referrals WHERE referrer_id = ?");
        $stmt->bindValue(1, $referrerId, SQLITE3_INTEGER);
        $referralCount = $stmt->execute()->fetchArray()[0] ?? 0;
        $reward = $referralCount == 0 ? 1000 : 500;
        
        // Добавляем реферала
        $db->exec("INSERT INTO referrals (referrer_id, referred_id, reward) VALUES ($referrerId, $newUserId, $reward)");
        
        // Начисляем баллы
        $db->exec("UPDATE users SET points = points + $reward WHERE telegram_id = $referrerId");
        $db->exec("INSERT INTO points_transactions (user_id, amount, action, reason) 
           VALUES ($referrerId, $reward, 'add', 'Реферальная награда')");

        invalidateUserCache($referrerId);
        invalidateLeaderboardCache();
        
        echo json_encode(['success' => true, 'reward' => $reward]);
        break;
    
    case 'clicks_data':
        $userId = intval($_GET['userId']);
        $today = date('Y-m-d');
        
        $clickData = $db->querySingle("SELECT * FROM user_clicks WHERE user_id = $userId", true);
        
        if (!$clickData) {
            $db->exec("INSERT INTO user_clicks (user_id, clicks_today, earned_today, last_reset) VALUES ($userId, 0, 0, '$today')");
            $clickData = ['clicks_today' => 0, 'earned_today' => 0, 'last_reset' => $today];
        }
        
        // Сброс если новый день
        if ($clickData['last_reset'] !== $today) {
            $db->exec("UPDATE user_clicks SET clicks_today = 0, earned_today = 0, last_reset = '$today' WHERE user_id = $userId");
            $clickData = ['clicks_today' => 0, 'earned_today' => 0, 'last_reset' => $today];
        }
        
        $clicks = min(intval($clickData['clicks_today']), 10);
        $earned = min(intval($clickData['earned_today']), 500);
        
        $isBlocked = $clicks >= 10 || $earned >= 500;
        
        $timeLeft = 0;
        if ($isBlocked) {
            // Получаем время последнего клика
            $lastClickTime = $db->querySingle("SELECT strftime('%s', created_at) FROM points_transactions WHERE user_id = $userId AND reason = 'Клик' AND DATE(created_at) = '$today' ORDER BY created_at DESC LIMIT 1");
            if ($lastClickTime) {
                $elapsed = time() - $lastClickTime;
                $timeLeft = max(0, 86400 - $elapsed);
            } else {
                $timeLeft = 86400;
            }
        }
        
        echo json_encode([
            'today' => $clicks,
            'earned' => $earned,
            'isBlocked' => $isBlocked,
            'timeLeft' => $timeLeft
        ]);
        break;
        
    case 'profile_earnings':
        $userId = $_GET['userId'];
        
        // Суммируем весь заработок пользователя
        $totalEarned = $db->querySingle("
            SELECT SUM(amount) 
            FROM points_transactions 
            WHERE user_id = " . intval($userId) . " 
            AND action = 'add'
            AND reason != 'Регистрационный бонус'
        ") ?: 0;
        
        // Заработок за сегодня
        $todayEarned = $db->querySingle("
            SELECT SUM(amount) 
            FROM points_transactions 
            WHERE user_id = " . intval($userId) . " 
            AND action = 'add'
            AND DATE(created_at) = CURRENT_DATE
            AND reason != 'Регистрационный бонус'
        ") ?: 0;
        
        // Заработок по категориям
        $clicksEarned = $db->querySingle("
            SELECT SUM(amount) 
            FROM points_transactions 
            WHERE user_id = " . intval($userId) . " 
            AND reason = 'Клик'
        ") ?: 0;
        
        $tasksEarned = $db->querySingle("
            SELECT SUM(amount) 
            FROM points_transactions 
            WHERE user_id = " . intval($userId) . " 
            AND reason LIKE 'Выполнено задание:%'
        ") ?: 0;
        
        $referralEarned = $db->querySingle("
            SELECT SUM(amount) 
            FROM points_transactions 
            WHERE user_id = " . intval($userId) . " 
            AND reason = 'Реферальная награда'
        ") ?: 0;
        
        echo json_encode([
            'totalEarned' => intval($totalEarned),
            'todayEarned' => intval($todayEarned),
            'clicksEarned' => intval($clicksEarned),
            'tasksEarned' => intval($tasksEarned),
            'referralEarned' => intval($referralEarned)
        ]);
        break;    
    
    case 'perform_click':
        $data = getValidatedInput();
        $userId = intval($data['userId']);
        
        checkRateLimit("user_{$userId}", 'perform_click', 15, 60); // БЕЗ $db!
        
        $today = date('Y-m-d');
        
        // Получаем текущие данные
        $clickData = $db->querySingle("SELECT * FROM user_clicks WHERE user_id = $userId", true);
        
        if (!$clickData || $clickData['last_reset'] !== $today) {
            // Новый день - сбрасываем счетчики
            $db->exec("INSERT OR REPLACE INTO user_clicks (user_id, clicks_today, earned_today, last_reset) 
                       VALUES ($userId, 0, 0, '$today')");
            $clicks = 0;
            $earned = 0;
        } else {
            $clicks = intval($clickData['clicks_today']);
            $earned = intval($clickData['earned_today']);
        }
        
        // Проверяем лимиты
        if ($clicks >= 10 || $earned >= 500) {
            echo json_encode(['success' => false, 'message' => 'Лимит достигнут']);
            break;
        }
        
        // Добавляем один клик
        $clicks++;
        $pointsPerClick = 50;
        $earned += $pointsPerClick;
        
        // Обновляем базу данных
        $db->exec("UPDATE user_clicks SET clicks_today = $clicks, earned_today = $earned WHERE user_id = $userId");
        $db->exec("UPDATE users SET points = points + $pointsPerClick WHERE telegram_id = $userId");
        $db->exec("INSERT INTO points_transactions (user_id, amount, action, reason) 
           VALUES ($userId, $pointsPerClick, 'add', 'Клик')");

        invalidateUserCache($userId);
        invalidateLeaderboardCache();
        
        $isBlocked = ($clicks >= 10) || ($earned >= 500);
        
        echo json_encode([
            'success' => true,
            'data' => [
                'today' => $clicks,
                'earned' => $earned,
                'isBlocked' => $isBlocked
            ]
        ]);
        break;
        
    case 'complete_hold_game':
        $data = getValidatedInput();
        $userId = intval($data['userId']);
        $holdTime = intval($data['holdTime'] ?? 0);
        
        checkRateLimit("user_{$userId}", 'complete_hold_game', 5, 60);
        
        if ($holdTime < 6000 || $holdTime > 7000) {
            echo json_encode(['success' => false, 'message' => 'Неверное время удержания']);
            exit;
        }
        
        $today = date('Y-m-d');
        $now = time();
        
        $stmt = $db->prepare("SELECT last_hold_game FROM users WHERE telegram_id = ?");
        $stmt->bindValue(1, $userId, SQLITE3_INTEGER);
        $result = $stmt->execute();
        $user = $result->fetchArray(SQLITE3_ASSOC);
        
        if ($user && $user['last_hold_game'] === $today) {
            echo json_encode(['success' => false, 'message' => 'Уже сыграно сегодня']);
            exit;
        }
        
        $db->exec("UPDATE users SET points = points + 500, last_hold_game = '$today', last_hold_timestamp = $now WHERE telegram_id = $userId");
        $db->exec("INSERT INTO points_transactions (user_id, amount, action, reason) VALUES ($userId, 500, 'add', 'Игра на удержание')");
        
        invalidateUserCache($userId);
        invalidateLeaderboardCache();
        
        echo json_encode([
            'success' => true,
            'reward' => 500,
            'timeLeft' => 86400
        ]);
        break;
    
    case 'hold_game_status':
        $userId = intval($_GET['userId']);
        
        try {
            @$db->exec("ALTER TABLE users ADD COLUMN last_hold_game DATE DEFAULT NULL");
            @$db->exec("ALTER TABLE users ADD COLUMN last_hold_timestamp INTEGER DEFAULT NULL");
        } catch (Exception $e) {}
        
        $stmt = $db->prepare("SELECT last_hold_game, last_hold_timestamp FROM users WHERE telegram_id = ?");
        $stmt->bindValue(1, $userId, SQLITE3_INTEGER);
        $result = $stmt->execute();
        $user = $result->fetchArray(SQLITE3_ASSOC);
        
        $today = date('Y-m-d');
        $lastPlayDate = $user['last_hold_game'] ?? null;
        $lastPlayTimestamp = intval($user['last_hold_timestamp'] ?? 0);
        
        $canPlay = ($lastPlayDate !== $today);
        
        $timeLeft = 0;
        if (!$canPlay && $lastPlayTimestamp > 0) {
            $elapsed = time() - $lastPlayTimestamp;
            $timeLeft = max(0, 86400 - $elapsed);
        }
        
        echo json_encode([
            'canPlay' => $canPlay,
            'lastPlayTime' => $lastPlayDate,
            'timeLeft' => $timeLeft
        ]);
        break;    
        
    case 'profile_history_grouped':
        $userId = $_GET['userId'];
        
        // Получаем все транзакции кроме кликов
        $nonClicksQuery = "
            SELECT amount, action, reason, DATE(created_at) as date, created_at
            FROM points_transactions 
            WHERE user_id = " . intval($userId) . " 
            AND reason != 'Клик'
            ORDER BY created_at DESC
            LIMIT 10
        ";
        
        // Получаем клики, группированные по дням
        $clicksQuery = "
            SELECT SUM(amount) as amount, 'add' as action, 'Клики' as reason, 
                   DATE(created_at) as date, MAX(created_at) as created_at
            FROM points_transactions 
            WHERE user_id = " . intval($userId) . " 
            AND reason = 'Клик'
            GROUP BY DATE(created_at)
            ORDER BY date DESC
            LIMIT 5
        ";
        
        $history = [];
        
        // Добавляем не-клики
        $result = $db->query($nonClicksQuery);
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $history[] = $row;
        }
        
        // Добавляем сгруппированные клики
        $result = $db->query($clicksQuery);
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            // Формируем красивую причину с датой
            $dateFormatted = date('d.m.Y', strtotime($row['date']));
            $row['reason'] = "Клики за {$dateFormatted}";
            $history[] = $row;
        }
        
        // Сортируем по дате создания
        usort($history, function($a, $b) {
            return strtotime($b['created_at']) - strtotime($a['created_at']);
        });
        
        // Берем только первые 15 записей
        $history = array_slice($history, 0, 15);
        
        echo json_encode($history);
        break;    
    
    case 'admin_task_delete':
        $taskId = intval($_GET['taskId']);
        
        // Удаляем задание и связанные записи
        $db->exec("DELETE FROM tasks WHERE id = $taskId");
        $db->exec("DELETE FROM user_tasks WHERE task_id = $taskId");
        $db->exec("DELETE FROM task_clicks WHERE task_id = $taskId");
        
        echo json_encode(['success' => true]);
        break;
        
    case 'leaderboard':
        $offset = intval($_GET['offset'] ?? 0);
        $limit = intval($_GET['limit'] ?? 5);
        
        $cacheKey = "leaderboard_{$offset}_{$limit}";
        $cached = apcu_fetch($cacheKey, $success);
        if ($success) {
            header('Content-Type: application/json');
            echo $cached;
            break;
        }
        
        try {
            $stmt = $db->prepare("
                SELECT name, points 
                FROM users 
                WHERE points_registered = 1
                AND points > 0 
                AND name IS NOT NULL 
                AND name != ''
                ORDER BY points DESC 
                LIMIT ? OFFSET ?
            ");
            
            $stmt->bindValue(1, $limit, SQLITE3_INTEGER);
            $stmt->bindValue(2, $offset, SQLITE3_INTEGER);
            $result = $stmt->execute();
            
            $leadersList = [];
            while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
                $leadersList[] = [
                    'name' => $row['name'] ?: 'Пользователь',
                    'points' => intval($row['points'])
                ];
            }
            
            $totalCount = $db->querySingle("
                SELECT COUNT(*) 
                FROM users 
                WHERE points_registered = 1
                AND points > 0 
                AND name IS NOT NULL 
                AND name != ''
            ");
            
            $hasMore = $totalCount > ($offset + $limit);
            
            $response = json_encode([
                'success' => true,
                'leaders' => $leadersList, 
                'hasMore' => $hasMore
            ]);
            
            apcu_store($cacheKey, $response, 30); // Кеш 30 сек
            
            header('Content-Type: application/json');
            echo $response;
            
        } catch (Exception $e) {
            header('Content-Type: application/json');
            echo json_encode([
                'success' => false,
                'error' => $e->getMessage(),
                'leaders' => [],
                'hasMore' => false
            ]);
        }
        break;
        
    case 'emergency_reset_balances':
        logSuspiciousActivity('system', 'emergency_reset', 'All balances reset');
        
        try {
            $result = $db->exec("UPDATE users SET points = 0");
            $affected = $db->changes();
            
            // Добавляем запись о сбросе балансов
            $db->exec("INSERT INTO points_transactions (user_id, amount, action, reason) 
                       SELECT telegram_id, -points, 'subtract', 'Emergency reset' FROM users WHERE points > 0");
            
            echo json_encode(['success' => true, 'affected' => $affected]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        break;
    
    case 'emergency_get_all_users':
        try {
            $users = [];
            $result = $db->query("SELECT telegram_id, name, first_name, username, points, created_at FROM users ORDER BY created_at DESC");
            while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
                $users[] = $row;
            }
            echo json_encode(['success' => true, 'users' => $users]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        break;
    
    case 'emergency_get_users_with_balances':
        try {
            $users = [];
            $result = $db->query("SELECT telegram_id, name, first_name, username, points, created_at FROM users WHERE points > 0 ORDER BY points DESC");
            while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
                $users[] = $row;
            }
            echo json_encode(['success' => true, 'users' => $users]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        break;
    
    case 'emergency_delete_user':
        $data = getValidatedInput();
        $userId = intval($data['userId']);
        
        logSuspiciousActivity($userId, 'emergency_delete', "User deleted");
        
        try {
            // Удаляем все связанные записи
            $db->exec("DELETE FROM points_transactions WHERE user_id = $userId");
            $db->exec("DELETE FROM user_tasks WHERE user_id = $userId");
            $db->exec("DELETE FROM task_clicks WHERE user_id = $userId");
            $db->exec("DELETE FROM referrals WHERE referrer_id = $userId OR referred_id = $userId");
            $db->exec("DELETE FROM user_clicks WHERE user_id = $userId");
            $db->exec("DELETE FROM bookings WHERE user_id = $userId");
            $db->exec("DELETE FROM daily_rewards WHERE user_id = $userId");
            $db->exec("DELETE FROM users WHERE telegram_id = $userId");
            
            echo json_encode(['success' => true]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        break;
        
    case 'security_test':
        // Только для админов
        if (!in_array($action, $criticalActions) || !$isValidRequest) {
            http_response_code(403);
            exit(json_encode(['error' => 'Unauthorized']));
        }
        
        $tests = [
            'rate_limit_table' => $db->querySingle("SELECT COUNT(*) FROM rate_limits") ?: 0,
            'db_connection' => $db ? 'OK' : 'FAIL',
            'functions' => [
                'checkRateLimit' => function_exists('checkRateLimit'),
                'sanitizeOutput' => function_exists('sanitizeOutput'),
                'getValidatedInput' => function_exists('getValidatedInput'),
                'logSuspiciousActivity' => function_exists('logSuspiciousActivity')
            ]
        ];
        
        echo json_encode($tests);
        break;    
        
    case 'get_user_notifications':
        $userId = intval($_GET['userId']);
        
        // Логируем запрос
        file_put_contents('notifications_debug.txt', 
            date('Y-m-d H:i:s') . " - Getting notifications for user: $userId\n", FILE_APPEND);
        
        // Проверяем существование таблицы
        $tableExists = $db->querySingle("SELECT name FROM sqlite_master WHERE type='table' AND name='user_notifications'");
        
        if (!$tableExists) {
            file_put_contents('notifications_debug.txt', 
                date('Y-m-d H:i:s') . " - Table user_notifications does not exist\n", FILE_APPEND);
            echo json_encode(['notifications' => []]);
            break;
        }
        
        $notifications = [];
        $result = $db->query("SELECT * FROM user_notifications WHERE user_id = $userId AND delivered = 0 ORDER BY created_at DESC");
        
        if ($result) {
            while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
                $notifications[] = $row;
            }
        }
        
        file_put_contents('notifications_debug.txt', 
            date('Y-m-d H:i:s') . " - Found " . count($notifications) . " notifications\n", FILE_APPEND);
        
        echo json_encode(['notifications' => $notifications]);
        break;
    
    case 'mark_notifications_delivered':
        $userId = intval($_POST['userId']);
        $db->exec("UPDATE user_notifications SET delivered = 1 WHERE user_id = $userId");
        echo json_encode(['success' => true]);
        break;    
        
    case 'daily_reward_status':
        $userId = intval($_GET['userId']);
        $today = date('Y-m-d');
        
        try {
            // Создаем таблицу если не существует
            $db->exec("CREATE TABLE IF NOT EXISTS daily_rewards (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                claim_date DATE NOT NULL,
                reward_amount INTEGER NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE(user_id, claim_date)
            )");
            
            // Проверяем получал ли приз сегодня
            $stmt = $db->prepare("SELECT id FROM daily_rewards WHERE user_id = ? AND claim_date = ?");
            $stmt->bindValue(1, $userId, SQLITE3_INTEGER);
            $stmt->bindValue(2, $today, SQLITE3_TEXT);
            $claimed = $stmt->execute()->fetchArray()['id'] ?? null;
            
            // Считаем статистику
            $currentMonth = date('Y-m');
            $monthlyTotal = $db->querySingle("SELECT SUM(reward_amount) FROM daily_rewards WHERE user_id = $userId AND strftime('%Y-%m', claim_date) = '$currentMonth'") ?: 0;
            $dayCount = $db->querySingle("SELECT COUNT(*) FROM daily_rewards WHERE user_id = $userId AND strftime('%Y-%m', claim_date) = '$currentMonth'") ?: 0;
            $nextReward = min(($dayCount + 1) * 50, 3000 - $monthlyTotal);
            
            $canClaim = !$claimed && $nextReward > 0;
            
            // Время до полуночи если уже получен
            $timeLeft = 0;
            if ($claimed) {
                // Получаем время когда был получен приз
                $claimTime = $db->querySingle("SELECT strftime('%s', created_at) FROM daily_rewards WHERE user_id = $userId AND claim_date = '$today'");
                if ($claimTime) {
                    $elapsed = time() - $claimTime; // Прошло времени с момента получения
                    $timeLeft = max(0, 86400 - $elapsed); // Остается до 24 часов
                } else {
                    $timeLeft = 86400;
                }
            }
            
            echo json_encode([
                'canClaim' => $canClaim,
                'nextReward' => $nextReward,
                'monthlyTotal' => $monthlyTotal,
                'dayCount' => $dayCount,
                'timeLeft' => $timeLeft
            ]);
            
        } catch (Exception $e) {
            echo json_encode(['canClaim' => false, 'nextReward' => 0, 'monthlyTotal' => 0, 'dayCount' => 0, 'timeLeft' => 0]);
        }
        break;
    
    case 'claim_daily_reward':
        $data = getValidatedInput();
        $userId = intval($data['userId']);
        
        checkRateLimit("user_{$userId}", 'claim_daily_reward', 3, 60);
        
        $today = date('Y-m-d');
        $currentMonth = date('Y-m');
        try {
            // Проверяем уже получал ли сегодня
            $claimed = $db->querySingle("SELECT id FROM daily_rewards WHERE user_id = $userId AND claim_date = '$today'");
            if ($claimed) {
                echo json_encode(['success' => false, 'message' => 'Уже получен сегодня']);
                break;
            }
            
            // Проверяем месячный лимит
            $monthlyTotal = $db->querySingle("SELECT SUM(reward_amount) FROM daily_rewards WHERE user_id = $userId AND strftime('%Y-%m', claim_date) = '$currentMonth'") ?: 0;
            if ($monthlyTotal >= 3000) {
                echo json_encode(['success' => false, 'message' => 'Месячный лимит достигнут']);
                break;
            }
            
            // Рассчитываем награду
            $dayCount = $db->querySingle("SELECT COUNT(*) FROM daily_rewards WHERE user_id = $userId AND strftime('%Y-%m', claim_date) = '$currentMonth'") ?: 0;
            $reward = min(($dayCount + 1) * 50, 3000 - $monthlyTotal);
            
            if ($reward <= 0) {
                echo json_encode(['success' => false, 'message' => 'Лимит исчерпан']);
                break;
            }
            
            // Записываем награду
            $db->exec("INSERT INTO daily_rewards (user_id, claim_date, reward_amount) VALUES ($userId, '$today', $reward)");
            
            // Начисляем баллы
            $db->exec("UPDATE users SET points = points + $reward WHERE telegram_id = $userId");
            $db->exec("INSERT INTO points_transactions (user_id, amount, action, reason) VALUES ($userId, $reward, 'add', 'Ежедневный приз')");
            
            invalidateUserCache($userId);
            invalidateLeaderboardCache();
            
            echo json_encode(['success' => true, 'reward' => $reward]);
            
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => 'Ошибка сервера']);
        }
        break;    
            
    case 'user_completed_tasks':
        $userId = $_GET['userId'];
        $completed = [];
        $result = $db->query("SELECT task_id FROM user_tasks WHERE user_id = " . intval($userId));
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
             $completed[] = $row;
        }
            echo json_encode($completed);
            break;
            
    case 'verify_email':
        $data = getValidatedInput();
        $userId = intval($data['userId']);
        $code = trim($data['code']);
        
        checkRateLimit("user_{$userId}", 'verify_email', 5, 300);
        if (!preg_match('/^\d{6}$/', $code)) {
            http_response_code(400);
            exit(json_encode(['error' => 'Invalid code format']));
        }
        
        $now = date('Y-m-d H:i:s');
        $verification = $db->querySingle("SELECT *, datetime(expires_at) as exp, datetime('now') as now FROM email_verifications WHERE user_id = $userId AND verified = 0", true);
        
        file_put_contents('verify_debug.log', date('H:i:s') . " - User: $userId\n", FILE_APPEND);
        file_put_contents('verify_debug.log', "Entered: '$code'\n", FILE_APPEND);
        file_put_contents('verify_debug.log', "DB code: '{$verification['code']}'\n", FILE_APPEND);
        file_put_contents('verify_debug.log', "Expires: {$verification['exp']}, Now: {$verification['now']}\n", FILE_APPEND);
        
        if (!$verification) {
            echo json_encode(['success' => false, 'message' => 'Код не найден']);
            break;
        }
        
        if ($verification['exp'] < $verification['now']) {
            echo json_encode(['success' => false, 'message' => 'Код истёк']);
            break;
        }
        
        if ($code !== $verification['code']) {
            echo json_encode(['success' => false, 'message' => 'Неверный код']);
            break;
        }
        
        $db->exec("UPDATE email_verifications SET verified = 1 WHERE user_id = $userId");
        $db->exec("UPDATE users SET email_verified = 1 WHERE telegram_id = $userId");
        $db->exec("UPDATE users SET points = points + 500 WHERE telegram_id = $userId");
        $db->exec("INSERT INTO points_transactions (user_id, amount, action, reason) VALUES ($userId, 500, 'add', 'Подтверждение email')");
        
        invalidateUserCache($userId);
        invalidateLeaderboardCache();
        
        // ДОБАВЛЯЕМ УВЕДОМЛЕНИЕ
        $emailEscaped = $db->escapeString($verification['email']);
        $message = "✅ Email подтвержден!\n\n📧 $emailEscaped\n💰 Начислено: +500 сум";
        $db->exec("INSERT INTO user_notifications (user_id, type, message) VALUES ($userId, 'email_verified', '$message')");
        
        echo json_encode(['success' => true]);
        break;
        
    case 'export_users_txt':
        // ИСПРАВЛЕННАЯ кодировка
        header('Content-Type: text/plain; charset=utf-8');
        header('Content-Disposition: attachment; filename="users_' . date('Y-m-d') . '.txt"');
        
        // Добавляем BOM для корректного отображения UTF-8
        $output = "\xEF\xBB\xBF"; // UTF-8 BOM
        
        $output .= "USER LIST - " . strtoupper(APP_SHORT_NAME) . "\n";
        $output .= "Дата экспорта: " . date('Y-m-d H:i:s') . "\n";
        $output .= str_repeat("=", 80) . "\n\n";
        
        $result = $db->query("SELECT * FROM users ORDER BY created_at DESC");
        $count = 1;
        
        while ($user = $result->fetchArray(SQLITE3_ASSOC)) {
            $output .= "#{$count}\n";
            $output .= "Имя: " . ($user['name'] ?: $user['first_name'] ?: 'Не указано') . "\n";
            $output .= "Username: " . ($user['username'] ? '@' . $user['username'] : 'Не указан') . "\n";
            $output .= "Телефон: " . ($user['phone'] ?: 'Не указан') . "\n";
            $output .= "Email: " . ($user['email'] ?: 'Не указан');
            
            if ($user['email'] && $user['email_verified']) {
                $output .= " ✓ Подтверждён";
            }
            $output .= "\n";
            
            $output .= "Telegram ID: " . $user['telegram_id'] . "\n";
            $output .= "Баллы: " . number_format($user['points'] ?: 0, 0, ',', ' ') . " сум\n";
            $output .= "Регистрация: " . $user['created_at'] . "\n";
            $output .= str_repeat("-", 80) . "\n\n";
            $count++;
        }
        
        $output .= "\nВсего пользователей: " . ($count - 1);
        
        echo $output;
        exit;    

    case 'resend_verification':
        $userId = intval($_GET['userId']);
        $user = $db->querySingle("SELECT email FROM users WHERE telegram_id = $userId AND email IS NOT NULL AND email_verified = 0", true);
        
        if (!$user || !$user['email']) {
            echo json_encode(['success' => false, 'message' => 'Email не найден']);
            break;
        }
        
        $code = generateVerificationCode();
        $expiresAt = date('Y-m-d H:i:s', time() + 900);
        $db->exec("UPDATE email_verifications SET code = '$code', expires_at = '$expiresAt', created_at = CURRENT_TIMESTAMP WHERE user_id = $userId");
        
        $success = sendVerificationEmail($user['email'], $code);
        echo json_encode(['success' => $success]);
        break;
    
    case 'email_verification_status':
        $userId = intval($_GET['userId']);
        $user = $db->querySingle("SELECT email, email_verified FROM users WHERE telegram_id = $userId", true);
        
        echo json_encode([
            'hasEmail' => !empty($user['email']),
            'verified' => ($user['email_verified'] ?? 0) == 1,
            'email' => $user['email'] ?? null
        ]);
        break;
    
    case 'fix_user_data':
        try {
            $db->exec("UPDATE users SET email_verified = 0 WHERE email_verified IS NULL");
            $db->exec("UPDATE users SET points_registered = 1 WHERE points_registered IS NULL AND points > 0");
            $db->exec("UPDATE users SET points_registered = 0 WHERE points_registered IS NULL");
            echo json_encode(['success' => true, 'message' => 'Данные исправлены']);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        break;
}
?>
