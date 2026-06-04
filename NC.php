<?php
$NEXTCLOUD_URL = "https://nc.schp.mx"; // sin slash final
$ROOM_ID = "vwxavtex";
$USER = "claudflareca@gmail.com";
$TOKEN = "b7TNS-f36Jz-wzTjg-RPsPZ-EfzDc";

$MENSAJE = "🚀 Prueba desde CRM a Nextcloud, polilla enana " . date("Y-m-d H:i:s");

$url = $NEXTCLOUD_URL . "/ocs/v2.php/apps/spreed/api/v1/chat/" . $ROOM_ID;

$ch = curl_init($url);

curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => http_build_query([
        "message" => $MENSAJE
    ]),
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_USERPWD => $USER . ":" . $TOKEN,
    CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
    CURLOPT_HTTPHEADER => [
        "OCS-APIRequest: true",
        "Accept: application/json",
        "Content-Type: application/x-www-form-urlencoded"
    ],
    CURLOPT_SSL_VERIFYPEER => false,
]);

$response = curl_exec($ch);
$error = curl_error($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "<h3>Resultado:</h3>";
echo "<b>URL:</b> " . htmlspecialchars($url) . "<br>";
echo "<b>HTTP Code:</b> $http_code <br>";
echo "<b>Error CURL:</b> " . htmlspecialchars($error) . "<br><br>";
echo "<pre>" . htmlspecialchars($response) . "</pre>";
?>