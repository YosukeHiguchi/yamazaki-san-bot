<?php
require_once("config.php");
require_once __DIR__ . '/vendor/autoload.php';

$httpClient = new \LINE\LINEBot\HTTPClient\CurlHTTPClient(CHANNEL_ACCESS_TOKEN);
$bot = new \LINE\LINEBot($httpClient, ['channelSecret' => CHANNEL_SECRET]);


