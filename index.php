
<?php

session_start();

// Redirect based on login status.
if (isset($_SESSION["user_id"])) {
    header("Location: dashboard.php");
    exit;
} else {
    header("Location: login.php");
    exit;
}

?>
