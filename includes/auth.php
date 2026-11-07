
<?php

// Start the session if one has not been started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check whether the user is logged in
if (!isset($_SESSION["user_id"])) {
    header("Location: login.php");
    exit;
}

// Check if the current user is an administrator
function requireAdmin() {

    if (!isset($_SESSION["role"]) || $_SESSION["role"] !== "Admin") {
        http_response_code(403);
        die("Access denied. Administrator privileges required.");
    }

}

?>
    