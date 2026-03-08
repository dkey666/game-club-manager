<?php
require_once __DIR__ . '/config.php';
$adminsJson = json_encode(Config::getAdmins());
$dbFile = Config::get('DB_NAME', 'club.db');
function validateTelegramWebApp($initData, $botToken, $maxAge = 86400) {
    if (empty($initData)) {
        error_log("Empty initData from IP: " . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
        return false;
    }
    
    try {
        parse_str($initData, $data);
        
        if (!isset($data['hash'])) {
            error_log("Missing hash from IP: " . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
            return false;
        }
        
        if (isset($data['auth_date'])) {
            $authTime = intval($data['auth_date']);
            if (time() - $authTime > $maxAge) {
                error_log("Expired auth_date for user: " . ($data['user'] ?? 'unknown'));
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
        
        $isValid = hash_equals($calculatedHash, $hash);
        
        if (!$isValid) {
            error_log("Invalid hash for user: " . ($data['user'] ?? 'unknown') . " from IP: " . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
        }
        
        return $isValid;
    } catch (Exception $e) {
        error_log("Validation exception: " . $e->getMessage());
        return false;
    }
}

// Проверяем, это webhook запрос или обычный посетитель
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['webhook'])) {
    // Это webhook от Telegram - обрабатываем как обычно
    $input = file_get_contents('php://input');
    
    $update = json_decode($input, true);
    
    // ИЗМЕНЕНИЕ: Заменяем жестко заданный массив на конфигурацию
    $ADMINS = Config::getAdmins();
    
    // Обработка команд
    if (isset($update['message']['text'])) {
        $chatId = $update['message']['chat']['id'];
        $text = $update['message']['text'];
        $userId = $update['message']['from']['id'];
        
        if ($text === '/start' || strpos($text, '/start ref_') === 0) {
            $db = new SQLite3($dbFile);
            
            $ipAddress = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['HTTP_X_REAL_IP'] ?? $_SERVER['REMOTE_ADDR'] ?? 'unknown';
            
            $firstName = $db->escapeString($update['message']['from']['first_name'] ?? '');
            $username = $db->escapeString($update['message']['from']['username'] ?? '');
            
            // Проверяем существующего пользователя
            $stmt = $db->prepare("SELECT id FROM users WHERE telegram_id = :userId");
            $stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
            $result = $stmt->execute();
            $existingUser = $result->fetchArray(SQLITE3_ASSOC);
            
            if ($existingUser) {
                // Обновляем существующего пользователя, сохраняя его выбранный язык
                $stmt = $db->prepare("UPDATE users SET 
                                      username = :username, 
                                      first_name = :firstName, 
                                      ip_address = :ipAddress
                                      WHERE telegram_id = :userId");
                $stmt->bindValue(':username', $username, SQLITE3_TEXT);
                $stmt->bindValue(':firstName', $firstName, SQLITE3_TEXT);
                $stmt->bindValue(':ipAddress', $ipAddress, SQLITE3_TEXT);
                $stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
                $stmt->execute();
            } else {
                // Создаем нового пользователя с языком по умолчанию
                $stmt = $db->prepare("INSERT INTO users 
                                      (telegram_id, username, first_name, ip_address, created_at) 
                                      VALUES (:userId, :username, :firstName, :ipAddress, CURRENT_TIMESTAMP)");
                $stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
                $stmt->bindValue(':username', $username, SQLITE3_TEXT);
                $stmt->bindValue(':firstName', $firstName, SQLITE3_TEXT);
                $stmt->bindValue(':ipAddress', $ipAddress, SQLITE3_TEXT);
                $stmt->execute();
            }
            
            // Обработка реферала
            if (strpos($text, '/start ref_') === 0) {
                $referrerId = intval(str_replace('/start ref_', '', $text));
                
                if ($referrerId && $referrerId !== $userId) {
                    // Проверяем, не является ли уже рефералом
                        $stmt = $db->prepare("SELECT id FROM referrals WHERE referred_id = :userId");
                        $stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
                        $result = $stmt->execute();
                        $existingRef = $result->fetchArray();
                    
                    if (!$existingRef) {
                        // Обрабатываем реферальную награду через API
                        $apiUrl = APP_URL . '/api.php';
                        $postData = json_encode([
                            'referrerId' => $referrerId,
                            'newUserId' => $userId
                        ]);
                        
                        $context = stream_context_create([
                            'http' => [
                                'method' => 'POST',
                                'header' => 'Content-Type: application/json',
                                'content' => $postData
                            ]
                        ]);
                        
                        $response = file_get_contents($apiUrl . '?action=process_referral', false, $context);
                        $result = json_decode($response, true);
                        
                        if ($result && $result['success']) {
                            // Уведомляем реферера
                            $rewardText = $result['reward'] == 1000 ? 'первого друга' : 'нового друга';
                            $referrerMessage = "🎉 Табриклаймиз! Дўстингиз ботга қўшилди!\n\n" .
                                            "💰 Сиз {$result['reward']} сўм олдингиз $rewardText таклифи учун";
                            
                            $referrerUrl = $url = "https://api.telegram.org/bot" . BOT_TOKEN . "/sendMessage";
                            $referrerData = http_build_query([
                                'chat_id' => $referrerId,
                                'text' => $referrerMessage
                            ]);
                            
                            $referrerContext = stream_context_create([
                                'http' => [
                                    'method' => 'POST',
                                    'header' => 'Content-Type: application/x-www-form-urlencoded',
                                    'content' => $referrerData
                                ]
                            ]);
                            
                            file_get_contents($referrerUrl, false, $referrerContext);
                        }
                    }
                }
            }
            
            $keyboard = [
                'inline_keyboard' => [[
                    ['text' => '🌐 Веб-иловани очиш', 'web_app' => ['url' => WEBAPP_URL]]
                ]]
            ];
            
            $welcomeText = "🎮 " . APP_SHORT_NAME . " га хуш келибсиз!\n\n" .
                           "🖥️ Бизда 30 та замонавий ўйин компютерлар мавжуд\n" .
                           "🎯 3 та ихтисослаштирилган зал\n" .
                           "💰 Баллар ва бонуслар тизими\n" .
                           "📱 Қулай онлайн банд қилиш\n\n" .
                           "Веб-иловани очиш учун қуйидаги тугмани босинг:";
            
            // Отправляем сообщение
            $url = "https://api.telegram.org/bot" . BOT_TOKEN . "/sendMessage";
            $data = [
                'chat_id' => $chatId,
                'text' => $welcomeText,
                'reply_markup' => json_encode($keyboard)
            ];
            
            $context = stream_context_create([
                'http' => [
                    'method' => 'POST',
                    'header' => 'Content-Type: application/x-www-form-urlencoded',
                    'content' => http_build_query($data)
                ]
            ]);
            
            file_get_contents($url, false, $context);
            $db->close();
        }
        
        if ($text === '/admin' && in_array($userId, $ADMINS)) {
            $keyboard = [
                'inline_keyboard' => [
                    [['text' => '🖥️ ПК бошкаруви', 'callback_data' => 'admin_computers']],
                    [['text' => '📝 Бронлар', 'callback_data' => 'admin_bookings']],
                    [['text' => '👥 Фойдаланувчилар', 'callback_data' => 'admin_users']],
                    [['text' => '🎯 Вазифалар', 'callback_data' => 'admin_tasks']],
                    [['text' => '💰 Баллар', 'callback_data' => 'admin_points']],
                    [['text' => '🌐 Веб-админ очиш', 'url' => ADMIN_PANEL_URL]]
                ]
            ];
            
            $url = "https://api.telegram.org/bot" . BOT_TOKEN . "/sendMessage";
            $data = http_build_query([
                'chat_id' => $chatId,
                'text' => '⚙️ Админ панель',
                'reply_markup' => json_encode($keyboard)
            ]);
            
            $context = stream_context_create([
                'http' => [
                    'method' => 'POST',
                    'header' => 'Content-Type: application/x-www-form-urlencoded',
                    'content' => $data
                ]
            ]);
            
            file_get_contents($url, false, $context);
        }
    }
    
    // ЕДИНЫЙ блок обработки callback'ов
    if (isset($update['callback_query'])) {
        $callbackData = $update['callback_query']['data'];
        $chatId = $update['callback_query']['message']['chat']['id'];
        $messageId = $update['callback_query']['message']['message_id'];
        $userId = $update['callback_query']['from']['id'];
        
        // Подключаемся к БД
        $db = new SQLite3($dbFile);

        // Обработка подтверждения бронирования
        if (strpos($callbackData, 'confirm_booking_') === 0) {
            $bookingId = str_replace('confirm_booking_', '', $callbackData);
            
            // Получаем данные бронирования
            $stmt = $db->prepare("SELECT * FROM bookings WHERE id = :bookingId");
            $stmt->bindValue(':bookingId', $bookingId, SQLITE3_INTEGER);
            $result = $stmt->execute();
            $booking = $result->fetchArray(SQLITE3_ASSOC);
            
            if ($booking) {
                // Для бронирования
                $stmt = $db->prepare("UPDATE bookings SET status = :status WHERE id = :bookingId");
                $stmt->bindValue(':status', 'confirmed', SQLITE3_TEXT);
                $stmt->bindValue(':bookingId', $bookingId, SQLITE3_INTEGER);
                $stmt->execute();
                
                // Для компьютера
                $stmt = $db->prepare("UPDATE computers SET status = :status WHERE id = :computerId");
                $stmt->bindValue(':status', 'booked', SQLITE3_TEXT);
                $stmt->bindValue(':computerId', $booking['computer_id'], SQLITE3_INTEGER);
                $stmt->execute();
                
                // Отправляем уведомление клиенту
                $clientMessage = "✅ Банд қилиш сўровингиз тасдиқланди!\n\n" .
                               "🖥️ ПК №{$booking['computer_id']}\n" .
                               "📞 Администратор қўнғироғини кутинг";
                
                $url = "https://api.telegram.org/bot" . BOT_TOKEN . "/sendMessage";
                $data = http_build_query([
                    'chat_id' => $booking['user_id'],
                    'text' => $clientMessage
                ]);
                
                $context = stream_context_create([
                    'http' => [
                        'method' => 'POST',
                        'header' => 'Content-Type: application/x-www-form-urlencoded',
                        'content' => $data
                    ]
                ]);
                
                file_get_contents($url, false, $context);
                
                // Обновляем сообщение админа
                $newText = "✅ ТАСДИҚЛАНДИ\n\n" .
                          "👤 Клиент: {$booking['user_name']}\n" .
                          "📞 Телефон: {$booking['user_phone']}\n" .
                          "🖥️ ПК №{$booking['computer_id']}\n" .
                          "🆔 ID: {$bookingId}";
                
                $editUrl = $botUrl . "/editMessageText";
                $editData = http_build_query([
                    'chat_id' => $chatId,
                    'message_id' => $messageId,
                    'text' => $newText
                ]);
                
                $editContext = stream_context_create([
                    'http' => [
                        'method' => 'POST',
                        'header' => 'Content-Type: application/x-www-form-urlencoded',
                        'content' => $editData
                    ]
                ]);
                
                file_get_contents($editUrl, false, $editContext);
            }
        }
        // Обработка отклонения бронирования
        elseif (strpos($callbackData, 'reject_booking_') === 0) {
            $bookingId = str_replace('reject_booking_', '', $callbackData);
            
            // Получаем данные бронирования
            $booking = $db->querySingle("SELECT * FROM bookings WHERE id = " . intval($bookingId), true);
            
            if ($booking) {
                // Обновляем статус бронирования
                $db->exec("UPDATE bookings SET status = 'rejected' WHERE id = " . intval($bookingId));
                
                // Отправляем уведомление клиенту
                $clientMessage = "❌ Сизнинг банд қилиш сўровингиз рад қилинди\n\n" .
                               "🖥️ ПК №{$booking['computer_id']}\n" .
                               "📝 Бошқа ПК банд қилиб кўринг";
                
                $url = "https://api.telegram.org/bot" . BOT_TOKEN . "/sendMessage";
                $data = http_build_query([
                    'chat_id' => $booking['user_id'],
                    'text' => $clientMessage
                ]);
                
                $context = stream_context_create([
                    'http' => [
                        'method' => 'POST',
                        'header' => 'Content-Type: application/x-www-form-urlencoded',
                        'content' => $data
                    ]
                ]);
                
                file_get_contents($url, false, $context);
                
                // Обновляем сообщение админа
                $newText = "❌ РАД ҚИЛИНДИ\n\n" .
                          "👤 Клиент: {$booking['user_name']}\n" .
                          "📞 Телефон: {$booking['user_phone']}\n" .
                          "🖥️ ПК №{$booking['computer_id']}\n" .
                          "🆔 ID: {$bookingId}";
                
                $editUrl = $botUrl . "/editMessageText";
                $editData = http_build_query([
                    'chat_id' => $chatId,
                    'message_id' => $messageId,
                    'text' => $newText
                ]);
                
                $editContext = stream_context_create([
                    'http' => [
                        'method' => 'POST',
                        'header' => 'Content-Type: application/x-www-form-urlencoded',
                        'content' => $editData
                    ]
                ]);
                
                file_get_contents($editUrl, false, $editContext);
            }
        }
        // Обработка других админских callback'ов
        elseif (in_array($userId, $ADMINS) && !strpos($callbackData, '_booking_') && !strpos($callbackData, 'lang_')) {
            $url = "https://api.telegram.org/bot" . BOT_TOKEN . "/answerCallbackQuery";
            $data = http_build_query([
                'callback_query_id' => $update['callback_query']['id'],
                'text' => 'Функция в разработке. Используйте веб-админку.'
            ]);
            
            $context = stream_context_create([
                'http' => [
                    'method' => 'POST',
                    'header' => 'Content-Type: application/x-www-form-urlencoded',
                    'content' => $data
                ]
            ]);
            
            file_get_contents($url, false, $context);
        }
        
        // Отвечаем на callback запрос
        $answerUrl = "https://api.telegram.org/bot" . BOT_TOKEN . "/answerCallbackQuery";
        $answerData = http_build_query([
            'callback_query_id' => $update['callback_query']['id']
        ]);
        
        $answerContext = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => 'Content-Type: application/x-www-form-urlencoded',
                'content' => $answerData
            ]
        ]);
        
        file_get_contents($answerUrl, false, $answerContext);
    }
    exit;

// Проверка админки
if (isset($_GET['admin'])) {
    header('Location: new.html');
    exit;
}

// УПРОЩЕННАЯ И НАДЕЖНАЯ ПРОВЕРКА ДОСТУПА ИЗ TELEGRAM
$botToken = BOT_TOKEN;

// Получаем все возможные данные инициализации
$initData = '';
$possibleSources = [
    $_SERVER['HTTP_X_TELEGRAM_INIT_DATA'] ?? '',
    $_GET['tgWebAppData'] ?? '',
    $_GET['initData'] ?? '',
    $_GET['_auth'] ?? ''
];

foreach ($possibleSources as $source) {
    if (!empty($source)) {
        $initData = $source;
        break;
    }
}
// ДОБАВИТЬ ВАЛИДАЦИЮ:
$isValidTelegramData = false;
if (!empty($initData)) {
    // Проверяем подпись данных от Telegram
    $isValidTelegramData = validateTelegramWebApp($initData, BOT_TOKEN);
}

// Анализируем окружение
$userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
$referer = $_SERVER['HTTP_REFERER'] ?? '';
$host = $_SERVER['HTTP_HOST'] ?? '';

$isTelegramAccess = false;

// Проверяем основные признаки Telegram
if (
    // 1. Есть initData (основной признак)
    !empty($initData) ||
    
    // 2. User-Agent содержит Telegram
    (stripos($userAgent, 'telegram') !== false) ||
    
    // 3. Специальные заголовки Telegram
    isset($_SERVER['HTTP_TG_INIT_DATA']) ||
    isset($_SERVER['HTTP_X_TELEGRAM_INIT_DATA']) ||
    
    // 4. Referer от Telegram
    (stripos($referer, 't.me/') !== false) ||
    (stripos($referer, 'telegram.org') !== false) ||
    
    // 5. Для отладки (временно)
    isset($_GET['tg']) ||
    
    // 6. localhost для разработки
    in_array($host, ['localhost', '127.0.0.1']) ||
    strpos($host, 'localhost:') === 0
) {
    $isTelegramAccess = true;
}

// Дополнительная проверка для мобильных Telegram клиентов
if (!$isTelegramAccess && !empty($userAgent)) {
    $mobilePatterns = [
        'TelegramBot',
        'Telegram/',
        'TGWebApp',
        'WebAppInterface'
    ];
    
    foreach ($mobilePatterns as $pattern) {
        if (stripos($userAgent, $pattern) !== false) {
            $isTelegramAccess = true;
            break;
        }
    }
}
?>
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="Content-Security-Policy" content="
        default-src 'self' https://telegram.org https://api.telegram.org;
        script-src 'self' 'unsafe-inline' https://telegram.org;
        style-src 'self' 'unsafe-inline' https://fonts.googleapis.com;
        img-src 'self' data: https:;
        font-src 'self' https://fonts.gstatic.com;
        connect-src 'self' https://api.telegram.org;
        media-src 'self';
    ">
    <title>Access restricted - <?= htmlspecialchars(APP_SHORT_NAME, ENT_QUOTES, 'UTF-8') ?></title>
    <style>
        body {
            font-family: 'Exo 2', sans-serif;
            background: linear-gradient(135deg, #0A0A0B 0%, #1A1A2E 50%, #0A0A0B 100%);
            color: #FFFFFF;
            margin: 0;
            padding: 20px;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }
            
        .container {
            text-align: center;
            max-width: 400px;
            background: rgba(255, 255, 255, 0.08);
            backdrop-filter: blur(20px);
            border: 1px solid rgba(255, 255, 255, 0.12);
            border-radius: 24px;
            padding: 40px 30px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.3);
        }
            
        .icon { font-size: 64px; margin-bottom: 20px; }
        .title {
            font-size: 24px;
            font-weight: bold;
            margin-bottom: 15px;
            background: linear-gradient(135deg, #00d4ff, #00b0f0);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }
        .message {
            font-size: 16px;
            line-height: 1.5;
            color: #B0B0B0;
            margin-bottom: 30px;
        }
        .telegram-link {
            display: inline-block;
            background: linear-gradient(135deg, #0088cc, #006699);
            color: white;
            padding: 15px 30px;
            border-radius: 16px;
            text-decoration: none;
            font-weight: bold;
            transition: all 0.3s ease;
        }
        .info {
            margin-top: 30px;
            font-size: 14px;
            color: #888;
            border-top: 1px solid rgba(255, 255, 255, 0.1);
            padding-top: 20px;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="icon">🚫</div>
        <h1 class="title">Доступ ограничен</h1>
        <p class="message">
            Это мини-приложение работает только через Telegram.<br>
            Для доступа откройте нашего бота в Telegram.
        </p>
        <a href="https://t.me/asuscs_bot" class="telegram-link">
            📱 Открыть бота в Telegram
        </a>
        <div class="info">
            🎮 <?= htmlspecialchars(APP_SHORT_NAME, ENT_QUOTES, 'UTF-8') ?><br>
            Бронирование компьютеров • Система баллов • Задания
        </div>
    </div>
</body>
</html>
<?php
exit;
}
// Если дошли до этой точки - доступ разрешен
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="Content-Security-Policy" content="
        default-src 'self' https://telegram.org https://api.telegram.org;
        script-src 'self' 'unsafe-inline' https://telegram.org;
        style-src 'self' 'unsafe-inline' https://fonts.googleapis.com;
        img-src 'self' data: https:;
        font-src 'self' https://fonts.gstatic.com;
        connect-src 'self' https://api.telegram.org;
        media-src 'self';
    ">
    <title>🎮 <?= htmlspecialchars(APP_SHORT_NAME, ENT_QUOTES, 'UTF-8') ?></title>
    <script src="https://telegram.org/js/telegram-web-app.js"></script>
    <style>
    @import url('https://fonts.googleapis.com/css2?family=Exo+2:wght@300;400;500;600;700;800;900&display=swap');
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            -webkit-tap-highlight-color: transparent;
        }

        :root {
            --neon-purple: #a855f7;
            --neon-cyan: #06b6d4;
            --neon-pink: #ec4899;
            --neon-green: #10b981;
            --neon-orange: #f59e0b;
            --gaming-bg: #0a0a14;
            --gaming-card: #13132b;
        }

        body {
            font-family: 'Exo 2', sans-serif;
            background: linear-gradient(135deg, #0a0a14 0%, #0f0f23 50%, #0a0a14 100%);
            color: #ffffff;
            min-height: 100vh;
            position: relative;
            overflow-x: hidden;
        }

        /* Animated grid background */
        body::before {
            content: '';
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-image: 
                linear-gradient(rgba(168, 85, 247, 0.1) 1px, transparent 1px),
                linear-gradient(90deg, rgba(168, 85, 247, 0.1) 1px, transparent 1px);
            background-size: 50px 50px;
            z-index: -1;
            animation: grid-move 20s linear infinite;
        }

        @keyframes grid-move {
            0% { transform: translate(0, 0); }
            100% { transform: translate(50px, 50px); }
        }

        /* Glowing particles */
        body::after {
            content: '';
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-image: 
                radial-gradient(circle, rgba(168, 85, 247, 0.3) 2px, transparent 2px),
                radial-gradient(circle, rgba(6, 182, 212, 0.3) 1px, transparent 1px),
                radial-gradient(circle, rgba(236, 72, 153, 0.3) 1.5px, transparent 1.5px);
            background-size: 200px 200px, 150px 150px, 250px 250px;
            background-position: 0 0, 40px 60px, 130px 270px;
            z-index: -1;
            animation: particles-float 15s ease-in-out infinite;
        }

        @keyframes particles-float {
            0%, 100% { transform: translateY(0); opacity: 0.6; }
            50% { transform: translateY(-20px); opacity: 0.8; }
        }

        /* Fixed buttons */
        .points-button, .profile-button, .notifications-button {
            position: fixed;
            top: 12px;
            z-index: 1000;
            background: linear-gradient(135deg, rgba(168, 85, 247, 0.3), rgba(236, 72, 153, 0.3));
            backdrop-filter: blur(20px);
            border: 2px solid rgba(168, 85, 247, 0.5);
            color: white;
            padding: 10px 16px;
            border-radius: 10px;
            font-weight: 700;
            font-size: 13px;
            font-family: 'Exo 2', sans-serif;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 6px;
            box-shadow: 0 0 20px rgba(168, 85, 247, 0.4);
        }

        .points-button:hover, .profile-button:hover, .notifications-button:hover {
            transform: translateY(-2px);
            box-shadow: 0 0 30px rgba(168, 85, 247, 0.8), 0 5px 25px rgba(0, 0, 0, 0.3);
            border-color: rgba(168, 85, 247, 0.8);
        }
        
        .points-button { 
            right: 12px;
            max-width: 140px;
            white-space: nowrap;
        }
        
        .profile-button { 
            left: 12px;
            max-width: 120px;
        }
        
        .notifications-button {
            left: 135px;
            padding: 10px;
            width: 42px;
            height: 42px;
            border-radius: 10px;
            justify-content: center;
        }

        .notifications-badge {
            position: absolute;
            top: -3px;
            right: -3px;
            background: linear-gradient(135deg, #ff3b30, #ff6b6b);
            color: white;
            border-radius: 50%;
            width: 16px;
            height: 16px;
            font-size: 10px;
            display: none;
            align-items: center;
            justify-content: center;
            font-weight: 800;
            box-shadow: 0 0 10px rgba(255, 59, 48, 0.8);
        }

        /* Language switcher */
        .language-switcher {
            position: fixed;
            top: 60px;
            right: 12px;
            z-index: 1001;
        }

        .lang-toggle {
            display: flex;
            background: rgba(19, 19, 43, 0.8);
            backdrop-filter: blur(20px);
            border: 2px solid rgba(168, 85, 247, 0.3);
            border-radius: 10px;
            padding: 3px;
            gap: 3px;
        }

        .lang-btn {
            padding: 6px 12px;
            background: transparent;
            border: none;
            color: rgba(255, 255, 255, 0.6);
            font-weight: 600;
            font-size: 12px;
            font-family: 'Exo 2', sans-serif;
            cursor: pointer;
            border-radius: 7px;
            transition: all 0.3s ease;
        }

        .lang-btn.active {
            background: linear-gradient(135deg, var(--neon-purple), var(--neon-pink));
            color: white;
            box-shadow: 0 0 15px rgba(168, 85, 247, 0.6);
        }

        /* Header */
        .header {
            text-align: center;
            padding: 130px 20px 50px;
            position: relative;
        }

        .logo {
            font-family: 'Exo 2', sans-serif;
            font-size: 3.5rem;
            font-weight: 900;
            background: linear-gradient(135deg, var(--neon-purple), var(--neon-cyan), var(--neon-pink));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            text-shadow: 0 0 80px rgba(168, 85, 247, 0.8);
            animation: glow-pulse 3s ease-in-out infinite;
            margin-bottom: 15px;
            letter-spacing: 4px;
        }

        @keyframes glow-pulse {
            0%, 100% { filter: drop-shadow(0 0 20px rgba(168, 85, 247, 0.8)); }
            50% { filter: drop-shadow(0 0 40px rgba(236, 72, 153, 1)); }
        }

        .subtitle {
            font-size: 1.3rem;
            font-family: 'Exo 2', sans-serif;
            color: var(--neon-cyan);
            text-transform: uppercase;
            letter-spacing: 5px;
            text-shadow: 0 0 20px rgba(6, 182, 212, 0.8);
        }

        /* Gallery */
        .gallery {
            margin: 50px 0;
            overflow: hidden;
            position: relative;
        }

        .gallery::before, .gallery::after {
            content: '';
            position: absolute;
            top: 0;
            width: 150px;
            height: 100%;
            z-index: 10;
            pointer-events: none;
        }

        .gallery::before {
            left: 0;
            background: linear-gradient(90deg, #0a0a14 0%, transparent 100%);
        }

        .gallery::after {
            right: 0;
            background: linear-gradient(90deg, transparent 0%, #0a0a14 100%);
        }

        .gallery-container {
            display: flex;
            gap: 20px;
            padding: 24px 0;
            animation: slide 20s linear infinite;
        }
        
        @keyframes slide {
            0% { transform: translateX(0); }
            100% { transform: translateX(-50%); }
        }

        .gallery-container:hover {
            animation-play-state: paused;
        }

        .gallery-item {
            min-width: 350px;
            height: 250px;
            background: linear-gradient(135deg, rgba(168, 85, 247, 0.2), rgba(236, 72, 153, 0.2));
            border-radius: 20px;
            overflow: hidden;
            position: relative;
            border: 2px solid rgba(168, 85, 247, 0.3);
            transition: all 0.4s ease;
        }

        .gallery-item:hover {
            transform: translateY(-10px) scale(1.05);
            border-color: rgba(168, 85, 247, 0.8);
            box-shadow: 0 0 40px rgba(168, 85, 247, 0.6);
        }

        .gallery-item img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            transition: transform 0.4s ease;
        }

        .gallery-item:hover img {
            transform: scale(1.1);
        }

        /* Halls section */
        .halls-section {
            max-width: 1200px;
            margin: 80px auto;
            padding: 0 20px;
        }

        .section-title {
            font-family: 'Exo 2', sans-serif;
            font-size: 2.5rem;
            text-align: center;
            margin-bottom: 50px;
            background: linear-gradient(135deg, var(--neon-purple), var(--neon-pink));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            text-shadow: 0 0 40px rgba(168, 85, 247, 0.6);
        }

        .hall-card {
            background: linear-gradient(135deg, rgba(19, 19, 43, 0.9), rgba(30, 20, 60, 0.7));
            border: 2px solid rgba(168, 85, 247, 0.4);
            border-radius: 20px;
            padding: 30px;
            margin-bottom: 20px;
            position: relative;
            overflow: hidden;
            transition: all 0.4s ease;
            backdrop-filter: blur(20px);
        }

        .hall-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(168, 85, 247, 0.2), transparent);
            transition: left 0.6s ease;
        }

        .hall-card:hover::before {
            left: 100%;
        }

        .hall-card:hover {
            transform: translateX(10px);
            border-color: rgba(168, 85, 247, 0.8);
            box-shadow: 0 0 40px rgba(168, 85, 247, 0.5), inset 0 0 20px rgba(168, 85, 247, 0.1);
        }

        .hall-title {
            font-family: 'Exo 2', sans-serif;
            font-size: 1.5rem;
            margin-bottom: 15px;
            color: var(--neon-cyan);
            text-shadow: 0 0 15px rgba(6, 182, 212, 0.8);
        }

        .hall-description {
            color: rgba(255, 255, 255, 0.8);
            font-size: 1.1rem;
            line-height: 1.6;
        }

        /* Prices */
        .prices-section {
            max-width: 1000px;
            margin: 80px auto;
            padding: 0 20px;
        }

        .price-card {
            background: linear-gradient(135deg, rgba(19, 19, 43, 0.9), rgba(30, 20, 60, 0.7));
            border: 2px solid rgba(6, 182, 212, 0.4);
            border-radius: 20px;
            padding: 25px 30px;
            margin-bottom: 15px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            transition: all 0.3s ease;
        }

        .price-card:hover {
            transform: translateX(15px);
            border-color: rgba(6, 182, 212, 0.8);
            box-shadow: 0 0 30px rgba(6, 182, 212, 0.5);
        }

        .price-time {
            font-size: 1.2rem;
            font-weight: 600;
        }

        .price-amount {
            font-family: 'Exo 2', sans-serif;
            font-size: 1.5rem;
            font-weight: 700;
            background: linear-gradient(135deg, var(--neon-cyan), var(--neon-green));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            text-shadow: 0 0 20px rgba(6, 182, 212, 0.8);
        }

        /* Leaderboard */
        .leaderboard-section {
            max-width: 1000px;
            margin: 80px auto;
            padding: 0 20px;
        }

        .leaderboard {
            background: linear-gradient(135deg, rgba(19, 19, 43, 0.9), rgba(30, 20, 60, 0.7));
            border: 2px solid rgba(255, 215, 0, 0.5);
            border-radius: 20px;
            padding: 40px 30px;
            backdrop-filter: blur(20px);
        }

        .leaderboard-title {
            font-family: 'Exo 2', sans-serif;
            font-size: 2rem;
            text-align: center;
            margin-bottom: 30px;
            color: #ffd700;
            text-shadow: 0 0 30px rgba(255, 215, 0, 0.8);
        }

        .leader-item {
            display: flex;
            align-items: center;
            padding: 15px 20px;
            margin-bottom: 10px;
            background: rgba(255, 255, 255, 0.05);
            border-radius: 15px;
            border: 1px solid transparent;
            transition: all 0.3s ease;
            gap: 10px;
        }

        .leader-item:hover {
            transform: translateX(10px);
            background: rgba(255, 255, 255, 0.1);
            border-color: rgba(168, 85, 247, 0.5);
        }

        .leader-item.top-1 {
            background: linear-gradient(135deg, rgba(255, 215, 0, 0.3), rgba(255, 193, 7, 0.2));
            border: 2px solid rgba(255, 215, 0, 0.6);
        }

        .leader-item.top-2 {
            background: linear-gradient(135deg, rgba(192, 192, 192, 0.3), rgba(169, 169, 169, 0.2));
            border: 2px solid rgba(192, 192, 192, 0.6);
        }

        .leader-item.top-3 {
            background: linear-gradient(135deg, rgba(205, 127, 50, 0.3), rgba(184, 115, 51, 0.2));
            border: 2px solid rgba(205, 127, 50, 0.6);
        }

        .leader-rank {
            font-family: 'Exo 2', sans-serif;
            font-size: 1.5rem;
            font-weight: 900;
            min-width: 40px;
            max-width: 40px;
            text-align: center;
            flex-shrink: 0;
        }

        .leader-item.top-1 .leader-rank {
            color: #ffd700;
            text-shadow: 0 0 20px rgba(255, 215, 0, 1);
        }

        .leader-item.top-2 .leader-rank {
            color: #e0e0e0;
            text-shadow: 0 0 20px rgba(192, 192, 192, 1);
        }

        .leader-item.top-3 .leader-rank {
            color: #cd7f32;
            text-shadow: 0 0 20px rgba(205, 127, 50, 1);
        }

        .leader-name {
            flex: 1;
            font-size: 1rem;
            font-weight: 600;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            min-width: 0;
        }

        .leader-points {
            font-family: 'Exo 2', sans-serif;
            font-weight: 700;
            color: var(--neon-green);
            white-space: nowrap;
            flex-shrink: 0;
            min-width: 90px;
            text-align: right;
            font-size: 0.95rem;
        }

        /* Floating book button */
        .floating-book-btn {
            position: fixed;
            bottom: 30px;
            left: 50%;
            transform: translateX(-50%);
            background: linear-gradient(135deg, rgba(16, 185, 129, 0.3), rgba(6, 182, 212, 0.3));
            backdrop-filter: blur(20px);
            border: 3px solid rgba(16, 185, 129, 0.6);
            color: white;
            padding: 18px 50px;
            border-radius: 50px;
            font-family: 'Exo 2', sans-serif;
            font-size: 1.2rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 3px;
            cursor: pointer;
            z-index: 1000;
            transition: all 0.4s ease;
            box-shadow: 0 0 40px rgba(16, 185, 129, 0.6);
            animation: float-button 3s ease-in-out infinite;
            text-decoration: none;
            display: inline-block;
        }

        @keyframes float-button {
            0%, 100% { transform: translateX(-50%) translateY(0); }
            50% { transform: translateX(-50%) translateY(-10px); }
        }

        .floating-book-btn:hover {
            transform: translateX(-50%) scale(1.1);
            box-shadow: 0 0 60px rgba(16, 185, 129, 1), 0 0 100px rgba(6, 182, 212, 0.8);
            border-color: rgba(16, 185, 129, 1);
        }

        /* Preloader */
        .preloader {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.5);
            backdrop-filter: blur(5px);
            z-index: 99999;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: opacity 0.5s ease, visibility 0.5s ease;
        }
        
        .preloader.hidden {
            opacity: 0;
            visibility: hidden;
        }
        
        .accept_container {
            width: 720px;
            height: 312px;
            max-width: calc(100vw - 40px);
            display: flex;
            flex-direction: column;
            align-items: center;
            position: relative;
            background-color: #1a1a1a;
            border: solid 6px #30c237;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 0 60px rgba(48, 194, 55, 0.6);
        }
        .accept_container::before {
            content: '';
            position: absolute;
            top: 0;
            right: 100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, 
                transparent 0%, 
                rgba(106, 227, 100, 0.3) 50%, 
                transparent 100%
            );
            z-index: 2;
            animation: green-sweep 1.25s ease-in-out 4;
            pointer-events: none;
        }
        
        @keyframes green-sweep {
            0% {
                right: 100%;
            }
            100% {
                right: -100%;
            }
        }
        
        .overlayVid {
            z-index: 1;
            opacity: 0.5;
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        
        .mapImg {
            width: 100%;
            height: 100%;
            object-fit: cover;
            position: absolute;
            z-index: 0;
            filter: brightness(0.8);
        }
        
        .Tag {
            z-index: 5;
            font-family: 'Exo 2', sans-serif;
            font-size: 30px;
            font-weight: 800;
            color: #6ae364;
            margin-top: 34px;
            text-transform: uppercase;
            letter-spacing: 2px;
            text-shadow: 0 0 20px rgba(106, 227, 100, 0.8);
        }
        
        .Tag::after {
            content: '';
            height: 2px;
            width: 100%;
            border-radius: 3px;
            display: block;
            background-color: #6ae364;
            box-shadow: 0 0 10px rgba(106, 227, 100, 0.6);
            margin-top: 8px;
        }
        
        .mapText {
            z-index: 5;
            font-family: 'Exo 2', sans-serif;
            color: #8af784;
            margin-top: 14px;
            text-align: center;
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 8px;
        }
        
        .mapText span:first-child {
            font-size: 20px;
            filter: drop-shadow(0 0 8px rgba(138, 247, 132, 0.8));
        }
        
        .mapText span:last-child {
            font-size: 15px;
            font-weight: 600;
            letter-spacing: 1px;
        }
        
        .accept_button {
            cursor: pointer;
            position: relative;
            margin-top: 37px;
            z-index: 10;
            width: 227px;
            height: 90px;
            background: linear-gradient(180deg, #61d365 0%, #4dc250 100%);
            color: #277018;
            text-align: center;
            font-family: 'Exo 2', sans-serif;
            font-size: 40px;
            font-weight: 900;
            line-height: 90px;
            border-radius: 5px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.4), inset 0 2px 0 rgba(255, 255, 255, 0.2);
            text-shadow: 1px 1px 2px rgba(0, 0, 0, 0.5);
            transition: all 0.2s ease;
            letter-spacing: 2px;
        }
        
        .accept_button::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.3), transparent);
            transition: left 0.5s ease;
        }
        
        .accept_button:hover {
            background: linear-gradient(180deg, #6ee372 0%, #5dd361 100%);
            transform: translateY(-2px);
            box-shadow: 0 6px 25px rgba(48, 194, 55, 0.6), inset 0 2px 0 rgba(255, 255, 255, 0.3);
        }
        
        .accept_button:hover::before {
            left: 100%;
        }
        
        .accept_button:active {
            transform: translateY(0);
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.4), inset 0 2px 0 rgba(255, 255, 255, 0.2);
        }
        
        .countdown {
            z-index: 5;
            font-family: 'Exo 2', sans-serif;
            color: #63d45d;
            font-weight: 700;
            font-size: 20px;
            margin-top: 25px;
            text-shadow: 0 0 10px rgba(99, 212, 93, 0.8);
            letter-spacing: 2px;
        }

        /* Responsive */
        @media (max-width: 768px) {
            .logo { font-size: 2.5rem; }
            .subtitle { font-size: 1rem; letter-spacing: 3px; }
            .section-title { font-size: 1.8rem; }
            .gallery-item { 
                min-width: 250px; 
                height: 180px; 
            }
            
            .gallery-container {
                animation: slide 15s linear infinite;
            }
            .price-card { flex-direction: column; gap: 10px; text-align: center; }
            .floating-book-btn { padding: 15px 35px; font-size: 1rem; }
            
            .profile-button {
                max-width: 100px;
                font-size: 12px;
                padding: 8px 12px;
            }
            
            .notifications-button { 
                left: 100px;
                width: 38px;
                height: 38px;
            }
            
            .language-switcher {
                top: 70px;
                right: 12px;
            }
            .admin-button {
                top: 120px;
                right: 12px;
                padding: 8px 12px;
                font-size: 12px;
            }
            .header {
                padding: 120px 20px 50px;
            }
            
            .accept_container {
                width: calc(100vw - 40px);
                height: auto;
                min-height: 280px;
                padding: 20px 10px;
                border-width: 4px;
            }
            
            .overlayVid {
                width: 100%;
                height: 100%;
            }
            
            .Tag {
                font-size: 18px;
                margin-top: 20px;
            }
            
            .mapText {
                font-size: 12px;
                margin-top: 10px;
            }
            
            .mapText span:first-child {
                font-size: 16px;
            }
            
            .mapText span:last-child {
                font-size: 13px;
            }
            
            .accept_button {
                width: 180px;
                height: 60px;
                font-size: 28px;
                line-height: 60px;
                margin-top: 20px;
            }
            
            .countdown {
                font-size: 16px;
                margin-top: 15px;
            }
        }

        /* Contacts button */
        .contacts-btn {
            display: block;
            max-width: 600px;
            margin: 50px auto 120px;
            background: linear-gradient(135deg, rgba(236, 72, 153, 0.3), rgba(168, 85, 247, 0.3));
            backdrop-filter: blur(20px);
            border: 2px solid rgba(236, 72, 153, 0.5);
            color: white;
            padding: 20px;
            border-radius: 15px;
            font-family: 'Exo 2', sans-serif;
            font-size: 1.2rem;
            font-weight: 700;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s ease;
            text-decoration: none;
            box-shadow: 0 0 30px rgba(236, 72, 153, 0.5);
        }

        .contacts-btn:hover {
            transform: translateY(-5px);
            box-shadow: 0 0 50px rgba(236, 72, 153, 0.8);
            border-color: rgba(236, 72, 153, 0.8);
        }
        
        .admin-button {
            position: fixed;
            top: 117px;
            right: 12px;
            z-index: 1000;
            background: linear-gradient(135deg, rgba(239, 68, 68, 0.3), rgba(220, 38, 38, 0.3));
            backdrop-filter: blur(20px);
            border: 2px solid rgba(239, 68, 68, 0.5);
            color: white;
            padding: 10px 16px;
            border-radius: 10px;
            font-weight: 700;
            font-size: 13px;
            font-family: 'Exo 2', sans-serif;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 6px;
            box-shadow: 0 0 20px rgba(239, 68, 68, 0.4);
        }
        
        .admin-button:hover {
            transform: translateY(-2px);
            box-shadow: 0 0 30px rgba(239, 68, 68, 0.8), 0 5px 25px rgba(0, 0, 0, 0.3);
            border-color: rgba(239, 68, 68, 0.8);
        }
        
        .admin-button:active {
            transform: translateY(-1px);
        }
    </style>
<script>
const ADMIN_IDS = <?php echo $adminsJson; ?>;
</script>    
</head>
<body>
    <!-- Preloader -->
    <div class="preloader" id="preloader">
        <div class="accept_container">
            <video class="overlayVid" width="720" height="312" autoplay loop muted playsinline>
                <source src="https://voidtyphoon.co.uk/codepenassets/csgo_accept/overlay.webm" type="video/webm">
            </video>
            <img class="mapImg" src="/images/club11.jpg" onerror="this.style.display='none'" />
            <div class="Tag">ВАША ИГРА ГОТОВА!</div>
            <div class="mapText">
                <span>🎮</span>
                <span><?= htmlspecialchars(APP_SHORT_NAME, ENT_QUOTES, 'UTF-8') ?> • OS/Manager</span>
            </div>
            <div class="accept_button" onclick="acceptMatch()">ПРИНЯТЬ</div>
            <div class="countdown" id="cs2Timer">0:05</div>
        </div>
    </div>

    <!-- Fixed buttons -->
    <button class="points-button" onclick="openPoints()">
        💎 <span id="pointsAmount">0</span> сум
    </button>
    
    <button class="profile-button" onclick="openProfile()">
        <span id="profileRankDisplay">Профиль</span>
    </button>
    
    <button class="notifications-button" id="notificationsBtn" onclick="openNotifications()">
        🔔
        <span class="notifications-badge" id="notificationsBadge">0</span>
    </button>

    <!-- Language switcher -->
    <div class="language-switcher">
        <div class="lang-toggle">
            <button onclick="switchLanguage('ru')" class="lang-btn active" id="ru-btn">RU</button>
            <button onclick="switchLanguage('uz')" class="lang-btn" id="uz-btn">UZ</button>
        </div>
    </div>

    <!-- Header -->
    <div class="header">
        <div class="logo">🎮 <?= htmlspecialchars(APP_SHORT_NAME, ENT_QUOTES, 'UTF-8') ?></div>
        <div class="subtitle">GAME CLUB</div>
    </div>

    <!-- Gallery -->
    <div class="gallery">
        <div class="gallery-container">
            <div class="gallery-item">
                <img src="/images/club11.jpg" alt="Gaming Club 1" onerror="this.parentElement.innerHTML='<div style=\'display:flex;align-items:center;justify-content:center;height:100%;color:rgba(255,255,255,0.5);font-family:Exo 2;\'>CLUB PHOTO 1</div>'">
            </div>
            <div class="gallery-item">
                <img src="/images/club21.jpg" alt="Gaming Club 2" onerror="this.parentElement.innerHTML='<div style=\'display:flex;align-items:center;justify-content:center;height:100%;color:rgba(255,255,255,0.5);font-family:Exo 2;\'>CLUB PHOTO 2</div>'">
            </div>
            <div class="gallery-item">
                <img src="/images/club31.jpg" alt="Gaming Club 3" onerror="this.parentElement.innerHTML='<div style=\'display:flex;align-items:center;justify-content:center;height:100%;color:rgba(255,255,255,0.5);font-family:Exo 2;\'>CLUB PHOTO 3</div>'">
            </div>
            <div class="gallery-item">
                <img src="/images/club41.jpg" alt="Gaming Club 4" onerror="this.parentElement.innerHTML='<div style=\'display:flex;align-items:center;justify-content:center;height:100%;color:rgba(255,255,255,0.5);font-family:Exo 2;\'>CLUB PHOTO 4</div>'">
            </div>
            <div class="gallery-item">
                <img src="/images/club51.jpg" alt="Gaming Club 5" onerror="this.parentElement.innerHTML='<div style=\'display:flex;align-items:center;justify-content:center;height:100%;color:rgba(255,255,255,0.5);font-family:Exo 2;\'>CLUB PHOTO 5</div>'">
            </div>
            <div class="gallery-item">
                <img src="/images/club61.jpg" alt="Gaming Club 6" onerror="this.parentElement.innerHTML='<div style=\'display:flex;align-items:center;justify-content:center;height:100%;color:rgba(255,255,255,0.5);font-family:Exo 2;\'>CLUB PHOTO 6</div>'">
            </div>
            <!-- Duplicates for infinite scroll -->
            <div class="gallery-item">
                <img src="/images/club11.jpg" alt="Gaming Club 1" onerror="this.parentElement.innerHTML='<div style=\'display:flex;align-items:center;justify-content:center;height:100%;color:rgba(255,255,255,0.5);font-family:Exo 2;\'>CLUB PHOTO 1</div>'">
            </div>
            <div class="gallery-item">
                <img src="/images/club21.jpg" alt="Gaming Club 2" onerror="this.parentElement.innerHTML='<div style=\'display:flex;align-items:center;justify-content:center;height:100%;color:rgba(255,255,255,0.5);font-family:Exo 2;\'>CLUB PHOTO 2</div>'">
            </div>
        </div>
    </div>

    <!-- Halls Section -->
    <div class="halls-section">
        <h2 class="section-title">НАШИ ЗАЛЫ</h2>
        <div class="hall-card">
            <div class="hall-title">🎯 ЗАЛ 1 - ОНЛАЙН ИГРЫ</div>
            <div class="hall-description">10 мощных ПК для онлайн-игр: CS2, Valorant, Dota 2, LoL и др.</div>
        </div>
        <div class="hall-card">
            <div class="hall-title">⚔️ ЗАЛ 2 - СМЕШАННЫЙ</div>
            <div class="hall-description">5 ПК для онлайн-игр + 5 ПК для классического CS 1.6</div>
        </div>
        <div class="hall-card">
            <div class="hall-title">🎩 ЗАЛ 3 - ОФФЛАЙН</div>
            <div class="hall-description">10 ПК для оффлайн-игр и классических шутеров</div>
        </div>
    </div>

    <!-- Prices Section -->
    <div class="prices-section">
        <h2 class="section-title">💰 ТАРИФЫ</h2>
        <div class="price-card">
            <div class="price-time">☀️ Дневной (09:00 - 19:00)</div>
            <div class="price-amount">12 000 сум/час</div>
        </div>
        <div class="price-card">
            <div class="price-time">🌙 Вечерний (19:00 - 09:00)</div>
            <div class="price-amount">14 000 сум/час</div>
        </div>
        <div class="price-card">
            <div class="price-time">🌃 Ночной пакет (00:00 - 07:00)</div>
            <div class="price-amount">42 000 сум</div>
        </div>
    </div>

    <!-- Leaderboard Section -->
    <div class="leaderboard-section">
        <div class="leaderboard">
            <div class="leaderboard-title">🏆 ТОП ПОЛЬЗОВАТЕЛЕЙ</div>
            <div id="leaderboardList">
                <div style="text-align: center; color: rgba(255,255,255,0.5); padding: 20px;">⏳ Загрузка...</div>
            </div>
            <button class="show-more-btn" id="showMoreBtn" onclick="showMoreLeaders()" style="display: none; width: 100%; padding: 15px; margin-top: 20px; background: linear-gradient(135deg, var(--neon-purple), var(--neon-pink)); border: 2px solid rgba(168, 85, 247, 0.5); border-radius: 12px; color: white; font-family: 'Exo 2', sans-serif; font-weight: 700; cursor: pointer; transition: all 0.3s ease;">
                ПОКАЗАТЬ ЕЩЕ
            </button>
        </div>
    </div>

    <!-- Contacts Button -->
    <a href="contacts.html" class="contacts-btn">
        📞 КОНТАКТЫ
    </a>

    <!-- Floating Book Button -->
    <a href="booking.html" class="floating-book-btn">
        ЗАБРОНИРОВАТЬ
    </a>

    <script>
        let user = null;
        let lastKnownBalance = 0;
        let leaderboardOffset = 0;
        let leaderboardData = [];
        
        
        // Система уведомлений
        let userNotifications = [];
        let unreadNotificationsCount = 0;
        let cs2Audio = null;

        // Предзагружаем звук сразу при загрузке страницы
        (function preloadAudio() {
            cs2Audio = new Audio('/audio/cs2.mp3');
            cs2Audio.preload = 'auto';
            cs2Audio.volume = 0.5;
        })();
        
        // Затем в startCS2Timer используй предзагруженный файл:
        function startCS2Timer() {
            if (cs2TimerStarted) return;
            cs2TimerStarted = true;
            
            // Используем предзагруженный звук
            if (cs2Audio) {
                cs2Audio.currentTime = 0; // Сбрасываем на начало
                cs2Audio.play().catch(err => console.log('Автовоспроизведение заблокировано:', err));
            }
            
            let timeLeft = 5;
            const timerEl = document.getElementById('cs2Timer');
            
            if (timerEl) {
                timerEl.textContent = '0:05';
            }
            
            cs2TimerInterval = setInterval(() => {
                timeLeft--;
                if (timerEl) {
                    timerEl.textContent = `0:0${timeLeft}`;
                }
                
                if (timeLeft <= 0) {
                    clearInterval(cs2TimerInterval);
                    hidePreloader();
                }
            }, 1000);
        }
        
        // Инициализация Telegram WebApp
        window.Telegram.WebApp.ready();
        window.Telegram.WebApp.expand();
        
        const tgUser = window.Telegram.WebApp.initDataUnsafe.user;
        const initData = window.Telegram.WebApp.initData;
        
        // Добавляем заголовок Telegram ко всем API запросам
        if (tgUser && initData) {
            const originalFetch = window.fetch;
            window.fetch = function(url, options = {}) {
                if (url.includes('api.php')) {
                    options.headers = options.headers || {};
                    options.headers['X-Telegram-Init-Data'] = initData;
                }
                return originalFetch(url, options);
            };
            
            loadUserData();
            checkAdminRights();
        } else {
            window.location.href = 'https://t.me/asuscs_bot';
        }
    
        // Загрузка данных пользователя
        async function loadUserData() {
            try {
                const response = await fetch(`api.php?action=user_full_data&userId=${tgUser.id}&_t=${Date.now()}`);
                const data = await response.json();
                
                user = data.user;
                
                if (user && (user.points_registered === true || user.points_registered === 1 || user.points_registered === "1")) {
                    // Обновляем баланс
                    const newBalance = data.points;
                    if (lastKnownBalance > 0 && newBalance > lastKnownBalance) {
                        const earned = newBalance - lastKnownBalance;
                        showBalanceIncreaseNotification(earned);
                    }
                    
                    lastKnownBalance = newBalance;
                    document.getElementById('pointsAmount').textContent = newBalance.toLocaleString();
                    
                    // КРИТИЧНО: Сначала обновляем ранг в body
                    if (data.rank && data.rank.level) {
                        document.body.setAttribute('data-rank', data.rank.level);
                        console.log(`✅ Ранг применён на главной: ${data.rank.name} (уровень ${data.rank.level})`);
                    }
                    
                    // ЗАТЕМ обновляем отображение в UI
                    if (data.rank) {
                        // Обновляем отображение кнопки профиля
                        const profileDisplay = document.getElementById('profileRankDisplay');
                        if (profileDisplay) {
                            // Показываем имя пользователя, если есть, иначе "Профиль"
                            const displayName = user.name || user.first_name || 'Профиль';
                            profileDisplay.textContent = displayName;
                        }
                        
                        if (profileDisplay) profileDisplay.textContent = data.rank.name;
                        if (profileIcon) {
                            profileIcon.src = `images/icons/${data.rank.icon}`;
                            profileIcon.style.display = 'inline-block'; // Показываем иконку
                        }
                    }
                } else {
                    document.getElementById('pointsAmount').textContent = 'Активировать';
                    document.querySelector('.points-button').style.background = 'linear-gradient(45deg, #28a745, #20c997)';
                }
                
                loadLeaderboard();
            } catch (error) {
                console.error('Ошибка загрузки данных пользователя:', error);
            }
        }
        
        // Добавьте эту функцию для принудительного обновления ранга
        async function forceUpdateRank() {
            if (!tgUser) return;
            
            try {
                const response = await fetch(`api.php?action=user_rank&userId=${tgUser.id}`);
                const rankData = await response.json();
                
                if (rankData && rankData.rank) {
                    const currentLevel = document.body.getAttribute('data-rank');
                    const newLevel = rankData.rank.level.toString();
                    
                    if (currentLevel !== newLevel) {
                        document.body.setAttribute('data-rank', newLevel);
                        console.log(`🔄 Ранг принудительно обновлён: ${rankData.rank.name} (уровень ${newLevel})`);
                        
                        // Обновляем отображение
                        const profileDisplay = document.getElementById('profileRankDisplay');
                        const profileIcon = document.getElementById('profileRankIcon');
                        
                        if (profileDisplay) profileDisplay.textContent = rankData.rank.name;
                        if (profileIcon) profileIcon.src = `images/icons/${rankData.rank.icon}`;
                    }
                }
            } catch (error) {
                console.error('Ошибка принудительного обновления ранга:', error);
            }
        }
        
        // Проверяем, показывали ли уже прелоадер в этой сессии
        const hasSeenPreloader = sessionStorage.getItem('preloaderShown');
        
        window.addEventListener('load', function() {
            const preloader = document.getElementById('preloader');
            
            if (!preloader) return;
            
            // Если уже показывали - сразу скрываем
            if (hasSeenPreloader === 'true') {
                preloader.classList.add('hidden');
                setTimeout(() => {
                    if (preloader.parentNode) {
                        preloader.parentNode.removeChild(preloader);
                    }
                }, 100);
                return;
            }
            
            // Первый показ - запускаем таймер
            setTimeout(() => {
                if (!preloader.classList.contains('hidden')) {
                    startCS2Timer();
                    sessionStorage.setItem('preloaderShown', 'true');
                }
            }, 100);
        });
        
        // Также добавьте проверку при возврате на страницу
        document.addEventListener('visibilitychange', function() {
            if (!document.hidden && tgUser && user && user.points_registered) {
                setTimeout(() => {
                    loadUserData();
                    forceUpdateRank();
                }, 500);
            }
        });
        
        // Лидерборд
        async function loadLeaderboard(offset = 0) {
            try {
                const response = await fetch(`api.php?action=leaderboard&offset=${offset}&limit=5`);
                const data = await response.json();
                
                if (!data.success) return;
                
                if (offset === 0) {
                    leaderboardData = data.leaders || [];
                } else {
                    leaderboardData = [...leaderboardData, ...(data.leaders || [])];
                }
                
                renderLeaderboard();
                
                const showMoreBtn = document.getElementById('showMoreBtn');
                if (showMoreBtn) {
                    showMoreBtn.style.display = data.hasMore ? 'block' : 'none';
                }
            } catch (error) {
                const list = document.getElementById('leaderboardList');
                if (list) {
                    list.innerHTML = '<div style="text-align: center; color: #e74c3c;">Ошибка загрузки</div>';
                }
            }
        }
        
        function getRankByPoints(points) {
            if (points >= 1000000) return { name: 'Властелин Арены', icon: 'arena_lord.png', level: 10 };
            if (points >= 600000) return { name: 'Легенда', icon: 'legend.png', level: 9 };
            if (points >= 300000) return { name: 'Чемпион II', icon: 'champion2.png', level: 8 };
            if (points >= 150000) return { name: 'Чемпион I', icon: 'champion1.png', level: 7 };
            if (points >= 80000) return { name: 'Воин III', icon: 'warrior3.png', level: 6 };
            if (points >= 40000) return { name: 'Воин II', icon: 'warrior2.png', level: 5 };
            if (points >= 20000) return { name: 'Воин I', icon: 'warrior1.png', level: 4 };
            if (points >= 10000) return { name: 'Страж II', icon: 'guard2.png', level: 3 };
            if (points >= 5000) return { name: 'Страж I', icon: 'guard1.png', level: 2 };
            return { name: 'Новичок I', icon: 'novice.png', level: 1 };
        }
        
        
        function renderLeaderboard() {
            const list = document.getElementById('leaderboardList');
            if (!list) return;
            
            if (!leaderboardData || leaderboardData.length === 0) {
                list.innerHTML = '<div style="text-align: center; color: #a0a0a0; padding: 20px;">Пока нет данных</div>';
                return;
            }
            
            list.innerHTML = leaderboardData.map((leader, index) => {
                const rank = index + 1;
                let rankClass = '';
                
                if (rank === 1) rankClass = 'top-1';
                else if (rank === 2) rankClass = 'top-2';
                else if (rank === 3) rankClass = 'top-3';
                
                // Получаем ранг по баллам
                const userRank = getRankByPoints(leader.points || 0);
                
                return `
                    <div class="leader-item ${rankClass}">
                        <div class="leader-rank">${rank}</div>
                        <img src="images/icons/${userRank.icon}" alt="${userRank.name}" style="width: 32px; height: 32px; flex-shrink: 0; border-radius: 50%; box-shadow: 0 0 10px rgba(168, 85, 247, 0.5);" onerror="this.style.display='none'">
                        <div class="leader-name">${escapeHtml(leader.name || 'Пользователь')}</div>
                        <div class="leader-points">${(leader.points || 0).toLocaleString()} сум</div>
                    </div>
                `;
            }).join('');
        }
        
        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }
        
        function showMoreLeaders() {
            if (leaderboardData.length >= 10) {
                const showMoreBtn = document.getElementById('showMoreBtn');
                if (showMoreBtn) showMoreBtn.style.display = 'none';
                return;
            }
            
            leaderboardOffset += 5;
            loadLeaderboard(leaderboardOffset);
        }
        
        // Уведомления
        async function loadUserNotifications() {
            if (!tgUser) return;
            
            try {
                // ИЗМЕНЕНИЕ: Сначала загружаем ВСЕ уведомления с сервера (включая доставленные)
                const response = await fetch(`api.php?action=get_all_user_notifications&userId=${tgUser.id}`);
                const data = await response.json();
                
                // Очищаем локальные уведомления и загружаем с сервера
                userNotifications = [];
                unreadNotificationsCount = 0;
                
                if (data.notifications && data.notifications.length > 0) {
                    // Конвертируем серверные уведомления в локальный формат
                    userNotifications = data.notifications.map(notification => ({
                        id: notification.id, // Используем серверный ID как основной
                        serverId: notification.id,
                        type: notification.type,
                        message: notification.message,
                        time: new Date(notification.created_at).toLocaleString('ru-RU'),
                        timestamp: new Date(notification.created_at).getTime(),
                        read: notification.delivered === 1, // Если delivered = 1, значит прочитано
                        data: notification.data || {}
                    }));
                    
                    // Сортируем по времени создания (новые сначала)
                    userNotifications.sort((a, b) => b.timestamp - a.timestamp);
                    
                    // Считаем непрочитанные
                    unreadNotificationsCount = userNotifications.filter(n => !n.read).length;
                    
                    // Удаляем старые уведомления (старше 7 дней)
                    const sevenDaysAgo = Date.now() - (7 * 24 * 60 * 60 * 1000);
                    userNotifications = userNotifications.filter(n => n.timestamp > sevenDaysAgo);
                    
                    // Пересчитываем после фильтрации
                    unreadNotificationsCount = userNotifications.filter(n => !n.read).length;
                    
                    // Показываем анимацию если есть непрочитанные
                    if (unreadNotificationsCount > 0) {
                        const btn = document.getElementById('notificationsBtn');
                        if (btn) {
                            btn.classList.add('has-new');
                            setTimeout(() => btn.classList.remove('has-new'), 3000);
                        }
                    }
                }
                
                // Сохраняем в localStorage для офлайн доступа
                saveUserNotifications();
                updateNotificationsBadge();
                
            } catch (error) {
                // При ошибке сервера пытаемся загрузить из localStorage
                try {
                    const saved = localStorage.getItem('userNotifications');
                    const savedCount = localStorage.getItem('userUnreadCount');
                    
                    if (saved) {
                        userNotifications = JSON.parse(saved);
                        const sevenDaysAgo = Date.now() - (7 * 24 * 60 * 60 * 1000);
                        userNotifications = userNotifications.filter(n => n.timestamp > sevenDaysAgo);
                    } else {
                        userNotifications = [];
                    }
                    
                    unreadNotificationsCount = savedCount ? Math.min(parseInt(savedCount), userNotifications.length) : 0;
                } catch (localError) {
                    userNotifications = [];
                    unreadNotificationsCount = 0;
                }
                
                updateNotificationsBadge();
            }
        }
        
        function addUserNotification(type, message, data = {}) {
            const notification = {
                id: Date.now(),
                type: type,
                message: message,
                time: new Date().toLocaleString('ru-RU'),
                timestamp: Date.now(),
                read: false,
                data: data
            };
            
            userNotifications.unshift(notification);
            unreadNotificationsCount++;
            
            const sevenDaysAgo = Date.now() - (7 * 24 * 60 * 60 * 1000);
            userNotifications = userNotifications.filter(n => n.timestamp > sevenDaysAgo);
            
            saveUserNotifications();
            updateNotificationsBadge();
            
            const btn = document.getElementById('notificationsBtn');
            if (btn) {
                btn.classList.add('has-new');
                setTimeout(() => btn.classList.remove('has-new'), 3000);
            }
        }
        
        function saveUserNotifications() {
            try {
                localStorage.setItem('userNotifications', JSON.stringify(userNotifications));
                localStorage.setItem('userUnreadCount', unreadNotificationsCount.toString());
            } catch (error) {
                console.error('Ошибка сохранения уведомлений:', error);
            }
        }
        
        function updateNotificationsBadge() {
            const badge = document.getElementById('notificationsBadge');
            if (badge) {
                if (unreadNotificationsCount > 0) {
                    badge.textContent = unreadNotificationsCount > 99 ? '99+' : unreadNotificationsCount;
                    badge.style.display = 'flex';
                } else {
                    badge.style.display = 'none';
                }
            }
        }
        
        function openNotifications() {
            const modal = document.getElementById('notificationsModal');
            if (modal) {
                modal.style.display = 'block';
                renderUserNotifications();
                
                // Отмечаем все уведомления как прочитанные
                const unreadIds = userNotifications.filter(n => !n.read).map(n => n.serverId || n.id);
                
                if (unreadIds.length > 0 && tgUser) {
                    // Отправляем на сервер информацию о прочтении
                    fetch(`api.php?action=mark_notifications_read&userId=${tgUser.id}`, { 
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ notificationIds: unreadIds })
                    });
                }
                
                // Локально отмечаем как прочитанные
                unreadNotificationsCount = 0;
                userNotifications.forEach(n => n.read = true);
                saveUserNotifications();
                updateNotificationsBadge();
            }
        }
        
        function closeNotifications() {
            const modal = document.getElementById('notificationsModal');
            if (modal) modal.style.display = 'none';
        }
        
        function renderUserNotifications() {
            const container = document.getElementById('notificationsList');
            if (!container) return;
            
            if (userNotifications.length === 0) {
                container.innerHTML = '<p style="text-align: center; color: #666; padding: 20px;">Нет уведомлений</p>';
                return;
            }
            
            container.innerHTML = userNotifications.slice(0, 50).map(notification => `
                <div class="notification-item">
                    <div class="notification-time">${notification.time}</div>
                    <div class="notification-text">${notification.message}</div>
                </div>
            `).join('');
        }
        
        // Показ уведомления о начислении баллов
        function showBalanceIncreaseNotification(amount) {
            addUserNotification('balance_increase', `Начислено ${amount.toLocaleString()} сум!`);
            
            const notification = document.createElement('div');
            notification.style.cssText = `
                position: fixed;
                top: 20px;
                right: 20px;
                background: linear-gradient(45deg, #28a745, #20c997);
                color: white;
                padding: 15px 20px;
                border-radius: 12px;
                font-weight: bold;
                z-index: 10000;
                box-shadow: 0 4px 15px rgba(40, 167, 69, 0.4);
                transform: translateX(100%);
                transition: transform 0.3s ease;
            `;
            notification.textContent = `Начислено ${amount.toLocaleString()} сум!`;
            
            document.body.appendChild(notification);
            
            setTimeout(() => notification.style.transform = 'translateX(0)', 100);
            setTimeout(() => {
                notification.style.transform = 'translateX(100%)';
                setTimeout(() => {
                    if (notification.parentNode) {
                        notification.parentNode.removeChild(notification);
                    }
                }, 300);
            }, 3000);
        }
        
        // Основные функции
        function openPoints() {
            window.location.href = 'points.html';
        }
        
        function openProfile() {
            window.location.href = 'profile.html';
        }
        
        function checkAdminRights() {
            if (tgUser && ADMIN_IDS.includes(tgUser.id)) {
                const adminBtn = document.createElement('button');
                adminBtn.className = 'admin-button';
                adminBtn.textContent = 'Админ';
                adminBtn.onclick = () => window.location.href = 'new.html';
                document.body.appendChild(adminBtn);
            }
        }
        
        let cs2TimerInterval;
        let cs2TimerStarted = false;
        
        function startCS2Timer() {
            if (cs2TimerStarted) return;
            cs2TimerStarted = true;
            
            // Вибрация для мобильных
            if (navigator.vibrate) {
                navigator.vibrate([200, 100, 200]);
            }
            
            // Проигрываем звук
            const audio = new Audio('/audio/cs2.mp3');
            audio.volume = 0.5; // Громкость 50%
            audio.play().catch(err => console.log('Автовоспроизведение заблокировано:', err));
            
            let timeLeft = 5;
            const timerEl = document.getElementById('cs2Timer');
            
            if (timerEl) {
                timerEl.textContent = '0:05';
            }
            
            cs2TimerInterval = setInterval(() => {
                timeLeft--;
                if (timerEl) {
                    timerEl.textContent = `0:0${timeLeft}`;
                }
                
                if (timeLeft <= 0) {
                    clearInterval(cs2TimerInterval);
                    hidePreloader();
                }
            }, 1000);
        }
        
        function acceptMatch() {
            if (cs2TimerInterval) {
                clearInterval(cs2TimerInterval);
            }
            
            // Останавливаем музыку
            if (cs2Audio) {
                cs2Audio.pause();
                cs2Audio.currentTime = 0;
            }
            
            hidePreloader();
        }
        
        function hidePreloader() {
            const preloader = document.getElementById('preloader');
            if (preloader && !preloader.classList.contains('hidden')) {
                // Останавливаем музыку
                if (cs2Audio) {
                    cs2Audio.pause();
                    cs2Audio.currentTime = 0;
                }
                
                preloader.classList.add('hidden');
                sessionStorage.setItem('preloaderShown', 'true');
                setTimeout(() => {
                    if (preloader && preloader.parentNode) {
                        preloader.parentNode.removeChild(preloader);
                    }
                }, 500);
            }
        }
        
        
        function switchLanguage(lang) {
            const langToggle = document.querySelector('.lang-toggle');
            if (langToggle) langToggle.classList.add('switching');
            
            document.querySelectorAll('.lang-btn').forEach(btn => btn.classList.remove('active'));
            
            if (lang === 'ru') {
                document.getElementById('ru-btn')?.classList.add('active');
                langToggle?.setAttribute('data-active', 'ru');
            } else {
                document.getElementById('uz-btn')?.classList.add('active');
                langToggle?.setAttribute('data-active', 'uz');
            }
            
            setTimeout(() => {
                window.location.href = lang === 'uz' ? '/uz/' : '/';
            }, 400);
        }
        
        // Интервалы проверки
        setInterval(() => {
            if (tgUser && user && user.points_registered) {
                loadUserData();
            }
        }, 10000);
        
        setInterval(async () => {
            if (tgUser) {
                try {
                    const response = await fetch(`api.php?action=get_user_notifications&userId=${tgUser.id}`);
                    const data = await response.json();
                    
                    if (data.notifications && data.notifications.length > 0) {
                        loadUserNotifications();
                    }
                } catch (error) {
                    console.error('Ошибка проверки уведомлений:', error);
                }
            }
        }, 30000);
        
        document.addEventListener('visibilitychange', function() {
            if (!document.hidden && tgUser && user && user.points_registered) {
                setTimeout(() => loadUserData(), 1000);
            }
        });
        
        document.addEventListener('DOMContentLoaded', function() {
            // Языковой переключатель
            const langToggle = document.querySelector('.lang-toggle');
            const currentPath = window.location.pathname;
            
            if (langToggle) {
                if (currentPath.includes('/uz/')) {
                    langToggle.setAttribute('data-active', 'uz');
                    document.getElementById('uz-btn')?.classList.add('active');
                    document.getElementById('ru-btn')?.classList.remove('active');
                } else {
                    langToggle.setAttribute('data-active', 'ru');
                    document.getElementById('ru-btn')?.classList.add('active');
                    document.getElementById('uz-btn')?.classList.remove('active');
                }
            }
            
            // Инициализация уведомлений
            loadUserNotifications();
            
            
            // Закрытие модального окна по клику вне
            document.onclick = function(e) {
                const modal = document.getElementById('notificationsModal');
                const btn = document.getElementById('notificationsBtn');
                
                if (modal && modal.classList.contains('active') && 
                    !modal.contains(e.target) && !btn?.contains(e.target)) {
                    closeNotifications();
                }
            };
            
            // Приветственное уведомление для новых пользователей
            setTimeout(() => {
                const isFirstTime = !localStorage.getItem('userNotifications');
                if (isFirstTime) {
                    addUserNotification('welcome', 'Добро пожаловать!');
                }
            }, 3000);
            setTimeout(() => loadLeaderboard(), 1000);
        });
        
        // Отключение масштабирования
        document.addEventListener('touchstart', function(e) {
            if (e.touches.length > 1) e.preventDefault();
        }, { passive: false });
        
        document.addEventListener('gesturestart', function(e) {
            e.preventDefault();
        }, { passive: false });
        
        let lastTouchEnd = 0;
        document.addEventListener('touchend', function(e) {
            const now = Date.now();
            if (now - lastTouchEnd <= 300) e.preventDefault();
            lastTouchEnd = now;
        }, false);
        
        // Принудительное обновление ранга после скрытия прелоадера
        setTimeout(() => {
            if (tgUser) {
                forceUpdateRank();
            }
        }, 6000);
</script>
<div id="notificationsModal" style="display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.9); z-index: 10001; overflow-y: auto; padding: 20px;">
    <div style="max-width: 600px; margin: 80px auto 40px; background: linear-gradient(135deg, rgba(19, 19, 43, 0.95), rgba(30, 20, 60, 0.9)); border: 2px solid rgba(168, 85, 247, 0.5); border-radius: 24px; padding: 30px;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 25px;">
            <h2 style="font-family: 'Exo 2', sans-serif; font-size: 1.8rem; background: linear-gradient(135deg, #a855f7, #ec4899); -webkit-background-clip: text; -webkit-text-fill-color: transparent; margin: 0;">🔔 Уведомления</h2>
            <button onclick="closeNotifications()" style="background: transparent; border: 2px solid rgba(168, 85, 247, 0.5); color: white; width: 40px; height: 40px; border-radius: 50%; font-size: 24px; cursor: pointer; transition: all 0.3s ease;">×</button>
        </div>
        <div id="notificationsList"></div>
    </div>
</div>
</body>
</html>
