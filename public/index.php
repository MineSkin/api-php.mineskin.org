<?php
require "../vendor/autoload.php";
include("../internal/Database.php");
include("../internal/utils.php");
include("../internal/skinChanger.php");
include("../internal/dataFetcher.php");

$app = new \Slim\Slim();

$app->hook("slim.before", function () use ($app) {
    header("Access-Control-Allow-Origin: *");
    if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
        header("Access-Control-Allow-Methods: POST, GET, OPTIONS, DELETE, PUT");
        header("Access-Control-Allow-Headers: X-Requested-With, Accept, Content-Type, Origin");
        header("Access-Control-Request-Headers: X-Requested-With, Accept, Content-Type, Origin");
        exit;
    }
});

$app->get("/", function () {
    echo "hi!";
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
        if ($size <= 0 || $size > 16000 /*(16KB)*/) {
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
            $json = dbToJson($cursor, true);
            $json = $json[0];
            if ($json["time"] > strtotime("1 hour ago")) {
                echoSkinData($cursor, $json, false);
                skins()->update(array("uuid" => $longUuid), array('$inc' => array("duplicate" => 1)));
                return;
            }
        }

        if ($skinData = getSkinData($shortUuid, $skinDataError)) {
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
                    "type" => "user",
                    "duplicate" => 0,
                    "views" => 1
                );

                skins()->insert($data);
                echoSkinData(null, $data, true);
            }
        } else {
            echoData(array("error" => "invalid user / failed to get skin data. Return code #" . $skinDataError), 400);
        }
    });

});

$app->group("/get", function () use ($app) {

    $app->get("/delay", function () use ($app) {
        $time = time();
        $ip = $app->request()->getIp();

        $delay = getGeneratorDelay();

        $cursor = traffic()->find(array("ip" => $ip));
        if ($cursor->count() >= 1) {
            $json = dbToJson($cursor, true)[0];
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

    $app->get("/stats", function () use ($app) {
        $cursor = skins()->find();
        $count = $cursor->count();
        $duplicate = 0;
        foreach ($cursor as $doc) {
            $duplicate += $doc["duplicate"];
        }

        $private = skins()->find(array("visibility" => array('$ne' => 0)))->count();

        $yesterday = strtotime("1 day ago");
        $lastDay = skins()->find(array("time" => array('$gt' => $yesterday)))->count();

        $accounts = accounts()->find(array("enabled" => true))->count();

        $delay = getGeneratorDelay();

        echoData(array(
            "total" => ($count + $duplicate),
            "unique" => $count,
            "duplicate" => $duplicate,
            "private" => $private,
            "lastDay" => $lastDay,
            "accounts" => $accounts,
            "delay" => $delay
        ));
    });

    $app->get("/accounts", function () use ($app) {
        $cursor = accounts()->find(array(), array("_id" => 0, "id" => 1, "uuid" => 1, "lastUsed" => 1, "enabled" => 1, "hasError" => 1));
        $json = dbToJson($cursor, true);

        echoData($json);
    });

    $app->get("/id/:id", function ($id) use ($app) {
        $cursor = skins()->find(array("id" => (int)$id));
        if ($cursor->count() >= 1) {
            echoSkinData($cursor, $json, false);
            skins()->update(array("id" => (int)$id), array('$inc' => array("views" => 1)));
        } else {
            echoData(array("error" => "skin not found"), 404);
        }
    });

    $app->get("/list(/:page)", function ($page = 1) use ($app) {
        $page = max((int)$page, 1);
        $size = max((int)$app->request()->params("size", 16), 1);
        $sort = (int)$app->request()->params("sort", -1);
        $filter = $app->request()->params("filter");

        $query = array("visibility" => 0);
        if (!empty($filter)) {
            $query["name"] = array('$regex' => new MongoRegex("/$filter/i"));
        }
        $cursor = skins()
            ->find($query,
                array("_id" => 0, "id" => 1, "name" => 1, "url" => 1))
            ->skip($size * ($page - 1))->limit($size)->sort(array("id" => $sort));
        $json = dbToJson($cursor, true);

        $amount = skins()->find($query)->count();
        echoData(array("skins" => $json, "page" => array(
            "index" => $page,
            "amount" => round($amount / $size),
            "total" => $amount
        ), "filter" => $filter));
    });

    $app->get("/top(/:page)", function ($page = 1) use ($app) {
        $page = max((int)$page, 1);
        $size = max((int)$app->request()->params("size", 16), 1);
        $filter = $app->request()->params("filter");

        $query = array("visibility" => 0, "views" => array('$gt' => 0));
        if (!empty($filter)) {
            $query["name"] = array('$regex' => new MongoRegex("/$filter/i"));
        }
        $cursor = skins()
            ->find($query,
                array("_id" => 0, "id" => 1, "name" => 1, "url" => 1, "views" => 1))
            ->skip($size * ($page - 1))->limit($size)->sort(array("views" => -1));
        $json = dbToJson($cursor, true);

        $amount = $cursor->count();
        echoData(array("skins" => $json, "page" => array(
            "index" => $page,
            "amount" => round($amount / $size),
            "total" => $amount
        ), "filter" => $filter));
    });

});

$app->group("/validate", function () use ($app) {

    $app->get("/user/:name", function ($name) use ($app) {

        $content = file_get_contents("https://api.mojang.com/users/profiles/minecraft/$name");

        $valid = false;
        $uuid = "";
        if ($content !== false) {
            if (strlen($content) > 0) {
                $json = json_decode($content, true);
                if ($json !== false) {
                    $valid = true;
                    $uuid = $json ["id"];
                    $name = $json ["name"];
                }
            }
        }

        echoData(array(
            "valid" => $valid,
            "uuid" => $uuid,
            "name" => $name
        ));
    });

});

$app->group("/render", function () use ($app) {

    $app->get("/:id/skin(.png)", function ($id) use ($app) {
        $cursor = skins()->find(array("id" => (int)$id));
        if ($cursor->count() >= 1) {
            $json = dbToJson($cursor, true)[0];
            header("Location: https://api.mineskin.org/render/skin/?url=" . $json["url"]);
        }
    });

    $app->get("/:id/head(.png)", function ($id) use ($app) {
        $cursor = skins()->find(array("id" => (int)$id));
        if ($cursor->count() >= 1) {
            $json = dbToJson($cursor, true)[0];
            header("Location: https://api.mineskin.org/render/head/?url=" . $json["url"]);
        }
    });

    $app->get("/skin", function () use ($app) {
        $url = $app->request()->params("url");
        $options = $app->request()->params("options", "&aa=true");
        if (is_null($url)) {
            exit("Missing URL");
        }

        if (strpos($url, ".png") !== false) {
            $url = str_replace(".png", "", $url);
        }

        if (strpos($url, "tools.inventivetalent.org/skinrender") === false) {
            $url = "http://tools.inventivetalent.org/skinrender/3d.php?headOnly=false&user=" . $url . $options;
        }


        header('Pragma: public');
        header('Cache-Control: max-age=604800');
        header('Expires: ' . gmdate('D, d M Y H:i:s \G\M\T', time() + 604800));

        $imginfo = getimagesize($url);
        header("Content-type: " . $imginfo ['mime']);
        readfile($url);
        exit();
    });

    $app->get("/head", function () use ($app) {
        $url = $app->request()->params("url");
        $options = $app->request()->params("options", "&aa=true");
        if (is_null($url)) {
            exit("Missing URL");
        }

        if (strpos($url, ".png") !== false) {
            $url = str_replace(".png", "", $url);
        }

        if (strpos($url, "tools.inventivetalent.org/skinrender") === false) {
            $url = "http://tools.inventivetalent.org/skinrender/3d.php?headOnly=true&user=" . $url . $options;
        }


        header('Pragma: public');
        header('Cache-Control: max-age=604800');
        header('Expires: ' . gmdate('D, d M Y H:i:s \G\M\T', time() + 604800));

        $imginfo = getimagesize($url);
        header("Content-type: " . $imginfo ['mime']);
        readfile($url);
        exit();
    });

});

$app->group("/admin", function () use ($app) {

    $app->get("/accounts", function () use ($app) {
        if (authenticateUser()) {
            $sortField = "id";
            $sortDir = 1;

            if (isset($_COOKIE["MSA_SORT_FIELD"])) {
                $sortField = $_COOKIE["MSA_SORT_FIELD"];
            }
            setcookie("MSA_SORT_FIELD", $sortField, strtotime("+1 month"), "/");
            if (isset($_COOKIE["MSA_SORT_DIR"])) {
                $sortDir = (int)$_COOKIE["MSA_SORT_DIR"];
            }
            setcookie("MSA_SORT_DIR", $sortDir, strtotime("+1 month"), "/");

            $cursor = accounts()
                ->find(array(), array("_id" => 0, "id" => 1, "username" => 1, "uuid" => 1, "lastUsed" => 1, "enabled" => 1, "hasError" => 1, "lastError" => 1, "lastGen.type" => 1, "lastGen.image" => 1))
                ->sort(array($sortField => $sortDir));
            $json = dbToJson($cursor, true);

            echo "<head>";
            echo '<link href="https://maxcdn.bootstrapcdn.com/bootstrap/3.3.7/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-BVYiiSIFeK1dGmJRAkycuHAHRg32OmUcww7on3RYdg4Va+PmSTsz/K68vbdEjh4u" crossorigin="anonymous">';
            echo "
<style>
.account-disabled > h2{
color:#777;
}
.account-error{
border:solid red;
}
.account-error > h2{
color:red;
}
</style>";
            echo "</head>";
            echo "<div class='container'>";
            echo "<h1>MineSkin Accounts</h1>";

            $lastId = -1;
            foreach ($json as $account) {
                if (!isset($account["hasError"])) {
                    $account["hasError"] = false;
                }
                if (!isset($account["lastError"])) {
                    $account["lastError"] = "";
                }

                echo "<form action='/admin/accounts/update/" . $account["id"] . "' method='post'>";
                echo "<div id='account-" . $account["id"] . "' class='account " . ($account["enabled"] ? "account-enabled" : "account-disabled") . " " . ($account["hasError"] ? "account-error" : "") . "'>";
                echo "<h2>#" . $account["id"] . "&nbsp;" . $account["username"] . "</h2>";
                if (isset($_GET["updateId"]) && $account["id"] == $_GET["updatedId"]) {
                    echo "<i>Updated!</i><br/>";
                }
                echo "<strong>Username</strong>&nbsp;<input id='username' name='username' type='text' readonly value='" . $account["username"] . "'><br/>";
                echo "<strong>UUID</strong>&nbsp;<input id='uuid' name='uuid' type='text' readonly value='" . $account["uuid"] . "'><br/>";
                echo "<strong>Last Used</strong>&nbsp;<input id='lastUsed' name='lastUsed' type='number' value='" . $account["lastUsed"] . "'>&nbsp;(" . date("F j, Y \a\\t g:ia", $account["lastUsed"]) . ")<br/>";
                echo "<strong>Enabled</strong>&nbsp;<input id='enabled' name='enabled' type='checkbox' " . ($account["enabled"] ? "checked" : "") . "><br/>";
                echo "<strong>Has Error</strong>&nbsp;<input id='hasError' name='hasError' type='checkbox' " . ($account["hasError"] ? "checked" : "") . "><br/>";
                if ($account["hasError"]) {
                    echo "<input id='lastError' name='lastError' type='text' style='width:100%;' value='" . $account["lastError"] . "'><br/>";
                    echo "<strong>Last Image (" . $account["lastGen"]["type"] . ")</strong>: " . $account["lastGen"]["image"];
                } else {
                    echo "<input id='lastError' name='lastError' type='hidden' value=''>";
                }
                echo "<br/><button type='submit'>Update</button>";
                echo "<hr/>";
                echo "</div>";
                echo "</form>";

                if ($account["id"] > $lastId) {
                    $lastId = $account["id"];
                }
            }

            echo "<br/><hr/>";
            echo "<form action='/admin/accounts/add' method='post'>";
            echo "<strong>ID</strong>&nbsp;<input id='id' name='id' type='number' readonly value='" . ($lastId + 1) . "'><br/>";
            echo "<strong>Username</strong>&nbsp;<input id='username' name='username' type='text' required><br/>";
            echo "<strong>Password</strong>&nbsp;<input id='password' name='password' type='password' required><br/>";
            echo "<strong>UUID</strong>&nbsp;<input id='uuid' name='uuid' type='text' required><br/>";
            echo "<strong>Security Answer</strong>&nbsp;<input id='security' name='security' type='text'><br/>";
            echo "<br/><button type='submit'>Add Account</button>";
            echo "</form>";

            echo "</div>";
        } else {
            exit();
        }
    });

    $app->post("/accounts/update/:id", function ($id) use ($app) {
        if (authenticateUser()) {
            if (!isset($_POST["username"]) || !isset($_POST["uuid"])) {
                echoData(array("error" => "missing data"));
                exit();
            }

            $enabled = isset($_POST["enabled"]) && $_POST["enabled"];
            $hasError = isset($_POST["hasError"]) && $_POST["hasError"];
            $lastError = $_POST["lastError"];
            $lastUsed = (int)$_POST["lastUsed"];

            accounts()->update(
                array("id" => (int)$id, "username" => $_POST["username"], "uuid" => $_POST["uuid"]),
                array('$set' => array(
                    "enabled" => $enabled,
                    "hasError" => $hasError,
                    "lastError" => $lastError,
                    "lastUsed" => $lastUsed
                )));

            header("Location: /admin/accounts?updatedId=" . $id . "#account-" . $id);
        }
    });

    $app->post("/accounts/add", function () use ($app) {
        if (authenticateUser()) {
            if (!isset($_POST["id"]) || !isset($_POST["username"]) || !isset($_POST["uuid"]) || !isset($_POST["password"]) || !isset($_POST["security"])) {
                echoData(array("error" => "missing data"));
                exit();
            }

            $id = (int)$_POST["id"];
            $username = $_POST["username"];
            $uuid = $_POST["uuid"];
            $password = $_POST["password"];
            $security = $_POST["security"];

            accounts()->insert(array(
                "id" => $id,
                "username" => $username,
                "password" => encryptPassword($password),
                "security" => $security,
                "uuid" => $uuid,

                "lastUsed" => 0,
                "enabled" => false,
                "hasError" => false,
                "lastError" => "",
                "lastGen" => array(
                    "type" => "",
                    "image" => ""
                )
            ));

            header("Location: /admin/accounts?updatedId=" . $id . "#account-" . $id);
        }
    });

});

$app->run();

function generateData($app, $temp, $name, $model, $visibility, $type, $image)
{
    $hash = md5_file($temp);
    $cursor = skins()->find(array("hash" => $hash));
    if ($cursor->count() >= 1) {// Already generated
        echoSkinData($cursor, $json, false);
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

            if ($skinData = getSkinData($account["uuid"], $skinDataError)) {
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
                    "type" => $type,
                    "duplicate" => 0,
                    "views" => 1
                );

                skins()->insert($data);
                echoSkinData(null, $data, true);
                return;
            } else {
                echoData(array("error" => "failed to get skin data. Return code #" . $skinDataError), 500);
                return;
            }

        } else {
            // Update the lastUsed field anyway, to prevent a (probably) faulty account from being used again
            accounts()->update(
                array("username" => $account["username"]),
                array('$set' => array("lastUsed" => $time)));

            if ("ResponseCode: 400, Message: Could not set skin from the provided url." === $skin_error) {
                // Ignore this error since it's caused by an invalid skin image, not the account

                echoData(array("error" => "Couldn't generate data from the provided image",
                    "details" => $skin_error), 500);
                return;
            }
            if (strpos($skin_error, "Invalid credentials. Invalid username or password.") !== false) {

                echoData(array("error" => "Failed to log into the account (#" . $account["id"] . "). Please try again later",
                    "details" => $skin_error), 500);
                return;
            }

            echoData(array("error" => "Unknown account error. Try again later.",
                "details" => $skin_error), 500);

            accounts()->update(
                array("username" => $account["username"]),
                array('$set' => array(
                    "hasError" => true,
                    "enabled" => false,
                    "lastError" => $skin_error,
                    "lastGen.type" => $type,
                    "lastGen.image" => $image)));

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
        $json = dbToJson($cursor, true)[0];
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
    if (image_type_to_mime_type($type) !== "image/png") {
        if ($cancelRequest) {
            echoData(array("error" => "Invalid mime type. Must be image/png"), 400);
        }
        return false;
    }

    return true;
}

function getGeneratorDelay()
{
    $count = accounts()->find(array("enabled" => true))->count();
    return round(40 / max(1, $count), 2);
}

function echoSkinData($cursor, &$json = null, $delay = true, $return = false)
{
    if (is_null($json)) {
        $json = dbToJson($cursor, true);
        $json = $json[0];
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
        "nextRequest" => 0,
        "private" => ($json["visibility"] != 0),
        "views" => $json["views"]
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

function authenticateUser()
{
    if (!isset($_SERVER["PHP_AUTH_USER"])) {
        header('WWW-Authenticate: Basic realm="Mineskin Admin"');
        header('HTTP/1.0 401 Unauthorized');
        echoData(array("error" => "Unauthorized access"));
        exit();
    } else if (empty($_SERVER["PHP_AUTH_USER"]) || empty($_SERVER["PHP_AUTH_PW"])) {
        echoData(array("error" => "Unauthorized access. Missing username or password."));
        exit(403);
    } else {
        $user = $_SERVER["PHP_AUTH_USER"];
        $user = str_replace("../", "", $user);
        $user = str_replace("./", "", $user);
        $userFile = "../internal/auth/passwords/" . md5($user) . ".txt";
        if (!file_exists($userFile)) {
            echoData(array("error" => "Unauthorized access. User does not exist."));
            exit(403);
        } else {
            $passwordHash = hash("sha512", $_SERVER["PHP_AUTH_PW"]);
            $fileHash = file_get_contents($userFile);
            if ($fileHash !== $passwordHash) {
                echoData(array("error" => "Unauthorized access. Invalid password."));
                exit(403);
            } else {
                // Authorized
                return true;
            }
        }
    }
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