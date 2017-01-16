<?php
require_once('config.php');
require_once __DIR__ . '/vendor/autoload.php';

$httpClient = new \LINE\LINEBot\HTTPClient\CurlHTTPClient(CHANNEL_ACCESS_TOKEN);
$bot = new \LINE\LINEBot($httpClient, ['channelSecret' => CHANNEL_SECRET]);

// Get event
$signature = $_SERVER["HTTP_" . \LINE\LINEBot\Constant\HTTPHeader::LINE_SIGNATURE];
try {
    $events = $bot->parseEventRequest(file_get_contents('php://input'), $signature);
} catch(\LINE\LINEBot\Exception\InvalidSignatureException $e) {
    writeDebugLog("parseEventRequest failed. InvalidSignatureException => ".var_export($e, true));
} catch(\LINE\LINEBot\Exception\UnknownEventTypeException $e) {
    writeDebugLog("parseEventRequest failed. UnknownEventTypeException => ".var_export($e, true));
} catch(\LINE\LINEBot\Exception\UnknownMessageTypeException $e) {
    writeDebugLog("parseEventRequest failed. UnknownMessageTypeException => ".var_export($e, true));
} catch(\LINE\LINEBot\Exception\InvalidEventRequestException $e) {
    writeDebugLog("parseEventRequest failed. InvalidEventRequestException => ".var_export($e, true));
}

// Begin PDO
$dsn = DB_DSN;
$username = DB_USERNAME;
$password = DB_PASSWORD;
try {
    $dbh = new PDO($dsn, $username, $password);
} catch (PDOException $e) {
    exit;
}

if (!is_array($events)) {
    exit;
}

foreach ($events as $event) {
    if ($event instanceof \LINE\LINEBot\Event\BeaconDetectionEvent) {
        $bot->replyText($event->getReplyToken(), '山崎さんが・・・来るぅううううううう！');
        continue;
    }
    if (!($event instanceof \LINE\LINEBot\Event\MessageEvent)) {
        continue;
    }

    if (!($event instanceof \LINE\LINEBot\Event\MessageEvent\TextMessage)) {
        $invalid_type_msg = '[山崎さんBOT] 現在はテキストしか対応してません！';
        $bot->replyText($event->getReplyToken(), $invalid_type_msg);
        continue;
    }

    $text = $event->getText();
    $user_id = $event->getUserId();

    switch ($text) {
        case '山崎さんと話す':
            if (isInConversation($user_id)) {
                $bot->replyText($event->getReplyToken(), '[山崎さんBOT] 山崎さんと会話中ですよ！');
            } else {
                beginConversation($user_id);
            }
            break;
        case '山崎さんと話すのをやめる':
            $token = getToken($user_id);
            if ($token) {
                finishConversation($token);
            } else if (isWaiting($user_id)) {
                cancelWaiting($user_id);
                $bot->replyText($event->getReplyToken(), '[山崎さんBOT] 山崎さんと話すのをやめました。');
            } else {
                $bot->replyText($event->getReplyToken(), '[山崎さんBOT] 現在会話をしてませんよ！');
            }
            break;
        default:
            if (isInConversation($user_id)) {
                inConversation($user_id, $text);
            } else {
                $bot->replyText($event->getReplyToken(), '[山崎さんBOT] 山崎さんと会話をするには、「山崎さんと話す」と送信して下さい！');
            }
            break;
    }
}

function beginConversation($user_id) {
    global $dbh;

    // Duplicate Check
    $strSQL = "SELECT count(*) AS cnt FROM user WHERE user_id = :user_id";
    $stmt = $dbh->prepare($strSQL);
    $stmt->bindParam(':user_id', $user_id);
    $stmt->execute();
    $result = $stmt->fetch(PDO::FETCH_ASSOC);

    // Add or update user
    if ($result['cnt'] > 0) {
        $strSQL = "UPDATE user SET waiting_flg = 1, start_time = CURRENT_TIMESTAMP WHERE user_id = :user_id";
        $stmt = $dbh->prepare($strSQL);
        $stmt->bindParam(':user_id', $user_id);
        $stmt->execute();
    } else {
        $strSQL = "INSERT INTO user (user_id, waiting_flg, start_time) VALUES (:user_id, 1, CURRENT_TIMESTAMP)";
        $stmt = $dbh->prepare($strSQL);
        $stmt->bindParam(':user_id', $user_id);
        $stmt->execute();
    }

    // Connect if there is someone in waitinglist
    $waiting_user = getWaitingUser($user_id);
    if (count($waiting_user) > 0) {
        $token = createToken();

        $strSQL  = "UPDATE user SET token = :token, start_time = CURRENT_TIMESTAMP, waiting_flg = 0";
        $strSQL .= " WHERE user_id IN (:user1, :user2)";
        $stmt = $dbh->prepare($strSQL);
        $stmt->bindParam(':token', $token);
        $stmt->bindParam(':user1', $waiting_user['user_id']);
        $stmt->bindParam(':user2', $user_id);
        $stmt->execute();

        $msg = '[山崎さんBOT] 山崎さんと繋がりました！';
        sendText($waiting_user['user_id'], $msg);
        sendText($user_id, $msg);

        // Write to log
        $content_mod = "[".date('Y-m-d H:i:s').'] ----- Conversation Beginned -----';
        $strSQL  = "INSERT INTO log (token, user1_id, user2_id, user1_name, user2_name, content, start_time)";
        $strSQL .= " VALUES (:token, :user1_id, :user2_id, :user1_name, :user2_name, :content, CURRENT_TIMESTAMP)";
        $stmt = $dbh->prepare($strSQL);
        $stmt->bindParam(':token', $token);
        $stmt->bindParam(':user1_id', $user_id);
        $stmt->bindParam(':user2_id', $waiting_user['user_id']);
        $stmt->bindParam(':user1_name', getNameFromUserId($user_id));
        $stmt->bindParam(':user2_name', getNameFromUserId($waiting_user['user_id']));
        $stmt->bindParam(':content', $content_mod);

        $stmt->execute();
    } else {
        $msg = '[山崎さんBOT] 山崎さんを検索中。検索は10分後に自動的にオフになります。';
        sendText($user_id, $msg);
    }
}

function inConversation($user_id, $msg) {
    global $dbh;

    $errmsg = msgValidationCheck($msg);
    if ($errmsg != '') {
        sendText($user_id, $errmsg);
        return;
    }

    $token = getToken($user_id);
    $strSQL = "SELECT user_id FROM user WHERE token = :token AND user_id != :user_id";
    $stmt = $dbh->prepare($strSQL);
    $stmt->bindParam(':token', $token);
    $stmt->bindParam(':user_id', $user_id);
    $stmt->execute();
    $result = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($result === false) {
        $errmsg = '[山崎さんBOT] エラーが発生しました。';
        sendText($user_id, $errmsg);
        finishConversation($token);
        return;
    }

    $dest_user_id = $result['user_id'];
    sendText($dest_user_id, $msg);
    writeLog($user_id, $dest_user_id, $token, $msg);
}

function finishConversation($token) {
    global $dbh;

    if (!$token) {
        return;
    }

    $strSQL = "SELECT user_id FROM user WHERE token = :token";
    $stmt = $dbh->prepare($strSQL);
    $stmt->bindParam(':token', $token);
    $stmt->execute();
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $strSQL = "UPDATE user SET token = '', waiting_flg = 0 WHERE token = :token";
    $stmt = $dbh->prepare($strSQL);
    $stmt->bindParam(':token', $token);
    $stmt->execute();

    // Assign end_time to log table
    $content_mod = "\n[".date('Y-m-d H:i:s').'] ----- Conversation Finished -----';
    $strSQL = "UPDATE log SET content = CONCAT(content, :content), end_time = CURRENT_TIMESTAMP WHERE token = :token";
    $stmt = $dbh->prepare($strSQL);
    $stmt->bindParam(':content', $content_mod);
    $stmt->bindParam(':token', $token);
    $stmt->execute();

    foreach ($results as $result) {
        sendText($result['user_id'], '[山崎さんBOT] 会話を終了しました！');
    }
}

function isInConversation($user_id) {
    global $dbh;

    $strSQL = 'SELECT token FROM user WHERE user_id = :user_id';
    $stmt = $dbh->prepare($strSQL);
    $stmt->bindParam(':user_id', $user_id);
    $stmt->execute();
    $result = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($result['token']) {
        return true;
    }

    return false;
}

function msgValidationCheck($msg) {
    $errmsg = '';

    if (strpos($msg, '[山崎さんBOT]') !== false) {
        $errmsg = '[山崎さんBOT] 送信文中に"[山崎さんBOT]"は含めません。';
    }

    return $errmsg;
}

function getToken($user_id) {
    global $dbh;

    $strSQL = "SELECT token FROM user WHERE user_id = :user_id";
    $stmt = $dbh->prepare($strSQL);
    $stmt->bindParam(':user_id', $user_id);
    $stmt->execute();
    $result = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($result === false) {
        return '';
    }

    return $result['token'];
}

/**
 *  @param user_id User ID which should be excluded from searching
 */
function getWaitingUser($user_id = NULL) {
    global $dbh;

    $strSQL  = "SELECT * FROM user WHERE waiting_flg = 1 AND start_time > CURRENT_TIMESTAMP + INTERVAL -10 MINUTE";
    $strSQL .= " AND (token = '' OR token IS NULL)";
    if (!is_null($user_id)) {
        $strSQL .= " AND user_id != :user_id ";
    }
    $strSQL .= "ORDER BY start_time ASC";

    $stmt = $dbh->prepare($strSQL);
    if (!is_null($user_id)) {
        $stmt->bindParam(':user_id', $user_id);
    }
    $stmt->execute();
    $result = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($result === false) {
        return array();
    }

    return $result;
}

function isWaiting($user_id) {
    global $dbh;

    $strSQL  = "SELECT * FROM user WHERE waiting_flg = 1 AND start_time > CURRENT_TIMESTAMP + INTERVAL -10 MINUTE";
    $strSQL .= " AND (token = '' OR token IS NULL) AND user_id = :user_id";
    $stmt = $dbh->prepare($strSQL);
    $stmt->bindParam(':user_id', $user_id);
    $stmt->execute();
    
    if ($stmt->rowCount() > 0) {
        return true;
    }

    return false;
}

function cancelWaiting($user_id) {
    global $dbh;

    $strSQL = "UPDATE user SET waiting_flg = 0, token = '' WHERE user_id = :user_id";
    $stmt = $dbh->prepare($strSQL);
    $stmt->bindParam(':user_id', $user_id);
    $stmt->execute();
}

function sendText($to, $msg) {
    global $bot;

    $textMessageBuilder = new \LINE\LINEBot\MessageBuilder\TextMessageBuilder($msg);
    $bot->pushMessage($to, $textMessageBuilder);
}

function createToken($length = 16) {
    $bytes = openssl_random_pseudo_bytes($length);
    return bin2hex($bytes);
}

function writeLog($from_id, $to_id, $token, $msg) {
    global $bot, $dbh;

    $from_name = getNameFromUserId($from_id);

    $strSQL = "SELECT * FROM log WHERE token = :token";
    $stmt = $dbh->prepare($strSQL);
    $stmt->bindParam(':token', $token);
    $stmt->execute();
    $result = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($result === false) {
        return;
    }
    $msg = str_replace(array("\r\n", "\r", "\n"), '\n', $msg);
    $content_mod = "\n[".date('Y-m-d H:i:s').'] <'.$from_name.'> '.$msg;
    $strSQL = "UPDATE log SET content = CONCAT(content, :content) WHERE token = :token";
    $stmt = $dbh->prepare($strSQL);
    $stmt->bindParam(':content', $content_mod);
    $stmt->bindParam(':token', $token);
    $stmt->execute();
}

function getNameFromUserId($user_id) {
    global $bot;

    $response = $bot->getProfile($user_id);
    $profile = $response->getJsonDecodedBody();

    return $profile['displayName'];
}

function writeDebugLog($msg = '') {
    if ($msg == '') {
        return;
    }
    $filepath = DEBUG_DIR.date('Ymd').'.log';
    error_log(date('[Y-m-d H:i:s] ').$msg.'\n', 3, $filepath);
}
