<?php

// Enable error reporting
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

define('TOKEN', '7889069667:AAFoY1__v4tllao0irT4Rhn5E0h7IOmPgHo');
define('REQUIRED_CHANNEL', '@EarningScripter1');
define('ADMIN_ID', 1171531253);
define('DATA_FILE', 'referral_data.json');
define('BROADCAST_FILE', 'broadcast_data.json'); // Temporary file for broadcast data

// ✅ Load or initialize user data
function loadData() {
    if (file_exists(DATA_FILE)) {
        $data = file_get_contents(DATA_FILE);
        return json_decode($data, true) ?: [];
    }
    return [];
}

function saveData($data) {
    file_put_contents(DATA_FILE, json_encode($data, JSON_PRETTY_PRINT));
}

// ✅ Load or initialize broadcast data
function loadBroadcastData() {
    if (file_exists(BROADCAST_FILE)) {
        $data = file_get_contents(BROADCAST_FILE);
        return json_decode($data, true) ?: [];
    }
    return [];
}

function saveBroadcastData($data) {
    file_put_contents(BROADCAST_FILE, json_encode($data, JSON_PRETTY_PRINT));
}

$users = loadData();
$broadcastData = loadBroadcastData();

// ✅ Send a request to the Telegram API
function sendRequest($method, $params = []) {
    $url = "https://api.telegram.org/bot" . TOKEN . "/" . $method;
    $options = [
        'http' => [
            'header'  => "Content-type: application/x-www-form-urlencoded\r\n",
            'method'  => 'POST',
            'content' => http_build_query($params),
        ],
    ];
    $context = stream_context_create($options);
    $result = file_get_contents($url, false, $context);
    if ($result === FALSE) {
        error_log("Telegram API request failed: $method");
    }
    return $result;
}

// ✅ Get the incoming update
$update = json_decode(file_get_contents('php://input'), true);

if (!$update) {
    error_log("No update received.");
    echo "OK";
    exit;
}

if (isset($update['message'])) {
    $message = $update['message'];
    $chatId = $message['chat']['id'];
    $userId = $message['from']['id'];
    $firstName = $message['from']['first_name'];
    $text = $message['text'] ?? '';

    // ✅ /start Command
    if (strpos($text, '/start') === 0) {
        $args = explode(' ', $text);
        $referrerId = count($args) > 1 ? $args[1] : null;

        if (!isset($users[$userId])) {
            $users[$userId] = [
                'name' => $firstName, // Save user's name
                'referrals' => 0,
                'referred_by' => $referrerId,
                'joined' => false
            ];
            saveData($users);
        }

        $keyboard = [
            'inline_keyboard' => [
                [['text' => '✅ JOIN CHANNEL', 'url' => 'https://t.me/' . substr(REQUIRED_CHANNEL, 1)]],
                [['text' => '☑️ Joined', 'callback_data' => 'check_membership']]
            ]
        ];

        sendRequest('sendMessage', [
            'chat_id' => $chatId,
            'text' => "👋 **Hey $firstName!**\n\n" .
                      "🎁 Join our channel to continue and unlock referral rewards!\n\n" .
                      "🚀 **After Joining click on Joined:**"
            'parse_mode' => 'Markdown',
            'reply_markup' => json_encode($keyboard)
        ]);
    }

    // ✅ Broadcast Command (Admin Only)
    if ($text === '/broadcast' && $userId == ADMIN_ID) {
        $broadcastData['broadcast_mode'] = true;
        saveBroadcastData($broadcastData);

        sendRequest('sendMessage', [
            'chat_id' => $chatId,
            'text' => '📢 **Send the message or image you want to broadcast.**',
            'parse_mode' => 'Markdown'
        ]);
    }

    // ✅ Handle Broadcast Message
    if (isset($broadcastData['broadcast_mode']) && $broadcastData['broadcast_mode'] && $userId == ADMIN_ID && $text !== '/broadcast') {
        $broadcastData['broadcast_mode'] = false;
        saveBroadcastData($broadcastData);

        $msg = $text ?: '📷 Image Broadcast';
        $photo = $message['photo'][0]['file_id'] ?? null;

        foreach ($users as $id => $data) {
            try {
                if ($photo) {
                    sendRequest('sendPhoto', [
                        'chat_id' => $id,
                        'photo' => $photo,
                        'caption' => $msg
                    ]);
                } else {
                    sendRequest('sendMessage', [
                        'chat_id' => $id,
                        'text' => $msg
                    ]);
                }
            } catch (Exception $e) {
                error_log("Failed to send broadcast to user $id: " . $e->getMessage());
            }
        }

        sendRequest('sendMessage', [
            'chat_id' => $chatId,
            'text' => '✅ **Broadcast Sent!**',
            'parse_mode' => 'Markdown'
        ]);
    }
}

// ✅ Handle Callback Queries
if (isset($update['callback_query'])) {
    $query = $update['callback_query'];
    $userId = $query['from']['id'];
    $data = $query['data'];
    $messageId = $query['message']['message_id'];
    $chatId = $query['message']['chat']['id'];

    // ✅ Check Membership
    if ($data === 'check_membership') {
        $member = json_decode(sendRequest('getChatMember', [
            'chat_id' => REQUIRED_CHANNEL,
            'user_id' => $userId
        ]), true);

        if (in_array($member['result']['status'], ['member', 'administrator', 'creator'])) {
            $users[$userId]['joined'] = true;
            saveData($users);

            $referrerId = $users[$userId]['referred_by'];
            if ($referrerId && isset($users[$referrerId]) && !in_array($userId, $users[$referrerId]['referrals_done'] ?? [])) {
                $users[$referrerId]['referrals']++;
                $users[$referrerId]['referrals_done'][] = $userId;
                saveData($users);

                sendRequest('sendMessage', [
                    'chat_id' => $referrerId,
                    'text' => "🎉 **Congratulations!**\n" .
                              "🎯 Your referral just joined!\n" .
                              "🏆 **Total Referrals:** `{$users[$referrerId]['referrals']}`",
                    'parse_mode' => 'Markdown'
                ]);
            }

            $refLink = "https://t.me/" . json_decode(sendRequest('getMe'), true)['result']['username'] . "?start=$userId";
            $keyboard = [
                'inline_keyboard' => [
                    [['text' => '🏆 View Leaderboard', 'callback_data' => 'show_leaderboard']]
                ]
            ];

            sendRequest('editMessageText', [
                'chat_id' => $chatId,
                'message_id' => $messageId,
                'text' => "🔥 **Welcome Aboard!**\n\n" .
                          "🔗 **Your Referral Link:**\n`$refLink`\n\n" .
                          "👥 **Total Referrals:** `{$users[$userId]['referrals']}`\n\n" .
                          "📢 Reffer your friends and win up to ₹10,000 recharge. You just need to be in the top 5",
                'parse_mode' => 'Markdown',
                'reply_markup' => json_encode($keyboard)
            ]);
        } else {
            sendRequest('answerCallbackQuery', [
                'callback_query_id' => $query['id'],
                'text' => '❌ Please join the channel first!'
            ]);
        }
    }

    // ✅ Show Leaderboard
    if ($data === 'show_leaderboard') {
        $leaderboard = $users;
        usort($leaderboard, function ($a, $b) {
            return $b['referrals'] - $a['referrals'];
        });
        $leaderboard = array_slice($leaderboard, 0, 10);

        $msg = "🏆 **TOP 10 REFERRERS:**\n\n";
        foreach ($leaderboard as $i => $data) {
            $name = $data['name'];
            $userId = array_search($data, $users); // Get user ID
            $msg .= "🥇 " . ($i + 1) . ". [{$name}](tg://user?id={$userId}) - `{$data['referrals']}` Referrals\n";
        }

        $keyboard = [
            'inline_keyboard' => [
                [['text' => '⬅️ Back', 'callback_data' => 'go_back']]
            ]
        ];

        sendRequest('editMessageText', [
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'text' => $msg,
            'parse_mode' => 'Markdown',
            'reply_markup' => json_encode($keyboard)
        ]);
    }

    // ✅ Go Back
    if ($data === 'go_back') {
        $refLink = "https://t.me/" . json_decode(sendRequest('getMe'), true)['result']['username'] . "?start=$userId";
        $keyboard = [
            'inline_keyboard' => [
                [['text' => '🏆 View Leaderboard', 'callback_data' => 'show_leaderboard']]
            ]
        ];

        sendRequest('editMessageText', [
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'text' => "🔥 **Welcome Back!**\n\n" .
                      "🔗 **Your Referral Link:**\n`$refLink`\n\n" .
                      "👥 **Total Referrals:** `{$users[$userId]['referrals']}`\n" .
                      "📢 Reffer your friends and win up to ₹10,000 recharge. You just need to be in the top 5",
            'parse_mode' => 'Markdown',
            'reply_markup' => json_encode($keyboard)
        ]);
    }
}

// ✅ Respond to Telegram
echo "OK";
