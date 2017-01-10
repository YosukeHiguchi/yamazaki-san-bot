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
    error_log("parseEventRequest failed. InvalidSignatureException => ".var_export($e, true));
} catch(\LINE\LINEBot\Exception\UnknownEventTypeException $e) {
    error_log("parseEventRequest failed. UnknownEventTypeException => ".var_export($e, true));
} catch(\LINE\LINEBot\Exception\UnknownMessageTypeException $e) {
    error_log("parseEventRequest failed. UnknownMessageTypeException => ".var_export($e, true));
} catch(\LINE\LINEBot\Exception\InvalidEventRequestException $e) {
    error_log("parseEventRequest failed. InvalidEventRequestException => ".var_export($e, true));
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
    if (!($event instanceof \LINE\LINEBot\Event\MessageEvent)) {
        continue;
    }

    if (!($event instanceof \LINE\LINEBot\Event\MessageEvent\TextMessage)) {
        $invalid_type_msg = '[山崎さんBOT] 現在はテキストしか対応しておりません。';
        $bot->replyText($event->getReplyToken(), $invalid_type_msg);
        continue;
    }

    $text = $event->getText();
    $user_id = $event->getUserId();

    switch ($text) {
        case '山崎さんと話す':
            if (isInConversation($user_id)) {
                sendText($user_id, '[山崎さんBOT] 山崎さんと会話中です。');
            } else {
                beginConversation($user_id);
            }
            break;
        case '山崎さんと話すのをやめる':
            finishConversation(getToken($user_id));
            break;
        default:
            if (isInConversation($user_id)) {
                inConversation($user_id, $text);
            } else {
                // do nothing
                $bot->replyText($event->getReplyToken(), $text);
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
    } else {
        $msg = '[山崎さんBOT] 山崎さんを検索中です。検索は10分後に自動的にオフになります。';
        sendText($user_id, $msg);
    }
}

function inConversation($user_id, $msg) {
    global $dbh;

    $token = getToken($user_id);
    $strSQL = "SELECT user_id FROM user WHERE token = :token";
    $stmt = $dbh->prepare($strSQL);
    $stmt->bindParam(':token', $token);
    $stmt->execute();
    $result = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($result === false) {
        sendText($user_id, '[山崎さんBOT] エラーが発生しました。');
        finishConversation($token);
        return;
    }

    $dest_user_id = $result['user_id'];
    sendText($dest_user_id, $msg);
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

    foreach ($results as $result) {
        sendText($result['user_id'], '[山崎さんBOT] 会話を終了しました。');
    }
}

function isInConversation($user_id) {
    global $dbh;

    $strSQL = 'SELECT token FROM user WHERE user_id = :user_id';
    $stmt = $dbh->prepare($strSQL);
    $stmt->execute();
    $result = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($result['token']) {
        return true;
    }

    return false;
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

function sendText($to, $msg) {
    global $bot;

    $textMessageBuilder = new \LINE\LINEBot\MessageBuilder\TextMessageBuilder($msg);
    $bot->pushMessage($to, $textMessageBuilder);
}

function createToken($length = 16) {
    $bytes = openssl_random_pseudo_bytes($length);
    return bin2hex($bytes);
}
