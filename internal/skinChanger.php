<?php

$GLOBALS["LOGIN_URL"] = "https://authserver.mojang.com/authenticate";
$GLOBALS["REFRESH_URL"] = "https://authserver.mojang.com/refresh";
$GLOBALS["VALIDATE_URL"] = "https://authserver.mojang.com/validate";
$GLOBALS["SIGNOUT_URL"] = "https://authserver.mojang.com/signout";
$GLOBALS["SKIN_URL"] = "https://api.mojang.com/user/profile/:uuid/skin";
$GLOBALS["CHALLENGES_URL"] = "https://api.mojang.com/user/security/challenges";
$GLOBALS["LOCATION_URL"] = "https://api.mojang.com/user/security/location";


//// Main Function
function changeSkin($username, $password, $security, $uuid, $skin, $type = "url"/* url/upload */, $model = "steve", &$skin_error, $accessToken, $clientToken, $tokenCallback)
{
    $spoofIp = "" . mt_rand(0, 255) . "." . mt_rand(0, 255) . "." . mt_rand(0, 255) . "." . mt_rand(0, 255);

    sleep(1);
    if (useTokenOrLogin($username, $password, $clientToken, $accessToken, $tokenCallback, $skin_error, $spoofIp) !== false) {
        sleep(1);
        if ((strlen($security) === 0 || empty($security)) || completeChallenges($username, $password, $accessToken, $security, $skin_error, $spoofIp)) {
            sleep(1);
            if ("url" === $type) {
                if (setSkinUrl($accessToken, $skin, $uuid, $model, $skin_error, $spoofIp)) {
//                    logout($username, $password, $spoofIp);
                    return true;
                }
            } else if ("upload" === $type) {
                if (uploadSkin($accessToken, $skin, $uuid, $model, $skin_error, $spoofIp)) {
//                    logout($username, $password, $spoofIp);
                    return true;
                }
            }
        }
    } else {
        if (!isset($skin_error) || empty($skin_error)) {
            $skin_error = "Login failed for unknown reason";
        }
    }
//    logout($username, $password, $spoofIp);
    return false;
}

function useTokenOrLogin($username, $password, &$clientToken, &$accessToken, $tokenCallback, &$skin_error, $spoofIp)
{
    $method = "n/a";
    if ($clientToken !== false && $accessToken !== false) {
        if (validate($clientToken, $accessToken, $spoofIp)) {
            sleep(1);
            // Token still valid
            $accessToken = refresh($clientToken, $accessToken, $spoofIp);
            $method = "accessToken";
            $tokenCallback($accessToken, $clientToken, $method);
            return true;
        }
        $skin_error = "Login via accessToken failed";
    }

    $clientToken = uniqid();
    logout($username, $password, $spoofIp);
    sleep(1);
    if ($accessToken = login($username, $password, $clientToken, $skin_error, $spoofIp)) {
        $method = "login";
        $tokenCallback($accessToken, $clientToken, $method);
        return true;
    } else {
        logout($username, $password, $spoofIp);
        return false;
    }
}

function login($username, $password, $clientToken, &$skin_error, $spoofIp)
{
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $GLOBALS["LOGIN_URL"]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
    $fields = array(
        "username" => $username,
        "password" => decryptPassword($password),
        "clientToken" => $clientToken
    );
    $field_string = json_encode($fields);
    curl_setopt($ch, CURLOPT_HEADER, false);
    curl_setopt($ch, CURLOPT_HTTPHEADER, array(
        "Content-Type: application/json",
        "REMOTE_ADDR: $spoofIp",
        "X-Forwarded-For: $spoofIp"
    ));
    curl_setopt($ch, CURLOPT_POSTFIELDS, $field_string);

    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

    $result = curl_exec($ch);
    $code = curl_getinfo($ch) ["http_code"];
    curl_close($ch);
    if ($code !== 200) {
        $skin_error = "Login Failed. (" . $code . ", " . (substr($username, 0, 4)) . "): " . $result;
        return false;
    }
    $json_result = json_decode($result, true);
    if (!isset ($json_result ["accessToken"])) {
        $skin_error = "Failed to retrieve access token. " . $result;
        return false;
    }
    $token = $json_result ["accessToken"];

    return $token;
}

function validate($clientToken, $accessToken, $spoofIp)
{
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $GLOBALS["VALIDATE_URL"]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
    $fields = array(
        "clientToken" => $clientToken,
        "accessToken" => $accessToken
    );
    $field_string = json_encode($fields);
    curl_setopt($ch, CURLOPT_HEADER, false);
    curl_setopt($ch, CURLOPT_HTTPHEADER, array(
        "Content-Type: application/json",
        "REMOTE_ADDR: $spoofIp",
        "X-Forwarded-For: $spoofIp"
    ));
    curl_setopt($ch, CURLOPT_POSTFIELDS, $field_string);

    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

    $result = curl_exec($ch);
    $code = curl_getinfo($ch) ["http_code"];
    curl_close($ch);

    return $code < 400;
}

function refresh($clientToken, $accessToken, $spoofIp)
{
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $GLOBALS["REFRESH_URL"]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
    $fields = array(
        "clientToken" => $clientToken,
        "accessToken" => $accessToken
    );
    $field_string = json_encode($fields);
    curl_setopt($ch, CURLOPT_HEADER, false);
    curl_setopt($ch, CURLOPT_HTTPHEADER, array(
        "Content-Type: application/json",
        "REMOTE_ADDR: $spoofIp",
        "X-Forwarded-For: $spoofIp"
    ));
    curl_setopt($ch, CURLOPT_POSTFIELDS, $field_string);

    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

    $result = curl_exec($ch);
    $code = curl_getinfo($ch) ["http_code"];
    curl_close($ch);
    if ($code !== 200) {
        $skin_error = "Session Refresh failed. (" . $code . "): " . $result;
        return false;
    }
    $json_result = json_decode($result, true);
    if (!isset ($json_result ["accessToken"])) {
        $skin_error = "Failed to retrieve access token. " . $result;
        return false;
    }
    $token = $json_result ["accessToken"];

    return $token;
}

function logout($username, $password, $spoofIp)
{
    // Logout
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $GLOBALS["SIGNOUT_URL"]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
    $fields = array(
        "username" => $username,
        "password" => decryptPassword($password)
    );
    $field_string = json_encode($fields);
    curl_setopt($ch, CURLOPT_HEADER, false);
    curl_setopt($ch, CURLOPT_HTTPHEADER, array(
        "Content-Type: application/json",
        "Content-Length: " . strlen($field_string),
        "REMOTE_ADDR: $spoofIp",
        "X-Forwarded-For: $spoofIp"
    ));
    curl_setopt($ch, CURLOPT_POSTFIELDS, $field_string);

    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

    curl_exec($ch);
    curl_close($ch);
    return true;
}


function completeChallenges($username, $password, $token, $security, &$skin_error, $spoofIp)
{
    // Check security
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $GLOBALS["LOCATION_URL"]);
    curl_setopt($ch, CURLOPT_HEADER, false);
    curl_setopt($ch, CURLOPT_HTTPHEADER, array(
        "Content-Type: application/json",
        "Authorization: Bearer $token",
        "X-Forwarded-For: $spoofIp"
    ));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);

    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);


    curl_exec($ch);
    $code = curl_getinfo($ch) ["http_code"];
    curl_close($ch);
    unset ($ch);
    ////////////////////////////
    if ($code !== 200) {// Not yet answered
        // Get the questions
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $GLOBALS["CHALLENGES_URL"]);
        curl_setopt($ch, CURLOPT_HEADER, false);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            "Content-Type: application/json",
            "Authorization: Bearer $token",
            "REMOTE_ADDR: $spoofIp",
            "X-Forwarded-For: $spoofIp"
        ));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);

        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);


        $result = curl_exec($ch);
        $result = json_decode($result, true);
        curl_close($ch);
        unset ($ch);

        try {
            // Post answers
            $answers = array();
            foreach ($result as $answer) {
//                if (isset($answer ["answer"])) {
                    $answers [] = array(
                        "id" => $answer ["answer"] ["id"],
                        "answer" => $security
                    );
//                }
            }
        } catch (Exception $e) {
            echo $result;
            throw $e;
        }

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $GLOBALS["LOCATION_URL"]);
        curl_setopt($ch, CURLOPT_HEADER, false);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            "Content-Type: application/json",
            "Authorization: Bearer $token",
            "REMOTE_ADDR: $spoofIp",
            "X-Forwarded-For: $spoofIp"
        ));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($answers));

        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);


        curl_exec($ch);
        $code = curl_getinfo($ch) ["http_code"];
        curl_close($ch);
        unset ($ch);
        if (!($code >= 200 && $code < 300)) {// Should be 204: No Content
            $skin_error = "Challenges Failed ($code)";
//            logout($username, $password, $spoofIp);
            return false;
        }
        // /Post answers
    }

    return true;
}

function setSkinUrl($token, $url, $uuid, $model, &$skin_error, $spoofIp)
{
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, str_replace(":uuid", $uuid, $GLOBALS["SKIN_URL"]));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_HTTPHEADER, array(
        "Content-Type: application/x-www-form-urlencoded",
        "Authorization: Bearer $token",
        "REMOTE_ADDR: $spoofIp",
        "X-Forwarded-For: $spoofIp"
    ));
    curl_post($ch, array(
        "model" => $model,
        "url" => $url
    ));

    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

    $result = curl_exec($ch);
    $code = curl_getinfo($ch) ["http_code"];
    curl_close($ch);

    $success = $code >= 200 && $code < 300;
    if (!$success) {
        $json_result = json_decode($result, true);
        $skin_error = "Url: " . $GLOBALS["SKIN_URL"] . ", ResponseCode: $code, Message: " . $json_result ["errorMessage"];
        return false;
    }

    return true;
}

function uploadSkin($token, $file, $uuid, $model, &$skin_error, $spoofIp)
{
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, str_replace(":uuid", $uuid, $GLOBALS["SKIN_URL"]));
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "PUT");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_SAFE_UPLOAD, false);
    curl_setopt($ch, CURLOPT_HTTPHEADER, array(
        "Content-Type: multipart/form-data",
        "Authorization: Bearer $token",
        "REMOTE_ADDR: $spoofIp",
        "X-Forwarded-For: $spoofIp"
    ));
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, array(
        "model" => $model,
        "file" => curl_file_create($file, "image/png", "file")
    ));

    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

    $result = curl_exec($ch);
    $code = curl_getinfo($ch) ["http_code"];
    curl_close($ch);

    $success = $code >= 200 && $code < 300;
    if (!$success) {
        $json_result = json_decode($result, true);
        $skin_error = "Url: " . $GLOBALS["SKIN_URL"] . ", ResponseCode: $code, Message: " . $json_result ["errorMessage"];
        return false;
    }

    return true;
}


function completeChallenge($ch, $token)
{
    $fields = array(
        "answer" => "Cd8NxjVMPgU3bNEh",
        "authenticityToken" => $token
    );

    curl_post($ch, $fields);
    return curl_exec($ch);
}

function curl_post($ch, $fields)
{
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($fields));
}