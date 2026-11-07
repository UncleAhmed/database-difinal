
<?php

require_once "includes/auth.php";
require_once "config/database.php";

// Only administrators can create collection schedules
requireAdmin();

$error = "";
$success = "";

// Create a CSRF token for form security
if (empty($_SESSION["csrf_token"])) {
    $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
}

// Load routes
$routes = $pdo->query(
    "SELECT route_id, route_name
     FROM routes
     ORDER BY route_name"
)->fetchAll();

// Load vehicles
$vehicles = $pdo->query(
    "SELECT vehicle_id, capacity_tons
     FROM vehicles
     ORDER BY vehicle_id"
)->fetchAll();

// Load drivers
$drivers = $pdo->query(
    "SELECT personnel_id, full_name
     FROM personnel
     WHERE personnel_role = 'Driver'
     ORDER BY full_name"
)->fetchAll();

// Load crew members
// Use personnel_id when full_name is NULL or empty
$crewMembers = $pdo->query(
    "SELECT
        personnel_id,
        COALESCE(NULLIF(full_name, ''), personnel_id) AS display_name
     FROM personnel
     WHERE personnel_role = 'Crew'
     ORDER BY personnel_id"
)->fetchAll();

// Process the scheduling form
if ($_SERVER["REQUEST_METHOD"] === "POST") {

    // Verify CSRF token
    if (
        !isset($_POST["csrf_token"]) ||
        !hash_equals($_SESSION["csrf_token"], $_POST["csrf_token"])
    ) {
        $error = "Invalid form submission. Please refresh the page.";
    } else {

        $runId = trim($_POST["run_id"] ?? "");
        $scheduledDate = $_POST["scheduled_date"] ?? "";
        $routeId = $_POST["route_id"] ?? "";
        $vehicleId = $_POST["vehicle_id"] ?? "";
        $driverId = $_POST["driver_id"] ?? "";
        $selectedCrew = $_POST["crew_ids"] ?? [];

        // Basic validation
        if (
            $runId === "" ||
            $scheduledDate === "" ||
            $routeId === "" ||
            $vehicleId === "" ||
            $driverId === "" ||
            !is_array($selectedCrew) ||
            count($selectedCrew) === 0
        ) {
            $error = "Please complete all required fields and select at least one crew member.";
        } else {

            // Validate date
            $date = DateTime::createFromFormat("!Y-m-d", $scheduledDate);

            if (
                !$date ||
                $date->format("Y-m-d") !== $scheduledDate
            ) {
                $error = "Please enter a valid collection date.";
            } else {

                try {

                    // Check duplicate Run ID
                    $check = $pdo->prepare(
                        "SELECT run_id
                         FROM collection_runs
                         WHERE run_id = ?"
                    );

                    $check->execute([$runId]);

                    if ($check->fetch()) {

                        $error = "That Run ID already exists. Please use another one.";

                    } else {

                        // Verify route
                        $checkRoute = $pdo->prepare(
                            "SELECT route_id
                             FROM routes
                             WHERE route_id = ?"
                        );

                        $checkRoute->execute([$routeId]);

                        // Verify vehicle
                        $checkVehicle = $pdo->prepare(
                            "SELECT vehicle_id
                             FROM vehicles
                             WHERE vehicle_id = ?"
                        );

                        $checkVehicle->execute([$vehicleId]);

                        // Verify driver
                        $checkDriver = $pdo->prepare(
                            "SELECT personnel_id
                             FROM personnel
                             WHERE personnel_id = ?
                             AND personnel_role = 'Driver'"
                        );

                        $checkDriver->execute([$driverId]);

                        if (
                            !$checkRoute->fetch() ||
                            !$checkVehicle->fetch() ||
                            !$checkDriver->fetch()
                        ) {

                            $error = "One or more selected options are invalid.";

                        } else {

                            // Remove duplicate crew IDs
                            $selectedCrew = array_unique($selectedCrew);

                            // Verify crew members
                            $checkCrew = $pdo->prepare(
                                "SELECT personnel_id
                                 FROM personnel
                                 WHERE personnel_id = ?
                                 AND personnel_role = 'Crew'"
                            );

                            $validCrew = [];

                            foreach ($selectedCrew as $crewId) {

                                $checkCrew->execute([$crewId]);

                                if (!$checkCrew->fetch()) {
                                    throw new Exception("Invalid crew member selected.");
                                }

                                $validCrew[] = $crewId;
                            }

                            // Save schedule and crew members together
                            $pdo->beginTransaction();

                            $insertRun = $pdo->prepare(
                                "INSERT INTO collection_runs
                                (
                                    run_id,
                                    scheduled_date,
                                    route_id,
                                    vehicle_id,
                                    driver_id,
                                    collected_weight_tons,
                                    completion_time,
                                    run_status
                                )
                                VALUES (?, ?, ?, ?, ?, NULL, NULL, 'Scheduled')"
                            );

                            $insertRun->execute([
                                $runId,
                                $scheduledDate,
                                $routeId,
                                $vehicleId,
                                $driverId
                            ]);

                            // Insert assigned crew members
                            $insertCrew = $pdo->prepare(
                                "INSERT INTO run_crew (run_id, personnel_id)
                                 VALUES (?, ?)"
                            );

                            foreach ($validCrew as $crewId) {
                                $insertCrew->execute([$runId, $crewId]);
                            }

                            $pdo->commit();

                            $success = "Collection schedule created successfully!";

                            // Generate a fresh CSRF token
                            $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
                        }
                    }

                } catch (Exception $e) {

                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }

                    $error = "Unable to create the schedule. Please check your entries and try again.";
                }
            }
        }
    }
}

// Load existing collection schedules with assigned crew members
$schedules = $pdo->query(
    "SELECT
        cr.run_id,
        cr.scheduled_date,
        r.route_name,
        cr.vehicle_id,
        p.full_name AS driver_name,

        GROUP_CONCAT(
            COALESCE(NULLIF(crew.full_name, ''), crew.personnel_id)
            ORDER BY crew.personnel_id
            SEPARATOR ', '
        ) AS crew_members,

        cr.run_status

     FROM collection_runs cr

     JOIN routes r
        ON cr.route_id = r.route_id

     JOIN vehicles v
        ON cr.vehicle_id = v.vehicle_id

     JOIN personnel p
        ON cr.driver_id = p.personnel_id

     LEFT JOIN run_crew rc
        ON cr.run_id = rc.run_id

     LEFT JOIN personnel crew
        ON rc.personnel_id = crew.personnel_id

     GROUP BY
        cr.run_id,
        cr.scheduled_date,
        r.route_name,
        cr.vehicle_id,
        p.full_name,
        cr.run_status

     ORDER BY cr.scheduled_date DESC"
)->fetchAll();

?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Collection Scheduling | Solid Waste Management System</title>

    <link rel="stylesheet" href="assets/style.css">
</head>

<body class="dashboard-page">

    <div class="dashboard-layout">

        <!-- SIDEBAR -->
        <aside class="sidebar">

            <h2>Solid Waste</h2>
            <p class="sidebar-subtitle">Management System</p>

            <nav>
                <a href="dashboard.php">Dashboard</a>
                <a href="schedules.php" class="active">Collection Scheduling</a>
                <a href="monitoring.php">Collection Monitoring</a>
                <a href="users.php">User Management</a>
                <a href="logout.php">Logout</a>
            </nav>

        </aside>

        <!-- MAIN CONTENT -->
        <main class="dashboard-content">

            <header class="dashboard-header">

                <div>
                    <h1>Collection Scheduling</h1>
                    <p>Create and manage waste collection schedules.</p>
                </div>

                <span class="user-role">
                    <?php echo htmlspecialchars($_SESSION["username"]); ?>
                </span>

            </header>

            <!-- SCHEDULING FORM -->
            <section class="schedule-form-section">

                <h2>Create New Collection Schedule</h2>

                <?php if ($error !== ""): ?>
                    <div class="error-message">
                        <?php echo htmlspecialchars($error); ?>
                    </div>
                <?php endif; ?>

                <?php if ($success !== ""): ?>
                    <div class="success-message">
                        <?php echo htmlspecialchars($success); ?>
                    </div>
                <?php endif; ?>

                <form method="POST" action="schedules.php">

                    <input
                        type="hidden"
                        name="csrf_token"
                        value="<?php echo htmlspecialchars($_SESSION["csrf_token"]); ?>"
                    >

                    <div class="schedule-form-grid">

                        <!-- RUN ID -->
                        <div class="form-group">

                            <label for="run_id">Run ID</label>

                            <input
                                type="text"
                                id="run_id"
                                name="run_id"
                                placeholder="Example: RUN-07"
                                required
                            >

                        </div>

                        <!-- SCHEDULED DATE -->
                        <div class="form-group">

                            <label for="scheduled_date">Scheduled Date</label>

                            <input
                                type="date"
                                id="scheduled_date"
                                name="scheduled_date"
                                required
                            >

                        </div>

                        <!-- ROUTE -->
                        <div class="form-group">

                            <label for="route_id">Collection Route</label>

                            <select id="route_id" name="route_id" required>

                                <option value="">Select Route</option>

                                <?php foreach ($routes as $route): ?>

                                    <option value="<?php echo htmlspecialchars($route["route_id"]); ?>">
                                        <?php echo htmlspecialchars($route["route_name"]); ?>
                                    </option>

                                <?php endforeach; ?>

                            </select>

                        </div>

                        <!-- VEHICLE -->
                        <div class="form-group">

                            <label for="vehicle_id">Collection Vehicle</label>

                            <select id="vehicle_id" name="vehicle_id" required>

                                <option value="">Select Vehicle</option>

                                <?php foreach ($vehicles as $vehicle): ?>

                                    <option value="<?php echo htmlspecialchars($vehicle["vehicle_id"]); ?>">
                                        <?php echo htmlspecialchars($vehicle["vehicle_id"]); ?>
                                        (<?php echo htmlspecialchars($vehicle["capacity_tons"]); ?> tons)
                                    </option>

                                <?php endforeach; ?>

                            </select>

                        </div>

                        <!-- DRIVER -->
                        <div class="form-group">

                            <label for="driver_id">Driver</label>

                            <select id="driver_id" name="driver_id" required>

                                <option value="">Select Driver</option>

                                <?php foreach ($drivers as $driver): ?>

                                    <option value="<?php echo htmlspecialchars($driver["personnel_id"]); ?>">
                                        <?php echo htmlspecialchars($driver["full_name"]); ?>
                                    </option>

                                <?php endforeach; ?>

                            </select>

                        </div>

                    </div>

                    <!-- CREW MEMBERS -->
                    <div class="form-group crew-selection">

                        <label>Crew Members</label>

                        <div class="crew-options">

                            <?php foreach ($crewMembers as $crew): ?>

                                <label>

                                    <input
                                        type="checkbox"
                                        name="crew_ids[]"
                                        value="<?php echo htmlspecialchars($crew["personnel_id"]); ?>"
                                    >

                                    <?php echo htmlspecialchars($crew["display_name"]); ?>

                                </label>

                            <?php endforeach; ?>

                        </div>

                    </div>

                    <button type="submit" class="schedule-button">
                        Create Collection Schedule
                    </button>

                </form>

            </section>

            <!-- EXISTING SCHEDULES -->
            <section class="recent-runs schedule-list">

                <h2>Existing Collection Schedules</h2>

                <div class="table-container">

                    <table>

                        <thead>

                            <tr>
                                <th>Run ID</th>
                                <th>Date</th>
                                <th>Route</th>
                                <th>Vehicle</th>
                                <th>Driver</th>
                                <th>Crew Members</th>
                                <th>Status</th>
                            </tr>

                        </thead>

                        <tbody>

                            <?php if (count($schedules) > 0): ?>

                                <?php foreach ($schedules as $schedule): ?>

                                    <tr>

                                        <td>
                                            <?php echo htmlspecialchars($schedule["run_id"]); ?>
                                        </td>

                                        <td>
                                            <?php echo htmlspecialchars($schedule["scheduled_date"]); ?>
                                        </td>

                                        <td>
                                            <?php echo htmlspecialchars($schedule["route_name"]); ?>
                                        </td>

                                        <td>
                                            <?php echo htmlspecialchars($schedule["vehicle_id"]); ?>
                                        </td>

                                        <td>
                                            <?php echo htmlspecialchars($schedule["driver_name"]); ?>
                                        </td>

                                        <td>
                                            <?php echo htmlspecialchars($schedule["crew_members"] ?? "Not assigned"); ?>
                                        </td>

                                        <td>
                                            <span class="status">
                                                <?php echo htmlspecialchars($schedule["run_status"]); ?>
                                            </span>
                                        </td>

                                    </tr>

                                <?php endforeach; ?>

                            <?php else: ?>

                                <tr>
                                    <td colspan="7">No collection schedules found.</td>
                                </tr>

                            <?php endif; ?>

                        </tbody>

                    </table>

                </div>

            </section>

        </main>

    </div>

</body>

</html>
