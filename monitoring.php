
<?php

require_once "includes/auth.php";
require_once "config/database.php";

// Only Admin and Staff can access monitoring.
if (!in_array($_SESSION["role"] ?? "", ["Admin", "Staff"], true)) {
    http_response_code(403);
    die("Access denied. Only administrators and staff can access collection monitoring.");
}

// Create CSRF token.
if (empty($_SESSION["csrf_token"])) {
    $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
}

$message = "";
$error = "";

// Handle update or delete submission.
if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $token = $_POST["csrf_token"] ?? "";

    if (!hash_equals($_SESSION["csrf_token"], $token)) {

        $error = "Invalid request. Please refresh the page and try again.";

    } else {

        $action = $_POST["action"] ?? "update";
        $run_id = trim($_POST["run_id"] ?? "");

        // ==========================================
        // DELETE COLLECTION RECORD
        // ==========================================
        if ($action === "delete") {

            // Only Admin can delete collection records.
            if (($_SESSION["role"] ?? "") !== "Admin") {

                http_response_code(403);
                die("Access denied. Only administrators can delete collection records.");

            } elseif ($run_id === "") {

                $error = "Invalid collection record.";

            } else {

                try {

                    $pdo->beginTransaction();

                    // Delete associated crew assignments first.
                    $crewStmt = $pdo->prepare(
                        "DELETE FROM run_crew WHERE run_id = :run_id"
                    );

                    $crewStmt->execute([
                        ":run_id" => $run_id
                    ]);

                    // Delete the collection run.
                    $deleteStmt = $pdo->prepare(
                        "DELETE FROM collection_runs WHERE run_id = :run_id"
                    );

                    $deleteStmt->execute([
                        ":run_id" => $run_id
                    ]);

                    if ($deleteStmt->rowCount() === 0) {
                        throw new RuntimeException("Collection record not found.");
                    }

                    $pdo->commit();

                    header("Location: monitoring.php?deleted=1");
                    exit;

                } catch (Throwable $e) {

                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }

                    $error = "Unable to delete the collection record. Please check its database relationships and try again.";
                }
            }

        // ==========================================
        // UPDATE COLLECTION RECORD
        // ==========================================
        } elseif ($action === "update") {

            $run_status = $_POST["run_status"] ?? "";
            $weight_input = trim($_POST["collected_weight_tons"] ?? "");
            $completion_time = trim($_POST["completion_time"] ?? "");

            $allowed_statuses = [
                "Scheduled",
                "Ongoing",
                "Completed",
                "Cancelled"
            ];

            if (
                $run_id === "" ||
                !in_array($run_status, $allowed_statuses, true)
            ) {

                $error = "Please provide a valid collection run and status.";

            } elseif ($run_status === "Completed") {

                if (
                    $weight_input === "" ||
                    !is_numeric($weight_input) ||
                    (float)$weight_input < 0
                ) {

                    $error = "Please enter a valid collected weight.";

                } elseif (
                    !preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $completion_time)
                ) {

                    $error = "Please enter a valid completion time.";

                }
            }

            if ($error === "") {

                // Completed runs require weight and completion time.
                // Other statuses will have NULL weight and completion time.
                if ($run_status === "Completed") {
                    $weight = (float)$weight_input;
                    $time = $completion_time;
                } else {
                    $weight = null;
                    $time = null;
                }

                try {

                    $update_sql = "
                        UPDATE collection_runs
                        SET run_status = :run_status,
                            collected_weight_tons = :weight,
                            completion_time = :completion_time
                        WHERE run_id = :run_id
                    ";

                    $stmt = $pdo->prepare($update_sql);

                    $stmt->execute([
                        ":run_status" => $run_status,
                        ":weight" => $weight,
                        ":completion_time" => $time,
                        ":run_id" => $run_id
                    ]);

                    header("Location: monitoring.php?updated=1");
                    exit;

                } catch (PDOException $e) {

                    $error = "Unable to update the collection record. Please try again.";

                }
            }

        } else {

            $error = "Invalid action requested.";

        }
    }
}

// ==========================================
// SUCCESS MESSAGES
// ==========================================

if (isset($_GET["updated"])) {
    $message = "Collection record successfully updated.";
}

if (isset($_GET["deleted"])) {
    $message = "Collection record successfully deleted.";
}

// ==========================================
// GET COLLECTION RECORDS, INCLUDING ZONES
// ==========================================

$sql = "
    SELECT
        cr.run_id,
        cr.scheduled_date,
        cr.run_status,
        cr.collected_weight_tons,
        cr.completion_time,

        r.route_name,
        v.vehicle_id,
        p.full_name AS driver_name,

        GROUP_CONCAT(
            DISTINCT COALESCE(NULLIF(c.full_name, ''), c.personnel_id)
            ORDER BY c.personnel_id
            SEPARATOR ', '
        ) AS crew_members,

        GROUP_CONCAT(
            DISTINCT z.zone_name
            ORDER BY z.zone_id
            SEPARATOR ', '
        ) AS zones_covered

    FROM collection_runs cr

    LEFT JOIN routes r
        ON cr.route_id = r.route_id

    LEFT JOIN vehicles v
        ON cr.vehicle_id = v.vehicle_id

    LEFT JOIN personnel p
        ON cr.driver_id = p.personnel_id

    LEFT JOIN run_crew rc
        ON cr.run_id = rc.run_id

    LEFT JOIN personnel c
        ON rc.personnel_id = c.personnel_id

    LEFT JOIN route_zones rz
        ON cr.route_id = rz.route_id

    LEFT JOIN zones z
        ON rz.zone_id = z.zone_id

    GROUP BY
        cr.run_id,
        cr.scheduled_date,
        cr.run_status,
        cr.collected_weight_tons,
        cr.completion_time,
        r.route_name,
        v.vehicle_id,
        p.full_name

    ORDER BY cr.scheduled_date DESC, cr.run_id DESC
";

$stmt = $pdo->query($sql);
$runs = $stmt->fetchAll();

// ==========================================
// FIND SELECTED RECORD
// ==========================================

$selected_run = null;
$edit_id = trim($_GET["edit"] ?? $_POST["run_id"] ?? "");

if ($edit_id !== "") {

    foreach ($runs as $run) {

        if ($run["run_id"] === $edit_id) {
            $selected_run = $run;
            break;
        }
    }

    if ($selected_run === null) {

        $error = "The selected collection record could not be found.";

    } elseif ($_SERVER["REQUEST_METHOD"] === "POST" && $error !== "") {

        // Preserve entered values after validation errors.
        if (
            in_array(
                $_POST["run_status"] ?? "",
                ["Scheduled", "Ongoing", "Completed", "Cancelled"],
                true
            )
        ) {
            $selected_run["run_status"] = $_POST["run_status"];
        }

        $selected_run["collected_weight_tons"] =
            $_POST["collected_weight_tons"] ?? $selected_run["collected_weight_tons"];

        $selected_run["completion_time"] =
            $_POST["completion_time"] ?? $selected_run["completion_time"];
    }
}

// Helper for safely displaying text.
function e($value) {
    return htmlspecialchars((string)($value ?? ""), ENT_QUOTES, "UTF-8");
}

?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Collection Monitoring</title>

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

            <a href="monitoring.php" class="active">Collection Monitoring</a>

            <?php if (($_SESSION["role"] ?? "") === "Admin"): ?>
                <a href="users.php">User Management</a>
            <?php endif; ?>

            <a href="logout.php">Logout</a>

        </nav>

    </aside>

    <!-- MAIN CONTENT -->
    <main class="main-content">

        <div class="topbar">

            <h1>Collection Monitoring</h1>

            <span>
                Welcome, <?= e($_SESSION["username"] ?? "User"); ?>
            </span>

        </div>

        <section class="monitoring-page">

            <h2>Collection Records</h2>

            <p class="monitoring-description">
                View collection activities and update their status, collected weight,
                and completion time.
            </p>

            <!-- SUCCESS MESSAGE -->
            <?php if ($message !== ""): ?>

                <div class="monitoring-alert success-alert">
                    <?= e($message); ?>
                </div>

            <?php endif; ?>

            <!-- ERROR MESSAGE -->
            <?php if ($error !== ""): ?>

                <div class="monitoring-alert error-alert">
                    <?= e($error); ?>
                </div>

            <?php endif; ?>

            <!-- COLLECTION RECORDS TABLE -->
            <div class="monitoring-table-wrapper">

                <table class="monitoring-table">

                    <thead>

                        <tr>
                            <th>Run ID</th>
                            <th>Date</th>
                            <th>Route</th>
                            <th>Zones Covered</th>
                            <th>Vehicle</th>
                            <th>Driver</th>
                            <th>Crew Members</th>
                            <th>Status</th>
                            <th>Collected Weight</th>
                            <th>Completion Time</th>
                            <th>Action</th>
                        </tr>

                    </thead>

                    <tbody>

                        <?php if (count($runs) > 0): ?>

                            <?php foreach ($runs as $run): ?>

                                <tr>

                                    <td><?= e($run["run_id"]); ?></td>

                                    <td>
                                        <?= e(date("M d, Y", strtotime($run["scheduled_date"]))); ?>
                                    </td>

                                    <td>
                                        <?= e($run["route_name"]); ?>
                                    </td>

                                    <td>
                                        <?= e($run["zones_covered"] ?: "No zones assigned"); ?>
                                    </td>

                                    <td>
                                        <?= e($run["vehicle_id"]); ?>
                                    </td>

                                    <td>
                                        <?= e($run["driver_name"]); ?>
                                    </td>

                                    <td>
                                        <?= e($run["crew_members"] ?: "No crew assigned"); ?>
                                    </td>

                                    <td>
                                        <span class="status-badge status-<?= strtolower(e($run["run_status"])); ?>">
                                            <?= e($run["run_status"]); ?>
                                        </span>
                                    </td>

                                    <td>

                                        <?php if ($run["collected_weight_tons"] !== null): ?>

                                            <?= e(number_format((float)$run["collected_weight_tons"], 2)); ?> tons

                                        <?php else: ?>

                                            —

                                        <?php endif; ?>

                                    </td>

                                    <td>

                                        <?php if (!empty($run["completion_time"])): ?>

                                            <?= e(date("h:i A", strtotime($run["completion_time"]))); ?>

                                        <?php else: ?>

                                            —

                                        <?php endif; ?>

                                    </td>

                                    <td>

                                        <a
                                            class="monitoring-edit-link"
                                            href="monitoring.php?edit=<?= urlencode($run["run_id"]); ?>#update-form"
                                        >
                                            Update
                                        </a>

                                    </td>

                                </tr>

                            <?php endforeach; ?>

                        <?php else: ?>

                            <tr>

                                <td colspan="11" class="empty-row">
                                    No collection records found.
                                </td>

                            </tr>

                        <?php endif; ?>

                    </tbody>

                </table>

            </div>

            <!-- UPDATE FORM -->
            <?php if ($selected_run !== null): ?>

                <section class="monitoring-update-section" id="update-form">

                    <div class="update-heading">

                        <div>

                            <h2>Update Collection Record</h2>

                            <p>
                                Editing collection run:
                                <strong><?= e($selected_run["run_id"]); ?></strong>
                            </p>

                        </div>

                        <a href="monitoring.php" class="monitoring-cancel-link">
                            Close
                        </a>

                    </div>

                    <form
                        method="POST"
                        action="monitoring.php"
                        class="organized-update-form"
                        id="updateCollectionForm"
                    >

                        <!-- CSRF TOKEN -->
                        <input
                            type="hidden"
                            name="csrf_token"
                            value="<?= e($_SESSION["csrf_token"]); ?>"
                        >

                        <!-- COLLECTION RUN ID -->
                        <input
                            type="hidden"
                            name="run_id"
                            value="<?= e($selected_run["run_id"]); ?>"
                        >

                        <!-- FIRST ROW: STATUS AND WEIGHT -->
                        <div class="update-form-row">

                            <div class="update-form-group">

                                <label for="run_status">
                                    Collection Status
                                </label>

                                <select
                                    name="run_status"
                                    id="run_status"
                                    required
                                >

                                    <option value="">
                                        -- Select Status --
                                    </option>

                                    <?php
                                    $statuses = [
                                        "Scheduled",
                                        "Ongoing",
                                        "Completed",
                                        "Cancelled"
                                    ];

                                    foreach ($statuses as $status):
                                    ?>

                                        <option
                                            value="<?= e($status); ?>"
                                            <?= ($selected_run["run_status"] === $status) ? "selected" : ""; ?>
                                        >
                                            <?= e($status); ?>
                                        </option>

                                    <?php endforeach; ?>

                                </select>

                            </div>

                            <div class="update-form-group">

                                <label for="collected_weight_tons">
                                    Collected Weight (tons)
                                </label>

                                <input
                                    type="number"
                                    name="collected_weight_tons"
                                    id="collected_weight_tons"
                                    min="0"
                                    step="0.01"
                                    placeholder="Example: 2.80"
                                    value="<?= $selected_run["collected_weight_tons"] !== null
                                        ? e($selected_run["collected_weight_tons"])
                                        : ""; ?>"
                                >

                            </div>

                        </div>

                        <!-- SECOND ROW: COMPLETION TIME -->
                        <div class="update-form-row">

                            <div class="update-form-group">

                                <label for="completion_time">
                                    Completion Time
                                </label>

                                <input
                                    type="time"
                                    name="completion_time"
                                    id="completion_time"
                                    value="<?= !empty($selected_run["completion_time"])
                                        ? e(substr($selected_run["completion_time"], 0, 5))
                                        : ""; ?>"
                                >

                            </div>

                        </div>

                        <!-- THIRD ROW: ACTION BUTTONS -->
                        <div class="update-form-actions">

                            <!-- CLEAR FORM -->
                            <button
                                type="button"
                                class="monitoring-clear-button"
                                onclick="clearUpdateForm()"
                            >
                                Clear Form
                            </button>

                            <!-- SAVE UPDATE -->
                            <button
                                type="submit"
                                name="action"
                                value="update"
                                class="monitoring-save-button"
                            >
                                Save Update
                            </button>

                            <!-- DELETE RECORD: ADMIN ONLY -->
                            <?php if (($_SESSION["role"] ?? "") === "Admin"): ?>

                                <button
                                    type="submit"
                                    name="action"
                                    value="delete"
                                    class="monitoring-delete-button"
                                    onclick="return confirm('Are you sure you want to permanently delete collection run <?= e($selected_run["run_id"]); ?>? This action cannot be undone.');"
                                >
                                    Delete Record
                                </button>

                            <?php endif; ?>

                        </div>

                        <p class="monitoring-form-note">

                            Note: Collected weight and completion time are required
                            when the status is set to Completed.

                        </p>

                    </form>

                </section>

            <?php endif; ?>

        </section>

    </main>

</div>

<!-- CLEAR FORM SCRIPT -->
<script>

function clearUpdateForm() {

    document.getElementById("run_status").value = "";

    document.getElementById("collected_weight_tons").value = "";

    document.getElementById("completion_time").value = "";

}

</script>

</body>
</html>
