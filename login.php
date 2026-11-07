
<?php

session_start();

require_once __DIR__ . "/config/database.php";

// If the user is already logged in, redirect them
if (isset($_SESSION['user_id'])) {
    header("Location: dashboard.php");
    exit;
}

$error = "";
$username = "";

// Check if the login form was submitted
if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $username = trim($_POST["username"] ?? "");
    $password = $_POST["password"] ?? "";

    // Find the active account in the database
    $sql = "SELECT * FROM users
            WHERE username = ?
            AND account_status = 'Active'";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([$username]);

    $user = $stmt->fetch();

    // Verify the password
    if ($user && password_verify($password, $user["password_hash"])) {

        session_regenerate_id(true);

        $_SESSION["user_id"] = $user["user_id"];
        $_SESSION["username"] = $user["username"];
        $_SESSION["role"] = $user["role"];

        header("Location: dashboard.php");
        exit;

    } else {
        $error = "Invalid username or password.";
    }
}

?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Login | Solid Waste Management System</title>

    <link rel="stylesheet" href="assets/style.css">
</head>

<body class="login-page">

    <div class="login-container">

        <div class="login-header">
            <h1>Solid Waste Management</h1>
            <p>Collection Scheduling and Monitoring System</p>
        </div>

        <form method="POST" action="">

            <h2>Administrator / Staff Login</h2>

            <?php if ($error !== ""): ?>
                <div class="error-message">
                    <?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>

            <div class="form-group">
                <label for="username">Username</label>

                <input
                    type="text"
                    id="username"
                    name="username"
                    placeholder="Enter your username"
                    value="<?php echo htmlspecialchars($username); ?>"
                    required
                >
            </div>

            <div class="form-group">
                <label for="password">Password</label>

                <input
                    type="password"
                    id="password"
                    name="password"
                    placeholder="Enter your password"
                    required
                >
            </div>

            <button type="submit" class="login-button">
                Login
            </button>

        </form>

        <p class="login-footer">
            Solid Waste Collection Scheduling and Monitoring System
        </p>

    </div>

</body>
</html>
