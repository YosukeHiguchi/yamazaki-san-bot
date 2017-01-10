<?php
require_once('config.php');
require_once('db_connect.php');
require_once __DIR__ . '/vendor/autoload.php';

$httpClient = new \LINE\LINEBot\HTTPClient\CurlHTTPClient(CHANNEL_ACCESS_TOKEN);
$bot = new \LINE\LINEBot($httpClient, ['channelSecret' => CHANNEL_SECRET]);

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

foreach ($events as $event) {
    if (!($event instanceof \LINE\LINEBot\Event\MessageEvent)) {
        continue;
    }
    if (!($event instanceof \LINE\LINEBot\Event\MessageEvent\TextMessage)) {
        $invalid_type_msg = '現在はテキストしか対応しておりません。';
        $bot->replyText($event->getReplyToken(), $invalid_type_msg);
        continue;
    }

    $text = $event->getText();
    if ($text == '登録') {
        $bot->replyText($event->getReplyToken(), $event->getUserId());
        addUserId($event->getUserId());
    }

    $bot->replyText($event->getReplyToken(), $text);
}
