<?php
// =============================================================================
// DIAMOND DEPOSIT BOT - COMPLETE PHP VERSION
// Based on original Python mains.py
// Webhook: 
// =============================================================================

// =============================================================================
// CONFIGURATION
// =============================================================================
define('BOT_TOKEN', '');
define('ADMIN_ID', );
define('DB_FILE', '');
define('DATA_FOLDER', '');
define('FILE_GAME_FOLDER', '');
define('DATA_WRITE_FOLDER', '');
define('BAKONG_TOKEN', '');

// =============================================================================
// INITIALIZATION
// =============================================================================
if (!is_dir(DATA_FOLDER)) mkdir(DATA_FOLDER, 0777, true);
if (!is_dir(FILE_GAME_FOLDER)) mkdir(FILE_GAME_FOLDER, 0777, true);
if (!is_dir(DATA_WRITE_FOLDER)) mkdir(DATA_WRITE_FOLDER, 0777, true);

$logFile = DATA_FOLDER . 'bot.log';
function logMsg($msg) {
    global $logFile;
    file_put_contents($logFile, date('H:i:s') . " | $msg\n", FILE_APPEND);
}

// Initialize database
initializeDatabase();

// =============================================================================
// WEBHOOK HANDLER
// =============================================================================
$input = file_get_contents('php://input');
$update = json_decode($input, true);

// Return OK immediately to Telegram
http_response_code(200);
header('Content-Type: application/json');
echo json_encode(['ok' => true]);

if (!$update) {
    logMsg("No input received");
    exit;
}

// Process update
try {
    if (isset($update['message'])) {
        processMessage($update['message']);
    } elseif (isset($update['callback_query'])) {
        processCallback($update['callback_query']);
    }
} catch (Exception $e) {
    logMsg("ERROR: " . $e->getMessage());
    logMsg("Trace: " . $e->getTraceAsString());
}

// =============================================================================
// DATABASE FUNCTIONS (from Python: initialize_user_database, get_user_data, save_user_data)
// =============================================================================
function initializeDatabase() {
    try {
        $pdo = new PDO('sqlite:' . DB_FILE);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        // Users table
        $pdo->exec("CREATE TABLE IF NOT EXISTS users (
            user_id INTEGER PRIMARY KEY,
            balance REAL DEFAULT 0,
            start_data INTEGER,
            total_order INTEGER DEFAULT 0,
            date_startbot TEXT,
            total_deposit REAL DEFAULT 0,
            referral_total REAL DEFAULT 0,
            username TEXT,
            first_name TEXT
        )");
        
        // Chat history table
        $pdo->exec("CREATE TABLE IF NOT EXISTS chat_history (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            chat_id INTEGER,
            message TEXT,
            date TEXT
        )");
        
        logMsg("Database initialized");
    } catch (Exception $e) {
        logMsg("Database init error: " . $e->getMessage());
    }
}

function getDb() {
    $pdo = new PDO('sqlite:' . DB_FILE);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    return $pdo;
}

function getUserData($userId, $key) {
    try {
        $db = getDb();
        $stmt = $db->prepare("SELECT $key FROM users WHERE user_id = ?");
        $stmt->execute([$userId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ? $result[$key] : null;
    } catch (Exception $e) {
        return null;
    }
}

function saveUserData($userId, $key, $value) {
    try {
        $db = getDb();
        
        // Check if user exists
        $stmt = $db->prepare("SELECT 1 FROM users WHERE user_id = ?");
        $stmt->execute([$userId]);
        
        if (!$stmt->fetch()) {
            // Insert new user
            $columns = "(user_id, " . $key . ")";
            $stmt = $db->prepare("INSERT INTO users $columns VALUES (?, ?)");
            $stmt->execute([$userId, $value]);
        } else {
            // Update existing
            $stmt = $db->prepare("UPDATE users SET $key = ? WHERE user_id = ?");
            $stmt->execute([$value, $userId]);
        }
    } catch (Exception $e) {
        logMsg("Save user data error: " . $e->getMessage());
    }
}

// =============================================================================
// CHAT HISTORY (from Python: load_chat_history, save_chat_history, handle_message)
// =============================================================================
function loadChatHistory() {
    $file = DATA_WRITE_FOLDER . 'chat_history.json';
    if (!file_exists($file)) return [];
    return json_decode(file_get_contents($file), true) ?: [];
}

function saveChatHistory($history) {
    $file = DATA_WRITE_FOLDER . 'chat_history.json';
    file_put_contents($file, json_encode($history, JSON_PRETTY_PRINT));
}

function addToHistory($chatId, $text) {
    $history = loadChatHistory();
    if (!isset($history[$chatId])) {
        $history[$chatId] = [];
    }
    $history[$chatId][] = $text;
    saveChatHistory($history);
}

// =============================================================================
// FILE MANAGER (from Python: delete_file, create_custom_keyboard)
// =============================================================================
function deleteFile($filePath) {
    $fullPath = FILE_GAME_FOLDER . $filePath;
    if (file_exists($fullPath)) {
        unlink($fullPath);
        return true;
    }
    return false;
}

function createCustomKeyboard() {
    $keyboard = [
        'keyboard' => [],
        'resize_keyboard' => true
    ];
    
    $file = DATA_FOLDER . 'keyboard.txt';
    if (!file_exists($file)) {
        $keyboard['keyboard'][] = [['text' => '🔙 BACK']];
        return $keyboard;
    }
    
    $content = file_get_contents($file);
    $buttons = explode('-', $content);
    
    $row = [];
    foreach ($buttons as $button) {
        if (empty($button)) continue;
        $row[] = ['text' => $button];
        if (count($row) == 3) {
            $keyboard['keyboard'][] = $row;
            $row = [];
        }
    }
    if (!empty($row)) {
        $keyboard['keyboard'][] = $row;
    }
    
    $keyboard['keyboard'][] = [['text' => '🔙 BACK']];
    return $keyboard;
}

function getMainMenu() {
    return [
        'keyboard' => [
            [['text' => '🗳️ Price Pool']],
            [['text' => '💵 ទឹកលុយ'], ['text' => '💸 ដាក់ប្រាក់'], ['text' => 'ℹ️ ព័ត៌មាន']],
            [['text' => '🛍️ Order'], ['text' => '📊 Statistics']],
            [['text' => '🗃️ File']]
        ],
        'resize_keyboard' => true
    ];
}

// =============================================================================
// TELEGRAM API HELPERS
// =============================================================================
function sendMessage($chatId, $text, $parseMode = 'Markdown', $replyMarkup = null) {
    $url = "https://api.telegram.org/bot" . BOT_TOKEN . "/sendMessage";
    $params = [
        'chat_id' => $chatId,
        'text' => $text,
        'parse_mode' => $parseMode
    ];
    if ($replyMarkup) {
        $params['reply_markup'] = json_encode($replyMarkup);
    }
    
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    $result = curl_exec($ch);
    $error = curl_error($ch);
    curl_close($ch);
    
    if ($error) {
        logMsg("SendMessage error: $error");
    }
    return $result;
}

function sendPhoto($chatId, $photo, $caption, $parseMode = 'Markdown', $replyMarkup = null) {
    $url = "https://api.telegram.org/bot" . BOT_TOKEN . "/sendPhoto";
    $params = [
        'chat_id' => $chatId,
        'photo' => $photo,
        'caption' => $caption,
        'parse_mode' => $parseMode
    ];
    if ($replyMarkup) {
        $params['reply_markup'] = json_encode($replyMarkup);
    }
    
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    $result = curl_exec($ch);
    curl_close($ch);
    
    return $result;
}

function deleteMessage($chatId, $messageId) {
    $url = "https://api.telegram.org/bot" . BOT_TOKEN . "/deleteMessage";
    $params = [
        'chat_id' => $chatId,
        'message_id' => $messageId
    ];
    
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_exec($ch);
    curl_close($ch);
}

// =============================================================================
// BAKONG PAYMENT (from Python: create_qr_code, check_transaction_by_md5, https)
// =============================================================================
function createQRCode($text, $chatId, $md5) {
    $qrUrl = "https://quickchart.io/qr?text=" . urlencode($text) . "&dark=ffae00&ecLevel=H&margin=5&size=500&centerImageUrl=https://i.ibb.co/fSJs2jY/New-Project-62-8-F91712.png&centerImageSize=0.50";
    
    $caption = "*✅ Please send your money to this QrCode\n• ━━━━━━━━━━━━━ •\n\n⏱️ Wait 1 to 5mn\n\n• ━━━━━━━━━━━━━ •\n⚠️ Minimum is 0.10\$STAR*";
    
    $keyboard = [
        'inline_keyboard' => [
            [['text' => '✅ CHECK', 'callback_data' => "check\$$md5"]]
        ]
    ];
    
    sendPhoto($chatId, $qrUrl, $caption, 'Markdown', $keyboard);
}

function checkTransactionByMd5($md5) {
    $url = 'https://api-bakong.nbc.gov.kh/v1/check_transaction_by_md5';
    
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['md5' => $md5]));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . BAKONG_TOKEN,
        'Content-Type: application/json'
    ]);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    $response = curl_exec($ch);
    curl_close($ch);
    
    return json_decode($response, true);
}

function checkTransactionByHash($hash, $amount) {
    $url = 'https://api-bakong.nbc.gov.kh/v1/check_transaction_by_short_hash';
    
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
        'hash' => $hash,
        'amount' => floatval($amount),
        'currency' => 'USD'
    ]));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . BAKONG_TOKEN,
        'Content-Type: application/json'
    ]);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    $response = curl_exec($ch);
    curl_close($ch);
    
    return json_decode($response, true);
}

// =============================================================================
// MESSAGE PROCESSOR (from Python: all message handlers)
// =============================================================================
function processMessage($message) {
    $chatId = $message['chat']['id'];
    $text = $message['text'] ?? '';
    $firstName = $message['chat']['first_name'] ?? 'User';
    $username = $message['chat']['username'] ?? 'no_username';
    
    logMsg("Message from $chatId (@$username): $text");
    
    // Handle commands
    if (strpos($text, '/') === 0) {
        handleCommand($message);
        return;
    }
    
    // Handle menu buttons
    handleText($message);
}

function handleCommand($message) {
    $chatId = $message['chat']['id'];
    $text = $message['text'];
    $command = explode(' ', $text)[0];
    
    logMsg("Command: $command");
    
    switch ($command) {
        case '/start':
            cmdStart($message);
            break;
            
        case '/admin':
            cmdAdmin($message);
            break;
            
        case '/add_balance':
            cmdAddBalance($message);
            break;
            
        case '/add_diamond':
            cmdAddDiamond($message);
            break;
            
        case '/delete_game':
            cmdDeleteGame($message);
            break;
            
        case '/add_topup':
            cmdAddTopup($message);
            break;
            
        case '/broadcast':
            cmdBroadcast($message);
            break;
            
        case '/random':
            cmdRandom($message);
            break;
            
        case '/create_code':
            cmdCreateCode($message);
            break;
            
        case '/code':
            cmdCheckCode($message);
            break;
            
        case '/set_up_file':
            cmdSetupFile($message);
            break;
            
        default:
            // Check for multi-step commands
            handleMultiStepCommand($message);
    }
}

// =============================================================================
// COMMAND IMPLEMENTATIONS
// =============================================================================

// /start - From Python: start()
function cmdStart($message) {
    $chatId = $message['chat']['id'];
    $firstName = $message['chat']['first_name'] ?? 'User';
    
    $balance = getUserData($chatId, 'balance');
    
    if ($balance === null) {
        // New user
        saveUserData($chatId, 'balance', 0);
        saveUserData($chatId, 'date_startbot', date('Y-m-d H:i:s'));
        saveUserData($chatId, 'first_name', $firstName);
        
        // Add to statistics
        $statFile = DATA_FOLDER . 'stati.txt';
        file_put_contents($statFile, '0 ', FILE_APPEND);
        
        // Add to user list
        $tgFile = DATA_FOLDER . 'tg.txt';
        file_put_contents($tgFile, " $chatId", FILE_APPEND);
        
        logMsg("New user registered: $chatId");
    }
    
    $caption = "Welcome / សួស្តី!\n\n@MyPersonalImage\n\nសូមចូលក្នុងឆានែលទាំងនេះជាមុនសិនបន្ទាប់មកចុច ✅ *Joined*";
    $photo = 'https://t.me/MyPersonalImage/412';
    
    $keyboard = [
        'inline_keyboard' => [
            [['text' => '✅ Join', 'callback_data' => 'join']]
        ]
    ];
    
    sendPhoto($chatId, $photo, $caption, 'Markdown', $keyboard);
}

// /admin - From Python: admin()
function cmdAdmin($message) {
    $chatId = $message['chat']['id'];
    
    if ($chatId != ADMIN_ID) {
        return;
    }
    
    $text = "/add_toup - Add new service for toup\n\n/add_diamond - Add diamond for any game topup";
    sendMessage($chatId, $text);
}

// /add_balance - From Python: add_balance(), balance_add(), add_balance2(), add_balance3()
function cmdAddBalance($message) {
    $chatId = $message['chat']['id'];
    
    if ($chatId != ADMIN_ID) {
        sendMessage($chatId, "You're not admin");
        return;
    }
    
    // Store state for next message
    setUserState($chatId, 'add_balance_step', 1);
    setUserState($chatId, 'add_balance_data', []);
    
    sendMessage($chatId, "*Send id user*", 'Markdown');
}

// /add_diamond - From Python: add_diamond(), add_value_game(), add_game_value()
function cmdAddDiamond($message) {
    $chatId = $message['chat']['id'];
    
    if ($chatId != ADMIN_ID) {
        sendMessage($chatId, "You're not admin");
        return;
    }
    
    setUserState($chatId, 'add_diamond_step', 1);
    sendMessage($chatId, "Enter the keyboard", null, createCustomKeyboard());
}

// /delete_game - From Python: delete_game(), game_delete()
function cmdDeleteGame($message) {
    $chatId = $message['chat']['id'];
    
    if ($chatId != ADMIN_ID) {
        sendMessage($chatId, "You're not admin");
        return;
    }
    
    setUserState($chatId, 'delete_game_step', 1);
    sendMessage($chatId, "select keyboard to delete", null, createCustomKeyboard());
}

// /add_topup - From Python: new_topup(), new_toups()
function cmdAddTopup($message) {
    $chatId = $message['chat']['id'];
    
    if ($chatId != ADMIN_ID) {
        sendMessage($chatId, "You're not admin");
        return;
    }
    
    setUserState($chatId, 'add_topup_step', 1);
    sendMessage($chatId, "Write name");
}

// /broadcast - From Python: broadcast(), broadcast2()
function cmdBroadcast($message) {
    $chatId = $message['chat']['id'];
    
    if ($chatId != ADMIN_ID) {
        return;
    }
    
    setUserState($chatId, 'broadcast_step', 1);
    sendMessage($chatId, "*Enter* text", 'Markdown');
}

// /random - From Python: random_user()
function cmdRandom($message) {
    $chatId = $message['chat']['id'];
    
    if ($chatId != ADMIN_ID) {
        return;
    }
    
    $tgFile = DATA_FOLDER . 'tg.txt';
    if (!file_exists($tgFile)) {
        sendMessage($chatId, "No users found");
        return;
    }
    
    $users = file_get_contents($tgFile);
    $userList = array_filter(explode(' ', $users));
    
    if (empty($userList)) {
        sendMessage($chatId, "No users found");
        return;
    }
    
    $winner = trim($userList[array_rand($userList)]);
    
    sendMessage($winner, "*Congratulations* Your account is *Winner*", 'Markdown');
    sendMessage($chatId, "Winner is $winner");
}

// /create_code - From Python: create_code(), generate_random_string()
function cmdCreateCode($message) {
    $chatId = $message['chat']['id'];
    
    if ($chatId != ADMIN_ID) {
        return;
    }
    
    $code = generateRandomString(10);
    
    file_put_contents(DATA_FOLDER . 'code.txt', $code);
    file_put_contents(DATA_FOLDER . 'ready.txt', '');
    file_put_contents(DATA_FOLDER . 'value.json', json_encode(['value' => 10]));
    
    sendMessage($chatId, "CODE: $code");
}

// /code - From Python: check_code(), check_code2()
function cmdCheckCode($message) {
    $chatId = $message['chat']['id'];
    setUserState($chatId, 'check_code_step', 1);
    sendMessage($chatId, "*Enter* your code", 'Markdown');
}

// /set_up_file - From Python: setup_file()
function cmdSetupFile($message) {
    $chatId = $message['chat']['id'];
    
    if ($chatId != ADMIN_ID) {
        return;
    }
    
    file_put_contents(DATA_FOLDER . 'code.txt', ' ');
    file_put_contents(DATA_FOLDER . 'keyboard.txt', '-ML[]BB');
    file_put_contents(DATA_FOLDER . 'ready.txt', ' ');
    file_put_contents(DATA_FOLDER . 'stati.txt', '0');
    file_put_contents(DATA_FOLDER . 'tg.txt', ' ');
    file_put_contents(DATA_FOLDER . 'value.json', '{}');
    file_put_contents(FILE_GAME_FOLDER . 'price_pool.json', '{}');
    
    sendMessage($chatId, "Setup completed");
}

// =============================================================================
// MENU BUTTON HANDLERS (from Python: price_pool, account, deposit, other, etc.)
// =============================================================================
function handleText($message) {
    $chatId = $message['chat']['id'];
    $text = $message['text'];
    $firstName = $message['chat']['first_name'] ?? 'User';
    
    // Check if user has active state (multi-step command)
    $state = getUserState($chatId);
    if ($state) {
        handleState($message, $state);
        return;
    }
    
    switch ($text) {
        case '🗳️ Price Pool':
            showPricePool($chatId);
            break;
            
        case '💵 ទឹកលុយ':
            showAccount($chatId, $firstName);
            break;
            
        case '💸 ដាក់ប្រាក់':
            showDeposit($chatId);
            break;
            
        case '🛍️ Order':
            showOrder($chatId);
            break;
            
        case '📊 Statistics':
            showStatistics($chatId);
            break;
            
        case 'ℹ️ ព័ត៌មាន':
            showInformation($chatId);
            break;
            
        case '🗃️ File':
            showFile($chatId);
            break;
            
        case '🔙 BACK':
            sendMessage($chatId, "Select", null, getMainMenu());
            break;
            
        default:
            sendMessage($chatId, "Use /start or menu buttons");
    }
}

// 🗳️ Price Pool - From Python: price_pool()
function showPricePool($chatId) {
    $poolFile = FILE_GAME_FOLDER . 'price_pool.json';
    $balance = 0;
    
    if (file_exists($poolFile)) {
        $data = json_decode(file_get_contents($poolFile), true);
        $balance = $data['balance'] ?? 0;
    }
    
    $text = "🗳️ Storage of star: *$balance*\n\nDescription: *Every others only 10% will add to storage of star. The end of the month we will giveaway all storage of star to user.*\n\nNote: *We always giveaway every month don't miss to join our channel*.\n\ncontact: @broskhuach";
    
    sendMessage($chatId, $text, 'Markdown');
}

// 💵 ទឹកលុយ - From Python: account()
function showAccount($chatId, $firstName) {
    $balance = getUserData($chatId, 'balance') ?? 0;
    
    $text = "👋 *Hello $firstName*\n• ━━━━━━━━━━━━━ •\n\n*Your balance: $balance*\n\n• ━━━━━━━━━━━━━ •\n*⚠️ Minimum deposit is 0.10\$STAR*";
    $photo = 'https://t.me/MyPersonalImage/412';
    
    sendPhoto($chatId, $photo, $text, 'Markdown');
}

// 💸 ដាក់ប្រាក់ - From Python: deposit()
function showDeposit($chatId) {
    $text = "*➕ DEPOSIT ➕*\n• ━━━━━━━━━━━━━ •\n\n💵 4000៛ / 1\$\n\n• ━━━━━━━━━━━━━ •\n*📜 LIST PRICE\n*";
    $photo = 'https://t.me/MyPersonalImage/412';
    
    $keyboard = [
        'inline_keyboard' => [
            [['text' => '🔴 BAKONG', 'callback_data' => 'bakong']]
        ]
    ];
    
    sendPhoto($chatId, $photo, $text, 'Markdown', $keyboard);
}

// 🛍️ Order - From Python: other(), function_check()
function showOrder($chatId) {
    $keyboard = createCustomKeyboard();
    sendMessage($chatId, "Choose", null, $keyboard);
    setUserState($chatId, 'order_step', 1);
}

// 📊 Statistics - From Python: statisticz()
function showStatistics($chatId) {
    $statFile = DATA_FOLDER . 'stati.txt';
    $count = 0;
    
    if (file_exists($statFile)) {
        $numbers = array_filter(explode(' ', file_get_contents($statFile)));
        $count = count($numbers);
    }
    
    $text = "📊 This is a *statistics* from bot\n\n$count";
    $photo = 'https://t.me/MyPersonalImage/412';
    
    $keyboard = [
        'inline_keyboard' => [
            [['text' => '↩️ Refresh', 'callback_data' => 'refresh']]
        ]
    ];
    
    sendPhoto($chatId, $photo, $text, 'Markdown', $keyboard);
}

// ℹ️ ព័ត៌មាន - From Python: informations()
function showInformation($chatId) {
    $text = "ℹ️ Information\n\nWhat's <b>Diamond Deposit</b>?\n└╼ <b>Diamond Deposit</b> is the best platform for top up to any game and can earn money for free.\n\nHow can i earn?\n└╼ We will giveaway every month. And mush more.\n\nHow can I deposit?\n└╼ Now we only have a Cambodia's bank but in the future we will add more bank for deposit.\n\nCan i be a reseller?\n└╼ Yes, contact supporter for more information.\n\nPlease read our Terms and Privacy Policy.\n\n<a href='https://telegra.ph/About-us-05-14-8'>About us</a>\n<a href='https://telegra.ph/Privacy-Policy-05-14-20'>Privacy Policy</a>\n<a href='https://telegra.ph/Welcome-to-our-top-up-game-platform-05-14'>Terms & use</a>";
    
    sendMessage($chatId, $text, 'HTML');
}

// 🗃️ File - From Python: show_chat_history()
function showFile($chatId) {
    $history = loadChatHistory();
    
    if (!isset($history[$chatId]) || empty($history[$chatId])) {
        sendMessage($chatId, "No chat history found.");
        return;
    }
    
    $messages = $history[$chatId];
    $response = "🗒️ Your History:\n\n";
    
    foreach ($messages as $idx => $msg) {
        $response .= ($idx + 1) . ". $msg\n\n";
    }
    
    sendMessage($chatId, $response, 'Markdown');
}

// =============================================================================
// CALLBACK HANDLER (from Python: calls())
// =============================================================================
function processCallback($callback) {
    $chatId = $callback['message']['chat']['id'];
    $messageId = $callback['message']['message_id'];
    $data = $callback['data'];
    
    logMsg("Callback: $data");
    
    // join
    if ($data === 'join') {
        sendMessage($chatId, "Select", null, getMainMenu());
        return;
    }
    
    // bakong deposit
    if ($data === 'bakong') {
        // Generate random MD5
        $random = rand(1, 99999);
        $md5 = md5("bakong_$random_" . time());
        
        createQRCode("BakongPayment_$md5", $chatId, $md5);
        return;
    }
    
    // check payment
    if (strpos($data, 'check$') === 0) {
        $md5 = explode('$', $data)[1];
        handleCheckPayment($chatId, $messageId, $md5);
        return;
    }
    
    // game selection
        // game selection (user clicked a diamond price)
    if (strpos($data, '-') !== false && strpos($data, '$') === false) {
        $parts = explode('-', $data);
        $game = $parts[0];           // FREE[]FIRE
        $price = $parts[1] ?? '';     // 10 (was [2] - BUG!)
        
        if (!empty($price)) {
            setUserState($chatId, 'payment_game', $game);
            setUserState($chatId, 'payment_price', $price);
            setUserState($chatId, 'payment_step', 1);
            
            sendMessage($chatId, "Send Your information: YourId(SeverId) ex 123456789(123456)");
        } else {
            sendMessage($chatId, "Error: Invalid price selection");
        }
        return;
    }
    
    // successful order
    if (strpos($data, 'suc ') === 0) {
        $userId = explode(' ', $data)[1];
        sendMessage($userId, "Your order has successfully...");
        return;
    }
    
    // reject order
    if (strpos($data, 'reject ') === 0) {
        $parts = explode(' ', $data);
        $userId = $parts[1];
        $amount = $parts[2];
        
        sendMessage($userId, "Your order is rejected.. Your money is refund. Please order again.");
        
        $balance = getUserData($userId, 'balance') ?? 0;
        $newBalance = floatval($balance) + floatval($amount);
        saveUserData($userId, 'balance', $newBalance);
        return;
    }
    
    // refresh statistics
    if ($data === 'refresh') {
        showStatistics($chatId);
        return;
    }
}

function handleCheckPayment($chatId, $messageId, $md5) {
    $response = checkTransactionByMd5($md5);
    $errorCode = $response['errorCode'] ?? 3;
    
    if ($errorCode == 3) {
        deleteMessage($chatId, $messageId);
        sendMessage($chatId, "Failed");
    } elseif ($errorCode == 1) {
        sendMessage($chatId, "not found");
    } else {
        deleteMessage($chatId, $messageId);
        
        $amount = $response['data']['amount'] ?? 0;
        $time = $response['data']['createdDateMs'] ?? '';
        
        $balance = getUserData($chatId, 'balance') ?? 0;
        $newBalance = floatval($balance) + floatval($amount);
        saveUserData($chatId, 'balance', $newBalance);
        
        $text = "🏦 *NEW DEPOSIT*\n\n↩️ From: *Bakong*\n🔄 To: *DiamondDepositBot*\nℹ️ Information: *Deposit successful*\n💸 Amount: *$amount*\n⌛ Time: *$time*";
        
        addToHistory($chatId, $text);
        sendMessage($chatId, "Your deposit +$amount");
    }
}

// =============================================================================
// STATE MANAGEMENT (for multi-step commands)
// =============================================================================
function setUserState($userId, $key, $value) {
    $file = DATA_FOLDER . "state_$userId.json";
    $state = [];
    
    if (file_exists($file)) {
        $state = json_decode(file_get_contents($file), true) ?: [];
    }
    
    $state[$key] = $value;
    file_put_contents($file, json_encode($state));
}

function getUserState($userId) {
    $file = DATA_FOLDER . "state_$userId.json";
    
    if (!file_exists($file)) {
        return null;
    }
    
    return json_decode(file_get_contents($file), true);
}

function clearUserState($userId) {
    $file = DATA_FOLDER . "state_$userId.json";
    if (file_exists($file)) {
        unlink($file);
    }
}

function handleState($message, $state) {
    $chatId = $message['chat']['id'];
    $text = $message['text'];
    
    if (isset($state['payment_step'])) {
        handlePaymentSteps($message, $state);
        return;
    }
    // Handle add_balance steps
    if (isset($state['add_balance_step'])) {
        handleAddBalanceSteps($message, $state);
        return;
    }
    
    // Handle add_diamond steps
    if (isset($state['add_diamond_step'])) {
        handleAddDiamondSteps($message, $state);
        return;
    }
    
    // Handle delete_game steps
    if (isset($state['delete_game_step'])) {
        handleDeleteGameSteps($message, $state);
        return;
    }
    
    // Handle add_topup steps
    if (isset($state['add_topup_step'])) {
        handleAddTopupSteps($message, $state);
        return;
    }
    
    // Handle broadcast steps
    if (isset($state['broadcast_step'])) {
        handleBroadcastSteps($message, $state);
        return;
    }
    
    // Handle check_code steps
    if (isset($state['check_code_step'])) {
        handleCheckCodeSteps($message, $state);
        return;
    }
    
    // Handle order steps
    if (isset($state['order_step'])) {
        handleOrderSteps($message, $state);
        return;
    }
    
    // Handle payment steps
    
}

// =============================================================================
// MULTI-STEP COMMAND HANDLERS
// =============================================================================

function handleAddBalanceSteps($message, $state) {
    $chatId = $message['chat']['id'];
    $text = $message['text'];
    $step = $state['add_balance_step'];
    $data = $state['add_balance_data'] ?? [];
    
    if ($step == 1) {
        $data['user_id'] = $text;
        setUserState($chatId, 'add_balance_data', $data);
        setUserState($chatId, 'add_balance_step', 2);
        sendMessage($chatId, "send amount");
    } elseif ($step == 2) {
        $data['amount'] = $text;
        setUserState($chatId, 'add_balance_data', $data);
        setUserState($chatId, 'add_balance_step', 3);
        sendMessage($chatId, "Remove or Add? (- or +)");
    } elseif ($step == 3) {
        $userId = $data['user_id'];
        $amount = floatval($data['amount']);
        $balance = getUserData($userId, 'balance') ?? 0;
        
        if ($text == '-') {
            $newBalance = floatval($balance) - $amount;
        } else {
            $newBalance = floatval($balance) + $amount;
        }
        
        saveUserData($userId, 'balance', $newBalance);
        sendMessage($chatId, "Done");
        clearUserState($chatId);
    }
}

function handleAddDiamondSteps($message, $state) {
    $chatId = $message['chat']['id'];
    $text = $message['text'];
    $step = $state['add_diamond_step'];
    
    if ($step == 1) {
        setUserState($chatId, 'add_diamond_game', $text);
        setUserState($chatId, 'add_diamond_step', 2);
        sendMessage($chatId, "Enter value of json to add");
    } elseif ($step == 2) {
        $game = $state['add_diamond_game'];
        
        try {
            $json = json_decode($text, true);
            file_put_contents(FILE_GAME_FOLDER . "$game.json", json_encode($json));
            sendMessage($chatId, "Done");
        } catch (Exception $e) {
            sendMessage($chatId, "Error: " . $e->getMessage());
        }
        
        clearUserState($chatId);
    }
}

function handleDeleteGameSteps($message, $state) {
    $chatId = $message['chat']['id'];
    $text = $message['text'];
    
    $keyboardFile = DATA_FOLDER . 'keyboard.txt';
    $content = file_get_contents($keyboardFile);
    $newContent = str_replace("-$text", '', $content);
    file_put_contents($keyboardFile, $newContent);
    
    deleteFile("$text.json");
    sendMessage($chatId, "done");
    clearUserState($chatId);
}

function handleAddTopupSteps($message, $state) {
    $chatId = $message['chat']['id'];
    $text = $message['text'];
    
    $keyboardFile = DATA_FOLDER . 'keyboard.txt';
    file_put_contents($keyboardFile, "-$text", FILE_APPEND);
    file_put_contents(FILE_GAME_FOLDER . "$text.json", '{}');
    
    sendMessage($chatId, "Done");
    clearUserState($chatId);
}

function handleBroadcastSteps($message, $state) {
    $chatId = $message['chat']['id'];
    $text = $message['text'];
    
    $tgFile = DATA_FOLDER . 'tg.txt';
    if (!file_exists($tgFile)) {
        sendMessage($chatId, "No users to broadcast");
        clearUserState($chatId);
        return;
    }
    
    $users = file_get_contents($tgFile);
    $userList = array_filter(explode(' ', $users));
    
    foreach ($userList as $userId) {
        sendMessage(trim($userId), $text, 'HTML');
    }
    
    sendMessage($chatId, "Broadcast sent to " . count($userList) . " users");
    clearUserState($chatId);
}

function handleCheckCodeSteps($message, $state) {
    $chatId = $message['chat']['id'];
    $text = $message['text'];
    
    $codeFile = DATA_FOLDER . 'code.txt';
    $readyFile = DATA_FOLDER . 'ready.txt';
    $valueFile = DATA_FOLDER . 'value.json';
    
    if (!file_exists($readyFile)) {
        file_put_contents($readyFile, '');
    }
    
    $ready = file_get_contents($readyFile);
    if (strpos($ready, " $chatId") !== false) {
        sendMessage($chatId, "Not available for your account");
        clearUserState($chatId);
        return;
    }
    
    $code = file_get_contents($codeFile);
    if (trim($code) !== trim($text)) {
        sendMessage($chatId, "Code not available");
        clearUserState($chatId);
        return;
    }
    
    $valueData = json_decode(file_get_contents($valueFile), true);
    $value = $valueData['value'] ?? 0;
    
    if ($value <= 0) {
        sendMessage($chatId, "You're late");
        clearUserState($chatId);
        return;
    }
    
    // Random prize box
    $box = [0.0001, 0.0005, 0.0010, 0.0010, 0.0010, 0.0005, 0.0050, 0.005, 0.005, 0.01, 0.01, 0.05, 0.07];
    $win = $box[array_rand($box)];
    
    // Update value
    $valueData['value'] = $value - 1;
    file_put_contents($valueFile, json_encode($valueData));
    
    // Mark user as used
    file_put_contents($readyFile, " $chatId", FILE_APPEND);
    
    // Send congratulations
    $keyboard = [
        'inline_keyboard' => [
            [['text' => 'SHARE', 'url' => "https://t.me/share/url?url=I just won $win on @DiamondDepositsBot"]]
        ]
    ];
    
    sendMessage($chatId, "*Congratulations* You win *$win*", 'Markdown', $keyboard);
    clearUserState($chatId);
}

function handleOrderSteps($message, $state) {
    $chatId = $message['chat']['id'];
    $text = $message['text'];  // This is the GAME name from custom keyboard
    
    if ($text == '🔙 BACK') {
        sendMessage($chatId, "Select", null, getMainMenu());
        clearUserState($chatId);
        return;
    }
    
    $keyboardFile = DATA_FOLDER . 'keyboard.txt';
    $content = file_get_contents($keyboardFile);
    $games = array_values(array_filter(array_map('trim', explode('-', trim($content, '-')))));
    
    if (!in_array($text, $games)) {
        sendMessage($chatId, "Game not found: $text");
        clearUserState($chatId);
        return;
    }
    
    $priceFile = FILE_GAME_FOLDER . "$text.json";
    if (!file_exists($priceFile)) {
        sendMessage($chatId, "No prices for: $text");
        clearUserState($chatId);
        return;
    }
    
    $prices = json_decode(file_get_contents($priceFile), true);
    if (empty($prices)) {
        sendMessage($chatId, "Empty price list");
        clearUserState($chatId);
        return;
    }
    
    $keyboard = [];
    foreach ($prices as $item) {
        if (!isset($item['amount']) || !isset($item['price'])) continue;
        
        // Clean price: remove $ and spaces, keep number
        $cleanPrice = preg_replace('/[^0-9.]/', '', $item['price']);
        
        // Callback: GAME-PRICE (e.g., FREE[]FIRE-10)
        $callbackData = $text . '-' . $cleanPrice;
        $buttonText = $item['amount'] . ' : ' . $item['price'];
        
        $keyboard[] = [['text' => $buttonText, 'callback_data' => $callbackData]];
    }
    
    if (empty($keyboard)) {
        sendMessage($chatId, "No valid prices");
        clearUserState($chatId);
        return;
    }
    
    $photo = 'https://t.me/MyPersonalImage/412';
    $caption = "*Select* diamond for *$text*\n\n⚠️ Check before clicking";
    
    sendPhoto($chatId, $photo, $caption, 'Markdown', ['inline_keyboard' => $keyboard]);
    
    // Keep state for back button, but we're done with step 1
    setUserState($chatId, 'order_step', 2);
    setUserState($chatId, 'order_game', $text);
}
function handlePaymentSteps($message, $state) {
    $chatId = $message['chat']['id'];
    $text = $message['text'];
    $game = $state['payment_game'] ?? '';
    $price = $state['payment_price'] ?? 0;  // Read from STATE, not user data
    
    // Debug logging
    logMsg("Payment step - Game: $game, Price: $price, Balance check starting");
    
    if ($text == '🔙 BACK') {
        clearUserState($chatId);
        sendMessage($chatId, "Select", null, getMainMenu());
        return;
    }
    
    $balance = getUserData($chatId, 'balance') ?? 0;
    
    if (floatval($balance) < floatval($price)) {
        sendMessage($chatId, "❌ Not enough money! Your balance: $$balance, Required: $$price");
        clearUserState($chatId);
        return;
    }
    
    // Deduct balance - FIXED: save newBalance not price!
    $newBalance = floatval($balance) - floatval($price);
    saveUserData($chatId, 'balance', $newBalance);  // FIXED: was $price
    
    // Calculate pool contribution (10%)
    $poolAmount = floatval($price) * 0.10;
    
    // Update pool
    $poolFile = FILE_GAME_FOLDER . 'price_pool.json';
    $poolData = ['balance' => 0];
    if (file_exists($poolFile)) {
        $poolData = json_decode(file_get_contents($poolFile), true) ?: ['balance' => 0];
    }
    $poolData['balance'] = ($poolData['balance'] ?? 0) + $poolAmount;
    file_put_contents($poolFile, json_encode($poolData));
    
    // Notify admin
    $username = $message['chat']['username'] ?? 'unknown';
    $info = "💎 New *top up*\n\nGame: $game\n💎 Diamond: $price\n🗂️ Id & server: $text\n\n👤 User: @$username\n💰 Pool +$$poolAmount";
    
    $adminKeyboard = [
        'inline_keyboard' => [
            [
                ['text' => '✅ Successful', 'callback_data' => "suc $chatId"],
                ['text' => '❌ Reject', 'callback_data' => "reject $chatId $price"]
            ]
        ]
    ];
    
    sendMessage(ADMIN_ID, $info, 'Markdown', $adminKeyboard);
    
    // Confirm to user
    sendMessage($chatId, "✅ Your order is submitted!\nGame: $game\nAmount: $$price\nInfo: $text\n\nPlease wait for processing.");
    
    // Add to history
    $historyText = "🆙 *TOP UP*\n\n🎮 Game: *$game*\n✅ Status: Pending\nℹ️ Info: $text\n💰 Price: $$price";
    addToHistory($chatId, $historyText);
    
    clearUserState($chatId);
}

// =============================================================================
// HELPER FUNCTIONS
// =============================================================================
function generateRandomString($length) {
    $characters = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
    $result = '';
    for ($i = 0; $i < $length; $i++) {
        $result .= $characters[rand(0, strlen($characters) - 1)];
    }
    return $result;
}

function handleMultiStepCommand($message) {
    // Fallback for any remaining multi-step logic
    $chatId = $message['chat']['id'];
    $text = $message['text'];
    
    // Check if it looks like a command response
    if (is_numeric($text) || strpos($text, '-') !== false) {
        sendMessage($chatId, "Please use /start first or select from menu");
    }
}
