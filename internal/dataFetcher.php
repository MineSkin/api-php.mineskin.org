<?php
function getSkinData($uuid)
{
    try {
        if ($data = file_get_contents("https://sessionserver.mojang.com/session/minecraft/profile/" . $uuid . "?unsigned=false")) {
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
}