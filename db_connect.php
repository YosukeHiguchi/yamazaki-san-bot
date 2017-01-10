<?php

$dsn = 'mysql:host=mysql329.db.sakura.ne.jp;dbname=mazak_yamazaki_san;charset=utf8';
$user = 'mazak';
$password = '9m3b58axw6';

try {
    $dbh = new PDO($dsn, $user, $password);
} catch (PDOException $e) {
    echo "test";
    exit;
}

function addUserId($user_id) {
    global $dbh;

    $strSQL = "INSERT INTO test (user_id) VALUES (:user_id)";
    $stmt = $dbh->prepare($strSQL);
    $stmt->bindParam(':user_id', $user_id);
    $stmt->execute();
}

function getUserId($id) {
    global $dbh;

    $strSQL = "SELECT user_id FROM test WHERE id = :id";
    $stmt = $dbh->prepare($strSQL);
    $stmt->bindParam(':id', $id);
    $stmt->execute();
    $result = $stmt->fetch(PDO::FETCH_ASSOC);

    return $result['user_id'];
}
