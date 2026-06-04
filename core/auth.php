<?php
require_once __DIR__ . "/app.php";

function requireLogin() {
    if (empty($_SESSION["user_id"])) {
        redirectToRoute("login");
    }
}
