<?php
function getSkinData($uuid, &$code = 0)
{
    try {
        $ch = curl_init("https://sessionserver.mojang.com/session/minecraft/profile/" . $uuid . "?unsigned=false");
        curl_setopt($ch, CURLOPT_USERAGENT, "MineSkin.org/1.0");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $data = curl_exec($ch);
        $info = curl_getinfo($ch);
        curl_close($ch);

        $code = $info["http_code"];
        if ($code === 200) {
            $data = json_decode($data, true);
            return array(
                "value" => $data["properties"][0]["value"],
                "signature" => $data["properties"][0]["signature"],
                "raw" => $data
            );
        }
    } catch (Exception $ignored) {
    }
    return false;

//    try {
//        if ($data = file_get_contents("https://sessionserver.mojang.com/session/minecraft/profile/" . $uuid . "?unsigned=false")) {
//            $data = json_decode($data, true);
//            return array(
//                "value" => $data["properties"][0]["value"],
//                "signature" => $data["properties"][0]["signature"],
//                "raw" => $data
//            );
//        }
//    } catch (Exception $ignored) {
//    }
//    return false;
}