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
            beginConversation($user_id);
            break;
        case '山崎さんと話すのをやめる':
            finishConversation();
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

    sendText($user_id, 'working');
    // Duplicate Check
    $strSQL = "SELECT count(*) FROM user WHERE user_id = :user_id";
    $stmt = $dbh->prepare($strSQL);
    $stmt->bindParam(':user_id', $user_id);
    $stmt->execute();
    $result = $stmt->fetch(PDO::FETCH_ASSOC);

    // Add or update user
    if ($result['count'] > 0) {
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
    $waiting_user = getWaitingUser();
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
        sendText($user_id, '[山崎さんBOT] エラーが発生しました。会話を終了します。');
        finishConversation();
        return;
    }

    $dest_user_id = $result['user_id'];
    sendText($dest_user_id, $msg);
}

function finishConversation($user_id) {
    global $dbh;

    $strSQL = "UPDATE user SET token = '', waiting_flg = 0 WHERE user_id = :user_id";
    $stmt = $dbh->prepare($strSQL);
    $stmt->bindParam(':user_id', $user_id);
    $stmt->execute();

    sendText($user_id, '[山崎さんBOT] 会話を終了しました。');
}

function isInConversation($user_id) {
    global $dbh;

    $strSQL = 'SELECT token FROM user WHERE user_id = :user_id';
    $stmt = $dbh->prepare($strSQL);
    $stmt->execute();
    $result = $stmt->fetch(PDO::FETCH);

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

function getWaitingUser() {
    global $dbh;

    $strSQL  = "SELECT * FROM user WHERE waiting_flg = 1 AND start_time > CURRENT_TIMESTAMP + INTERVAL -10 MINUTE";
    $strSQL .= " AND DATALENGTH(token) = 0 ORDER BY start_time ASC";
    $stmt = $dbh->query($strSQL);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($result === false) {
        return array();
    }

    return $result;
}

function getUserId($id) {
    global $dbh;

    $strSQL = "SELECT user_id FROM user WHERE id = :id";
    $stmt = $dbh->prepare($strSQL);
    $stmt->bindParam(':id', $id);
    $stmt->execute();
    $result = $stmt->fetch(PDO::FETCH_ASSOC);

    return $result['user_id'];
}

function sendText($to, $msg) {
    $textMessageBuilder = new \LINE\LINEBot\MessageBuilder\TextMessageBuilder($msg);
    $bot->pushMessage($to, $textMessageBuilder);
}

function createToken($length = 16) {
    $bytes = openssl_random_pseudo_bytes($length);
    return bin2hex($bytes);
}
