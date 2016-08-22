<?php
function db() {
    $json = json_decode(file_get_contents("../internal/mongo.json"));
    $host = $json->host;
    $port = $json->port;
    $database = $json->database;
    $user = $json->login->user;
    $pass = $json->login->pass;
    $login_db = $json->login->db;

    $auth = "";
    if (!is_null($user) && !is_null($pass)) {
        $auth = "$user:$pass@";
    }
    try {
        $mongo = new MongoClient ("mongodb://$auth$host:$port/$login_db");
        $db = $mongo->selectDB($database);
    } catch (Exception $e) {
        exit("Database connection failed");
    }

    unset ($json);
    unset ($user);
    unset ($pass);

    return $db;
}

?>