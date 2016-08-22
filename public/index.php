<?php
require "../vendor/autoload.php";
include("../internal/Database.php");
include("../internal/utils.php");
include("../internal/skinChanger.php");
include("../internal/dataFetcher.php");

$app = new \Slim\Slim();

$app->get("/", function () {
    echo "hi!";
});

//TODO: remove
$app->group("/test", function () use ($app) {
    $app->get("/encrypt/:text", function ($text) use ($app) {
        echo encryptPassword($text);
    });
});

$app->group("/generate", function () use ($app) {

    $app->post("/url", function () use ($app) {
        $url = $app->request()->params("url");
        $model = $app->request()->params("model", "steve");
        $visibility = (int)$app->request()->params("visibility", "0");// 0 = default; 1 = private
        $name = $app->request()->params("name", "");

        if (is_null($url)) {
            echoData(array("error" => "URL not set"), 400);
            return;
        }
        if (!checkTraffic($app)) {
            return;
        }

        $remoteSize = curl_get_file_size($url);
        if ($remoteSize <= 0 || $remoteSize > 102400/*(100MB)*/) {
            echoData(array("error" => "invalid file size"));
            return;
        }

        $temp = tempnam(sys_get_temp_dir(), "skin_url");
        file_put_contents($temp, file_get_contents($url));
        if (!validateImage($temp)) {
            return;
        }

        generateData($app, $temp, $name, $model, $visibility, "url", $url);
    });

    $app->post("/upload", function () use ($app) {
        if (!isset($_FILES["file"])) {
            echoData(array("error" => "File not set"), 400);
            return;
        }
        $model = $app->request()->params("model", "steve");
        $visibility = (int)$app->request()->params("visibility", "0");// 0 = default; 1 = private
        $name = $app->request()->params("name", "");

        if (!checkTraffic($app)) {
            return;
        }

        $size = $_FILES["file"]["size"];
        if ($size <= 0 || $size > 102400/*(100MB)*/) {
            echoData(array("error" => "invalid file size"));
            return;
        }

        $temp = $_FILES["file"]["tmp_name"];
        if (!validateImage($temp)) {
            return;
        }

        generateData($app, $temp, $name, $model, $visibility, "upload", $temp);
    });

    $app->get("/user/:uuid", function ($uuid) use ($app) {
        $visibility = (int)$app->request()->params("visibility", "0");// 0 = default; 1 = private
        $name = $app->request()->params("name", "");

        if (!checkTraffic($app)) {
            return;
        }
        $time = time();
        $ip = $app->request()->getIp();


        $shortUuid = $uuid;
        $longUuid = $uuid;


        if (strpos($shortUuid, "-") !== false) {
            $shortUuid = str_replace("-", "", $shortUuid);
        }
        if (strpos($longUuid, "-") === false) {
            $longUuid = addUuidDashes($longUuid);
        }


        $cursor = skins()->find(array("uuid" => $longUuid));
        if ($cursor->count() >= 1) {// Already exists
            echoSkinData($cursor, $json, false);
            skins()->update(array("uuid" => $longUuid), array('$inc' => array("duplicate" => 1)));
            return;
        }

        if ($skinData = getSkinData($shortUuid)) {
            $textureUrl = json_decode(base64_decode($skinData["value"]), true)["textures"]["SKIN"]["url"];

            $temp = tempnam(sys_get_temp_dir(), "skin_upload");
            file_put_contents($temp, file_get_contents($textureUrl));
            $hash = md5_file($temp);

            $cursor = skins()->find(array("hash" => $hash));
            if ($cursor->count() >= 1) {// Already generated
                echoSkinData($cursor, $json, false);
                skins()->update(array("hash" => $hash), array('$inc' => array("duplicate" => 1)));
                return;
            } else {
                traffic()->update(array(
                    "ip" => $ip),
                    array('$set' => array(
                        "ip" => $ip,
                        "lastRequest" => $time
                    )), array("upsert" => true));

                $cursor = skins()->find()->sort(array("id" => -1))->limit(1);
                $lastId = dbToJson($cursor, true)[0]["id"];

                $data = array(
                    "_id" => $hash,
                    "id" => (int)($lastId + 1),
                    "hash" => $hash,
                    "name" => $name,
                    "model" => "unknown",
                    "visibility" => (int)$visibility,
                    "uuid" => $longUuid,
                    "value" => $skinData["value"],
                    "signature" => $skinData["signature"],
                    "url" => $textureUrl,
                    "time" => $time,
                    "account" => -1,
                    "type" => "user"
                );

                skins()->insert($data);
                echoSkinData(null, $data, true);
            }
        } else {
            echoData(array("error" => "invalid user"), 400);
        }
    });

});

$app->group("/get", function () use ($app) {

    $app->get("/delay", function () use ($app) {
        $time = time();
        $ip = $app->request()->getIp();

        $delay = getGeneratorDelay();

        $cursor = traffic()->find(array("ip" => $ip));
        if ($cursor->count() >= 0) {
            $json = dbToJson($cursor);
            $lastRequest = $json["lastRequest"];

            echoData(array(
                "delay" => $delay,
                "next" => ($lastRequest + $delay),
                "nextRelative" => (($lastRequest + $delay) - $time)));
        } else {// First request
            echoData(array(
                "delay" => $delay,
                "next" => $time,
                "nextRelative" => 0));
        }
    });

    $app->get("/id/:id", function ($id) use ($app) {
        $cursor = skins()->find(array("id" => (int)$id));
        if ($cursor->count() >= 1) {
            echoSkinData($cursor, $json, false);
        } else {
            echoData(array("error" => "skin not found"), 404);
        }
    });

    $app->get("/list(/:page)", function ($page = 1) use ($app) {
        $page = max((int)$page, 1);
        $size = max((int)$app->request()->params("size", 16), 1);

        $cursor = skins()
            ->find(array("visibility" => 0),
                array("_id" => 0, "id" => 1, "name" => 1, "url" => 1))
            ->skip($size * ($page - 1))->limit($size)->sort(array("id" => 1));
        $json = dbToJson($cursor, true);

        echoData($json);
    });

});


$app->run();

function generateData($app, $temp, $name, $model, $visibility, $type, $image)
{
    $hash = md5_file($temp);
    $cursor = skins()->find(array("hash" => $hash));
    if ($cursor->count() >= 1) {// Already generated
        echoSkinData($cursor, null, false);
        skins()->update(array("hash" => $hash), array('$inc' => array("duplicate" => 1)));
        return;
    } else {// Generate new
        $time = time();
        $ip = $app->request()->getIp();

        $cursor = accounts()
            ->find(array(
                "enabled" => true,
                "lastUsed" => array(
                    '$lt' => ($time - 30)
                )))
            ->sort(array("lastUsed" => 1))
            ->limit(1);
        if ($cursor->count() <= 0) {
            echoData(array("error" => "no accounts available"), 404);
            return;
        }
        $account = dbToJson($cursor, true)[0];

        if (changeSkin($account["username"], $account["password"], $account["security"], $account["uuid"], $image, $type, $model, $skin_error)) {
            accounts()->update(
                array("username" => $account["username"]),
                array('$set' => array("lastUsed" => $time)));

            traffic()->update(array(
                "ip" => $ip),
                array('$set' => array(
                    "ip" => $ip,
                    "lastRequest" => $time
                )), array("upsert" => true));

            $cursor = skins()->find()->sort(array("id" => -1))->limit(1);
            $lastId = dbToJson($cursor, true)[0]["id"];

            $skinData = getSkinData($account["uuid"]);
            $textureUrl = json_decode(base64_decode($skinData["value"]), true)["textures"]["SKIN"]["url"];
            $data = array(
                "_id" => $hash,
                "id" => (int)($lastId + 1),
                "hash" => $hash,
                "name" => $name,
                "model" => $model,
                "visibility" => (int)$visibility,
                "uuid" => randomUuid(),
                "value" => $skinData["value"],
                "signature" => $skinData["signature"],
                "url" => $textureUrl,
                "time" => $time,
                "account" => (int)$account["id"],
                "type" => $type
            );

            skins()->insert($data);
            echoSkinData(null, $data, true);
        } else {
            echoData(array("error" => "failed to generate skin",
                "details" => $skin_error), 500);
            return;
        }
    }
}

function checkTraffic($app, $cancelRequest = true)
{
    $time = time();
    $ip = $app->request()->getIp();

    $cursor = traffic()->find(array("ip" => $ip));
    if ($cursor->count() >= 1) {
        $json = dbToJson($cursor);
        $lastRequest = $json["lastRequest"];

        $delay = getGeneratorDelay();
        if ($lastRequest > $time - $delay) {
            $allowed = false;
        } else {
            $allowed = true;
        }
    } else {
        // First ever request
        $allowed = true;
    }

    if ($cancelRequest) {
        if (!$allowed) {
            echoData(array("error" => "Too many requests"), 429);
        }
    }
    return $allowed;
}

function validateImage($file, $cancelRequest = true)
{
    $imgSize = getimagesize($file);
    if ($imgSize === false) {
        if ($cancelRequest) {
            echoData(array("error" => "invalid image"), 400);
        }
        return false;
    }

    list($width, $height, $type, $attr) = $imgSize;
    if (($width != 64) || ($height != 64 && $height != 32)) {
        if ($cancelRequest) {
            echoData(array("error" => "Invalid skin dimensions. Must be 64x32."), 400);
        }
        return false;
    }

    return true;
}

function getGeneratorDelay()
{
    $count = accounts()->find(array("enabled" => true))->count();
    return round(35 / max(1, $count), 2);
}

function echoSkinData($cursor, &$json = null, $delay = true, $return = false)
{
    if (is_null($json)) {
        $json = dbToJson($cursor);
    }
    $data = array(
        "id" => $json["id"],
        "name" => $json["name"],
        "data" => array(
            "uuid" => $json["uuid"],
            "texture" => array(
                "value" => $json["value"],
                "signature" => $json["signature"],
                "url" => $json["url"]
            )
        ),
        "timestamp" => $json["time"],
        "accountId" => $json["account"],
        "nextRequest" => 0
    );

    if ($delay) {
        $data["nextRequest"] = getGeneratorDelay();
    }

    if ($return) {
        return $data;
    } else {
        echoData($data);
    }
}

function echoData($json, $status = 0)
{
    $app = \Slim\Slim::getInstance();

    $app->response()->header("X-Api-Time", time());
    $app->response()->header("Connection", "close");

    $paramPretty = $app->request()->params("pretty");
    $pretty = true;
    if (!is_null($paramPretty)) {
        $pretty = $paramPretty !== "false";
    }

    if ($status !== 0) {
        $app->response->setStatus($status);
        http_response_code($status);
    }

    $app->contentType("application/json; charset=utf-8");
    header("Content-Type: application/json; charset=utf-8");

    if ($pretty) {
        $serialized = json_encode($json, JSON_PRETTY_PRINT, JSON_UNESCAPED_UNICODE);
    } else {
        $serialized = json_encode($json, JSON_UNESCAPED_UNICODE);
    }

    $jsonpCallback = $app->request()->params("callback");
    if (!is_null($jsonpCallback)) {
        echo $jsonpCallback . "(" . $serialized . ")";
    } else {
        echo $serialized;
    }
}

function dbToJson($cursor, $forceArray = false)
{
    $isArray = $cursor->count() > 1;
    $json = array();
    foreach ($cursor as $k => $row) {
        if ($isArray || $forceArray) {
            $json [] = $row;
        } else {
            return $row;
        }
    }


    return $json;
}


function accounts()
{
    $db = db();
    return $db->accounts;
}

function skins()
{
    $db = db();
    return $db->skins;
}

function traffic()
{
    $db = db();
    return $db->traffic;
}