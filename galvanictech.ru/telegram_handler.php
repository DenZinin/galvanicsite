<?php
require_once __DIR__ . '/load_env.php';

header('Content-Type: application/json; charset=utf-8');

// Разрешаем CORS запросы с разных источников
$allowed_origins = [
    'https://galvanictech.ru', 
    'http://galvanictech.ru', 
    'https://www.galvanictech.ru',
    'http://localhost:3000',
    'http://127.0.0.1:5500'
];

$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (in_array($origin, $allowed_origins)) {
    header('Access-Control-Allow-Origin: ' . $origin);
} else {
    header('Access-Control-Allow-Origin: https://galvanictech.ru');
}

header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Requested-With');
header('Access-Control-Max-Age: 86400');

// Обработка preflight запросов
if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    http_response_code(200);
    exit();
}

bootstrapEnv();
$recaptcha_secret = requireEnv('RECAPTCHA_SECRET_KEY');
$botToken = requireEnv('TELEGRAM_BOT_TOKEN');
$chatId = requireEnv('TELEGRAM_CHAT_ID');

// Функция для проверки reCAPTCHA
function verifyRecaptcha($secret, $response) {
    $url = 'https://www.google.com/recaptcha/api/siteverify';
    $data = [
        'secret' => $secret,
        'response' => $response,
        'remoteip' => $_SERVER['REMOTE_ADDR'] ?? ''
    ];
    
    $options = [
        'http' => [
            'header' => "Content-type: application/x-www-form-urlencoded\r\n",
            'method' => 'POST',
            'content' => http_build_query($data)
        ]
    ];
    
    $context = stream_context_create($options);
    $result = file_get_contents($url, false, $context);
    
    if ($result === FALSE) {
        return false;
    }
    
    $responseData = json_decode($result, true);
    return $responseData['success'] ?? false;
}

// Функция для экранирования Markdown символов
function escapeMarkdown($text) {
    if (empty($text)) return $text;
    $chars = ['_', '*', '[', ']', '(', ')', '~', '`', '>', '#', '+', '-', '=', '|', '{', '}', '.', '!'];
    foreach ($chars as $char) {
        $text = str_replace($char, '\\' . $char, $text);
    }
    return $text;
}

// Получаем данные из формы
$input = json_decode(file_get_contents('php://input'), true) ?? $_POST;

$name = $input['name'] ?? '';
$company = $input['company'] ?? '';
$phone = $input['phone'] ?? '';
$email = $input['email'] ?? '';
$interest = $input['interest'] ?? '';
$message = $input['message'] ?? '';
$recaptcha_response = $input['g-recaptcha-response'] ?? '';
$source = $input['source'] ?? 'products-page';

// ПРОВЕРКА reCAPTCHA
if (empty($recaptcha_response)) {
    http_response_code(400);
    echo json_encode([
        'success' => false, 
        'error' => 'Пожалуйста, подтвердите, что вы не робот'
    ]);
    exit;
}

if (!verifyRecaptcha($recaptcha_secret, $recaptcha_response)) {
    http_response_code(400);
    echo json_encode([
        'success' => false, 
        'error' => 'Ошибка проверки reCAPTCHA. Пожалуйста, попробуйте еще раз.'
    ]);
    exit;
}

// Валидация данных формы
if (empty($name) || empty($phone) || empty($interest)) {
    http_response_code(400);
    echo json_encode([
        'success' => false, 
        'error' => 'Заполните обязательные поля: Имя, Телефон и Интересующая продукция',
        'debug' => ['name' => $name, 'phone' => $phone, 'interest' => $interest]
    ]);
    exit;
}

// Проверяем длину данных для защиты от спама
if (strlen($name) > 100 || strlen($company) > 200 || strlen($phone) > 50 || strlen($email) > 100 || strlen($interest) > 100 || strlen($message) > 2000) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Слишком длинные данные в одном из полей']);
    exit;
}

// Форматируем текст сообщения для Telegram
$text = "🛠 *НОВАЯ ЗАЯВКА С САЙТА*\n\n";
$text .= "👤 *Имя:* " . escapeMarkdown($name) . "\n";
$text .= "🏢 *Компания:* " . (empty($company) ? 'не указана' : escapeMarkdown($company)) . "\n";
$text .= "📞 *Телефон:* " . escapeMarkdown($phone) . "\n";
$text .= "📧 *Email:* " . (empty($email) ? 'не указан' : escapeMarkdown($email)) . "\n";
$text .= "🎯 *Интересующая продукция:* " . escapeMarkdown($interest) . "\n";
$text .= "💬 *Сообщение:* " . (empty($message) ? 'не указано' : escapeMarkdown($message)) . "\n\n";
$text .= "🌐 *Источник:* " . escapeMarkdown($source) . "\n";
$text .= "⏰ *Время:* " . date('d.m.Y H:i:s');
$text .= "\n\n✅ *Проверка reCAPTCHA:* пройдена";

// Отправка в Telegram
$url = "https://api.telegram.org/bot" . $botToken . "/sendMessage";
$data = [
    'chat_id' => $chatId,
    'text' => $text,
    'parse_mode' => 'Markdown'
];

// Используем cURL для отправки
$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL => $url,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $data,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_SSL_VERIFYHOST => 2,
    CURLOPT_TIMEOUT => 15,
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_MAXREDIRS => 3
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
$curlErrno = curl_errno($ch);
curl_close($ch);

// Логирование для отладки
error_log("Telegram Request: " . json_encode($data));
error_log("Telegram Response: $httpCode - $response");

// Проверяем результат
if ($httpCode === 200 && strpos($response, '"ok":true') !== false) {
    echo json_encode([
        'success' => true, 
        'message' => 'Заявка отправлена! Мы свяжемся с вами в ближайшее время.'
    ]);
} else {
    $errorDetails = [
        'http_code' => $httpCode,
        'curl_error' => $curlError,
        'curl_errno' => $curlErrno,
        'response' => $response
    ];
    
    error_log("Telegram API Error: " . json_encode($errorDetails));
    
    http_response_code(500);
    echo json_encode([
        'success' => false, 
        'error' => 'Ошибка отправки. Пожалуйста, попробуйте позже или свяжитесь с нами по телефону.',
        'debug' => $curlError ?: 'Unknown error'
    ]);
}
?>