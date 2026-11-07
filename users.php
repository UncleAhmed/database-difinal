<?php
require_once "includes/auth.php";
require_once "config/database.php";

// Only administrators can access User Management.
requireAdmin();

// Create CSRF token.
if (empty($_SESSION["csrf_token"])) {
    $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
}

$message = "";
$error = "";

// Safely display text.
function e($value) {
    return htmlspecialchars((string)($value ?? ""), ENT_QUOTES, "UTF-8");
}

// Handle form submissions.
if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $token = $_POST["csrf_token"] ?? "";

    if (!hash_equals($_SESSION["csrf_token"], $token)) {
        $error = "Invalid request. Please refresh the page.";
    } else {

        $action = $_POST["action"] ?? "";

        // ADD USER
        if ($action === "add") {

            $username = trim($_POST["username"] ?? "");
            $password = $_POST["password"] ?? "";
            $role = $_POST["role"] ?? "";

            if ($username === "" || $password === "") {
                $error = "Please complete all required fields.";
            } elseif (!preg_match('/^[a-zA-Z0-9_.-]{3,30}$/', $username)) {
                $error = "Username must be 3–30 characters and contain only letters, numbers, dots, underscores, or hyphens.";
            } elseif (strlen($password) < 8) {
                $error = "Password must contain at least 8 characters.";
            } elseif (!in_array($role, ["Admin", "Staff"], true)) {
                $error = "Please select a valid user role.";
            } else {

                try {
                    $password_hash = password_hash($password, PASSWORD_DEFAULT);

                    $sql = "
                        INSERT INTO users
                            (username, password_hash, role, personnel_id, account_status)
                        VALUES
                            (:username, :password_hash, :role, NULL, 'Active')
                    ";

                    $stmt = $pdo->prepare($sql);
                    $stmt->execute([
                        ":username" => $username,
                        ":password_hash" => $password_hash,
                        ":role" => $role
                    ]);

                    header("Location: users.php?success=added");
                    exit;

                } catch (PDOException $e) {
                    if ($e->getCode() === "23000") {
                        $error = "That username is already registered.";
                    } else {
                        $error = "Unable to create the user account.";
                    }
                }
            }
        }

        // CHANGE ACCOUNT STATUS
        elseif ($action === "toggle") {

            $user_id = (int)($_POST["user_id"] ?? 0);

            if ($user_id <= 0) {
                $error = "Invalid user account.";
            } elseif ($user_id === (int)$_SESSION["user_id"]) {
                $error = "You cannot deactivate your own account.";
            } else {

                $stmt = $pdo->prepare("
                    SELECT user_id, role, account_status
                    FROM users
                    WHERE user_id = ?
                ");
                $stmt->execute([$user_id]);
                $target = $stmt->fetch();

                if (!$target) {
                    $error = "User account not found.";
                } else {

                    $new_status = $target["account_status"] === "Active"
                        ? "Inactive"
                        : "Active";

                    // Prevent deactivating the last active administrator.
                    if ($target["role"] === "Admin" && $new_status === "Inactive") {
                        $stmt = $pdo->query("
                            SELECT COUNT(*)
                            FROM users
                            WHERE role = 'Admin'
                              AND account_status = 'Active'
                        ");

                        if ((int)$stmt->fetchColumn() <= 1) {
                            $error = "You cannot deactivate the last active administrator.";
                        }
                    }

                    if ($error === "") {
                        $stmt = $pdo->prepare("
                            UPDATE users
                            SET account_status = ?
                            WHERE user_id = ?
                        ");
                        $stmt->execute([$new_status, $user_id]);

                        header("Location: users.php?success=status");
                        exit;
                    }
                }
            }
        }

        // DELETE USER
        elseif ($action === "delete") {

            $user_id = (int)($_POST["user_id"] ?? 0);

            if ($user_id <= 0) {
                $error = "Invalid user account.";
            } elseif ($user_id === (int)$_SESSION["user_id"]) {
                $error = "You cannot delete your own account.";
            } else {

                $stmt = $pdo->prepare("
                    SELECT user_id, role, account_status
                    FROM users
                    WHERE user_id = ?
                ");
                $stmt->execute([$user_id]);
                $target = $stmt->fetch();

                if (!$target) {
                    $error = "User account not found.";
                } else {

                    // Prevent deleting the last active administrator.
                    if (
                        $target["role"] === "Admin" &&
                        $target["account_status"] === "Active"
                    ) {
                        $stmt = $pdo->query("
                            SELECT COUNT(*)
                            FROM users
                            WHERE role = 'Admin'
                              AND account_status = 'Active'
                        ");

                        if ((int)$stmt->fetchColumn() <= 1) {
                            $error = "You cannot delete the last active administrator.";
                        }
                    }

                    if ($error === "") {
                        $stmt = $pdo->prepare("
                            DELETE FROM users
                            WHERE user_id = ?
                        ");
                        $stmt->execute([$user_id]);

                        header("Location: users.php?success=deleted");
                        exit;
                    }
                }
            }
        }

        else {
            $error = "Invalid action.";
        }
    }
}

// Success messages.
if (isset($_GET["success"])) {
    switch ($_GET["success"]) {
        case "added":
            $message = "User account successfully created.";
            break;
        case "status":
            $message = "Account status successfully updated.";
            break;
        case "deleted":
            $message = "User account successfully deleted.";
            break;
    }
}

// Retrieve all user accounts.
$stmt = $pdo->query("
    SELECT user_id, username, role, account_status, created_at
    FROM users
    ORDER BY user_id DESC
");

$users = $stmt->fetchAll();

?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>User Management</title>

    <link rel="stylesheet" href="assets/style.css">
</head>

<body>

<div class="dashboard-container">

    <!-- SIDEBAR -->
    <aside class="sidebar">

        <h2>Solid Waste System</h2>

        <nav>
            <a href="dashboard.php">Dashboard</a>
            <a href="schedules.php">Collection Scheduling</a>
            <a href="monitoring.php">Monitoring</a>
            <a href="users.php" class="active">User Management</a>
            <a href="logout.php">Logout</a>
        </nav>

    </aside>

    <!-- MAIN CONTENT -->
    <main class="main-content">

        <div class="topbar">
            <h1>User Management</h1>

            <span>
                Welcome, <?= e($_SESSION["username"]); ?>
            </span>
        </div>

        <section class="users-page">

            <!-- PAGE DESCRIPTION -->
            <h2>Manage System Users</h2>

            <p class="users-description">
                Create accounts, manage user access, and monitor registered system users.
            </p>

            <!-- SUCCESS MESSAGE -->
            <?php if ($message !== ""): ?>
                <div class="users-alert users-success">
                    <?= e($message); ?>
                </div>
            <?php endif; ?>

            <!-- ERROR MESSAGE -->
            <?php if ($error !== ""): ?>
                <div class="users-alert users-error">
                    <?= e($error); ?>
                </div>
            <?php endif; ?>


            <!-- ADD USER FORM -->
            <section class="add-user-section">

                <h3>Create New User</h3>

                <form method="POST" action="users.php" class="add-user-form">

                    <input
                        type="hidden"
                        name="csrf_token"
                        value="<?= e($_SESSION["csrf_token"]); ?>"
                    >

                    <input type="hidden" name="action" value="add">

                    <div class="user-form-grid">

                        <div class="user-form-group">
                            <label for="username">Username</label>

                            <input
                                type="text"
                                name="username"
                                id="username"
                                placeholder="Enter username"
                                minlength="3"
                                maxlength="30"
                                required
                            >
                        </div>

                        <div class="user-form-group">
                            <label for="password">Password</label>

                            <input
                                type="password"
                                name="password"
                                id="password"
                                placeholder="Enter password"
                                minlength="8"
                                required
                            >
                        </div>

                        <div class="user-form-group">
                            <label for="role">User Role</label>

                            <select name="role" id="role" required>
                                <option value="">Select Role</option>
                                <option value="Admin">Admin</option>
                                <option value="Staff">Staff</option>
                            </select>
                        </div>

                    </div>

                    <button type="submit" class="add-user-button">
                        Create Account
                    </button>

                </form>

                <p class="users-note">
                    New accounts are automatically set to Active.
                    Passwords are securely hashed before being stored.
                </p>

            </section>


            <!-- REGISTERED USERS TABLE -->
            <section class="registered-users-section">

                <h3>Registered Users</h3>

                <div class="users-table-wrapper">

                    <table class="users-table">

                        <thead>
                            <tr>
                                <th>User ID</th>
                                <th>Username</th>
                                <th>Role</th>
                                <th>Account Status</th>
                                <th>Date Created</th>
                                <th>Actions</th>
                            </tr>
                        </thead>

                        <tbody>

                            <?php if (count($users) > 0): ?>

                                <?php foreach ($users as $user): ?>

                                    <tr>

                                        <td><?= e($user["user_id"]); ?></td>

                                        <td><?= e($user["username"]); ?></td>

                                        <td>
                                            <span class="user-role-badge">
                                                <?= e($user["role"]); ?>
                                            </span>
                                        </td>

                                        <td>
                                            <span class="user-status-badge user-<?= strtolower(e($user["account_status"])); ?>">
                                                <?= e($user["account_status"]); ?>
                                            </span>
                                        </td>

                                        <td>
                                            <?= $user["created_at"]
                                                ? e(date("M d, Y", strtotime($user["created_at"])))
                                                : "—"; ?>
                                        </td>

                                        <td class="user-actions">

                                            <?php if ((int)$user["user_id"] !== (int)$_SESSION["user_id"]): ?>

                                                <!-- ACTIVATE / DEACTIVATE -->
                                                <form method="POST" action="users.php">

                                                    <input
                                                        type="hidden"
                                                        name="csrf_token"
                                                        value="<?= e($_SESSION["csrf_token"]); ?>"
                                                    >

                                                    <input type="hidden" name="action" value="toggle">

                                                    <input
                                                        type="hidden"
                                                        name="user_id"
                                                        value="<?= e($user["user_id"]); ?>"
                                                    >

                                                    <button
                                                        type="submit"
                                                        class="user-toggle-button"
                                                    >
                                                        <?= $user["account_status"] === "Active"
                                                            ? "Deactivate"
                                                            : "Activate"; ?>
                                                    </button>

                                                </form>

                                                <!-- DELETE -->
                                                <form
                                                    method="POST"
                                                    action="users.php"
                                                    onsubmit="return confirm('Are you sure you want to delete this user account?');"
                                                >

                                                    <input
                                                        type="hidden"
                                                        name="csrf_token"
                                                        value="<?= e($_SESSION["csrf_token"]); ?>"
                                                    >

                                                    <input type="hidden" name="action" value="delete">

                                                    <input
                                                        type="hidden"
                                                        name="user_id"
                                                        value="<?= e($user["user_id"]); ?>"
                                                    >

                                                    <button
                                                        type="submit"
                                                        class="user-delete-button"
                                                    >
                                                        Delete
                                                    </button>

                                                </form>

                                            <?php else: ?>

                                                <span class="current-user-label">
                                                    Current Account
                                                </span>

                                            <?php endif; ?>

                                        </td>

                                    </tr>

                                <?php endforeach; ?>

                            <?php else: ?>

                                <tr>
                                    <td colspan="6" class="users-empty-row">
                                        No user accounts found.
                                    </td>
                                </tr>

                            <?php endif; ?>

                        </tbody>

                    </table>

                </div>

            </section>

        </section>

    </main>

</div>

</body>
</html>