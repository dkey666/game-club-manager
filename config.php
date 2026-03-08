<?php
class Config {
    private static $config = [];
    
    public static function load() {
        self::$config = array_merge($_SERVER, $_ENV);
        $envFile = __DIR__ . '/.env';
        
        if (!file_exists($envFile)) {
            return;
        }
        
        $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        
        foreach ($lines as $line) {
            if (strpos(trim($line), '#') === 0) {
                continue;
            }
            
            if (strpos($line, '=') !== false) {
                list($key, $value) = explode('=', $line, 2);
                $key = trim($key);
                $value = trim($value);
                $value = trim($value, '"\'');
                
                self::$config[$key] = $value;
            }
        }
    }
    
    public static function validate() {
        $required = ['BOT_TOKEN', 'GROUP_ID'];
        $missing = [];
        
        foreach ($required as $key) {
            if (empty(self::get($key))) {
                $missing[] = $key;
            }
        }
        
        if (!empty($missing)) {
            throw new Exception('Missing required config keys: ' . implode(', ', $missing));
        }
        
        // Валидация токена
        $token = self::get('BOT_TOKEN');
        if (!preg_match('/^\d+:[A-Za-z0-9_-]{35}$/', $token)) {
            throw new Exception('Invalid BOT_TOKEN format');
        }
        
        // Валидация Group ID
        $groupId = self::get('GROUP_ID');
        if (!is_numeric($groupId)) {
            throw new Exception('GROUP_ID must be numeric');
        }
    }
    
    public static function get($key, $default = null) {
        return self::$config[$key] ?? $default;
    }

    public static function has($key) {
        return array_key_exists($key, self::$config) && self::$config[$key] !== '';
    }
    
    private static $adminCache = null;

    public static function getAdmins() {
        if (self::$adminCache !== null) {
            return self::$adminCache;
        }
        
        $adminIds = self::get('ADMIN_IDS', '');
        if (empty($adminIds)) {
            self::$adminCache = [];
            return [];
        }
        
        self::$adminCache = array_map('intval', array_filter(explode(',', $adminIds)));
        return self::$adminCache;
    }
    
    public static function isAdmin($userId) {
        return in_array(intval($userId), self::getAdmins());
    }
    
    // НОВОЕ: Email конфигурация
    public static function getEmailConfig() {
        return [
            'host' => self::get('SMTP_HOST'),
            'port' => intval(self::get('SMTP_PORT', 465)),
            'username' => self::get('SMTP_USERNAME'),
            'password' => self::get('SMTP_PASSWORD'),
            'from' => self::get('SMTP_FROM'),
            'from_name' => self::get('SMTP_FROM_NAME', APP_SHORT_NAME)
        ];
    }
}

Config::load();
if (Config::has('BOT_TOKEN') || Config::has('GROUP_ID')) {
    Config::validate();
}
define('BOT_TOKEN', Config::get('BOT_TOKEN'));
define('GROUP_ID', Config::get('GROUP_ID'));
define('APP_NAME', Config::get('APP_NAME', 'Dkx Game Club OS/Manager'));
define('APP_SHORT_NAME', Config::get('APP_SHORT_NAME', 'DKX Game Club'));
define('APP_URL', rtrim(Config::get('APP_URL', 'http://localhost'), '/'));
define('WEBAPP_URL', rtrim(Config::get('WEBAPP_URL', APP_URL . '/'), '/'));
define('ADMIN_PANEL_URL', rtrim(Config::get('ADMIN_PANEL_URL', APP_URL . '/new.html'), '/'));
