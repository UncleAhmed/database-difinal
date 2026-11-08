
<?php

require_once "includes/auth.php";
require_once "config/database.php";

// Get total collection runs
$totalRuns = $pdo->query(
    "SELECT COUNT(*) FROM collection_runs"
)->fetchColumn();

// Get completed runs
$completedRuns = $pdo->query(
    "SELECT COUNT(*) FROM collection_runs
     WHERE run_status = 'Completed'"
)->fetchColumn();

// Get scheduled runs
$scheduledRuns = $pdo->query(
    "SELECT COUNT(*) FROM collection_runs
     WHERE run_status = 'Scheduled'"
)->fetchColumn();

// Get total waste collected
$totalWeight = $pdo->query(
    "SELECT COALESCE(SUM(collected_weight_tons), 0)
     FROM collection_runs"
)->fetchColumn();

// Get the five most recent collection runs, including zones covered
$sql = "SELECT
            cr.run_id,
            cr.scheduled_date,
            r.route_name,
            v.vehicle_id,

            GROUP_CONCAT(
                DISTINCT z.zone_name
                ORDER BY z.zone_id
                SEPARATOR ', '
            ) AS zones_covered,

            cr.collected_weight_tons,
            cr.run_status

        FROM collection_runs cr

        JOIN routes r
            ON cr.route_id = r.route_id

        JOIN vehicles v
            ON cr.vehicle_id = v.vehicle_id

        LEFT JOIN route_zones rz
            ON cr.route_id = rz.route_id

        LEFT JOIN zones z
            ON rz.zone_id = z.zone_id

        GROUP BY
            cr.run_id,
            cr.scheduled_date,
            r.route_name,
            cr.route_id,
            cr.vehicle_id,
            v.vehicle_id,
            cr.collected_weight_tons,
            cr.run_status

        ORDER BY cr.scheduled_date DESC, cr.run_id DESC
        LIMIT 5";

$stmt = $pdo->query($sql);
$recentRuns = $stmt->fetchAll();

?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Dashboard | Solid Waste Management System</title>

    <link rel="stylesheet" href="assets/style.css">
</head>

<body class="dashboard-page">

    <div class="dashboard-layout">

        <!-- SIDEBAR -->
        <aside class="sidebar">

            <h2>Solid Waste</h2>
            <p class="sidebar-subtitle">Management System</p>

            <nav>
                <a href="dashboard.php" class="active">Dashboard</a>
                <a href="schedules.php">Collection Scheduling</a>
                <a href="monitoring.php">Collection Monitoring</a>

                <?php if ($_SESSION["role"] === "Admin"): ?>
                    <a href="users.php">User Management</a>
                <?php endif; ?>

                <a href="logout.php">Logout</a>
            </nav>

        </aside>

        <!-- MAIN CONTENT -->
        <main class="dashboard-content">

            <header class="dashboard-header">
                <div>
                    <h1>Dashboard</h1>
                    <p>Welcome, <?php echo htmlspecialchars($_SESSION["username"]); ?>!</p>
                </div>

                <span class="user-role">
                    <?php echo htmlspecialchars($_SESSION["role"]); ?>
                </span>
            </header>

            <!-- SUMMARY CARDS -->
            <section class="dashboard-cards">

                <div class="dashboard-card">
                    <h3>Total Collection Runs</h3>
                    <p><?php echo $totalRuns; ?></p>
                </div>

                <div class="dashboard-card">
                    <h3>Completed Runs</h3>
                    <p><?php echo $completedRuns; ?></p>
                </div>

                <div class="dashboard-card">
                    <h3>Scheduled Runs</h3>
                    <p><?php echo $scheduledRuns; ?></p>
                </div>

                <div class="dashboard-card">
                    <h3>Total Waste Collected</h3>
                    <p><?php echo number_format((float)$totalWeight, 2); ?> tons</p>
                </div>

            </section>

            <!-- RECENT COLLECTION RUNS -->
            <section class="recent-runs">

                <h2>Recent Collection Runs</h2>

                <div class="table-container">

                    <table>

                        <thead>
                            <tr>
                               <th>Run ID</th>
<th>Date</th>
<th>Route</th>
<th>Zones Covered</th>
<th>Vehicle</th>
<th>Weight (tons)</th>
<th>Status</th>
                            </tr>
                        </thead>

                        <tbody>

                            <?php if (count($recentRuns) > 0): ?>

                                <?php foreach ($recentRuns as $run): ?>

                                    <tr>
                                        <td><?php echo htmlspecialchars($run["run_id"]); ?></td>

                                        <td><?php echo htmlspecialchars($run["scheduled_date"]); ?></td>

                                        <td>
    <?php echo htmlspecialchars($run["route_name"]); ?>
</td>

<td>
    <?php echo htmlspecialchars($run["zones_covered"] ?? "No zones assigned"); ?>
</td>

<td>
    <?php echo htmlspecialchars($run["vehicle_id"]); ?>
</td>
                                        <td>
                                            <?php
                                            echo $run["collected_weight_tons"] !== null
                                                ? number_format((float)$run["collected_weight_tons"], 2)
                                                : "Not recorded";
                                            ?>
                                        </td>

                                        <td>
                                            <span class="status">
                                                <?php echo htmlspecialchars($run["run_status"]); ?>
                                            </span>
                                        </td>
                                    </tr>

                                <?php endforeach; ?>

                            <?php else: ?>

                                <tr>
                                    <td colspan="7">No collection runs found.</td>
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
