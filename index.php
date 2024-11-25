<?php
session_start();
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
if (!isset($_SESSION['loggedin'])) {
    header('Location: login.php');
    exit;
}

$username = $_SESSION['username'] ?? 'Guest';

// Connect to the database
require 'db.php';
require 'header.php';

// Get the current date and time
$current_date = date("Y-m-d H:i:s");

// Fetch server statistics
$uptime = shell_exec('uptime -p'); // Server uptime
$load_average = shell_exec('cat /proc/loadavg'); // Load average
$server_ip = $_SERVER['SERVER_ADDR']; // Server IP

// Fetch CPU usage
$cpu_usage = shell_exec("top -bn1 | grep 'Cpu(s)' | sed 's/.*, *\\([0-9.]*\\)%* id.*/\\1/' | awk '{print 100 - $1}'");
$cpu_usage = trim($cpu_usage);

// Fetch memory usage
$memory_info = shell_exec("free -m");
preg_match('/Mem:\s+(\d+)\s+(\d+)/', $memory_info, $memory_matches);
$memory_total = $memory_matches[1];
$memory_used = $memory_matches[2];
$memory_percentage = round(($memory_used / $memory_total) * 100, 2);

// Fetch disk usage
$disk_info = shell_exec("df -h --total | grep 'total'");
preg_match('/total\s+(\d+G)\s+(\d+G)\s+(\d+G)\s+(\d+)%/', $disk_info, $disk_matches);
$disk_total = $disk_matches[1];
$disk_used = $disk_matches[2];
$disk_percentage = $disk_matches[4];

// Fetch swap usage
$swap_info = shell_exec("free -m");
preg_match('/Swap:\s+(\d+)\s+(\d+)/', $swap_info, $swap_matches);
$swap_total = $swap_matches[1];
$swap_used = $swap_matches[2];
$swap_percentage = $swap_total == 0 ? 0 : round(($swap_used / $swap_total) * 100, 2);

// Fetch backup statistics
$backup_count_query = "SELECT COUNT(*) AS count FROM backups";
$last_backup_query = "SELECT * FROM backups ORDER BY created_at DESC LIMIT 1";

$backup_count_result = $conn->query($backup_count_query);
$last_backup_result = $conn->query($last_backup_query);

if ($backup_count_result && $last_backup_result) {
    $backup_count = $backup_count_result->fetch_assoc()['count'];
    $last_backup = $last_backup_result->fetch_assoc();
    $last_backup_date = $last_backup['created_at'] ?? 'N/A';
} else {
    $backup_count = 0;
    $last_backup_date = 'N/A';
}

// Fetch backup data for the last week
$backup_week_query = "
    SELECT DATE(created_at) AS date, COUNT(*) AS count 
    FROM backups 
    WHERE created_at >= NOW() - INTERVAL 1 WEEK
    GROUP BY DATE(created_at)
    ORDER BY DATE(created_at)
";
$backup_week_result = $conn->query($backup_week_query);

$backup_week_data = [];
if ($backup_week_result) {
    while ($row = $backup_week_result->fetch_assoc()) {
        $backup_week_data[] = $row;
    }
}

// Convert data for chart
$dates = [];
$counts = [];
foreach ($backup_week_data as $data) {
    $dates[] = $data['date'];
    $counts[] = $data['count'];
}

// Convert arrays to JSON for use in JavaScript
$dates_json = json_encode($dates);
$counts_json = json_encode($counts);
?>

<!DOCTYPE html>
<html>
<head>
    <link rel="stylesheet" type="text/css" href="styles/style.css">
    <title>SqlBackupCenter</title>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chartjs-plugin-datalabels"></script>

</head>
<body>

    <div class="content">
        <h1>Welcome to the Backup System</h1>
        <div class="stat-box">
            <p>Server Uptime: <?php echo htmlspecialchars($uptime); ?></p>
            <p>Load Average: <?php echo htmlspecialchars($load_average); ?></p>
            <p>Server IP: <?php echo htmlspecialchars($server_ip); ?></p>
        </div>
        <div class="stat-box">
            <p>Number of Backups: <?php echo htmlspecialchars($backup_count); ?></p>
            <p>Last Backup Date: <?php echo htmlspecialchars($last_backup_date); ?></p>
           <p>Current Date and Time: <?php echo htmlspecialchars($current_date); ?></p>

        </div>

        <h2>Server Metrics</h2>
        <div class="chart-container">
            <div class="server-box">
                <canvas id="cpuChart"></canvas>
                <p>CPU Utilization</p>
            </div>
            <div class="server-box">
                <canvas id="loadChart"></canvas>
                <p>Load Average</p>
            </div>
            <div class="server-box">
                <canvas id="memoryChart"></canvas>
                <p>Memory Utilization</p>
            </div>
            <div class="server-box">
                <canvas id="diskChart"></canvas>
                <p>Disk Utilization</p>
            </div>
            <div class="server-box">
                <canvas id="swapChart"></canvas>
                <p>Swap Utilization</p>
            </div>
        </div>

        <h2>Backup Statistics for Last Week</h2>
        <div class="chart-container">
            <canvas id="backupChart"></canvas>
        </div>

        <script>
            var ctxCPU = document.getElementById('cpuChart').getContext('2d');
            var cpuChart = new Chart(ctxCPU, {
                type: 'doughnut',
                data: {
                    labels: ['Used', 'Free'],
                    datasets: [{
                        data: [<?php echo $cpu_usage; ?>, <?php echo 100 - $cpu_usage; ?>],
                        backgroundColor: ['#4caf50', '#e0e0e0'],
                        borderWidth: 0
                    }]
                },
                options: {
                    cutout: '80%',
                    rotation: 270,
                    circumference: 180,
                    plugins: {
                        tooltip: { enabled: false },
                        legend: { display: false },
                        datalabels: {
                            formatter: (value, ctx) => {
                                return value + '%';
                            },
                            color: '#000',
                            font: { weight: 'bold' }
                        }
                    }
                },
                plugins: [ChartDataLabels]
            });

            var ctxLoad = document.getElementById('loadChart').getContext('2d');
            var loadChart = new Chart(ctxLoad, {
                type: 'doughnut',
                data: {
                    labels: ['1 min', '5 min', '15 min'],
                    datasets: [{
                        data: [0.25, 0.22, 0.19],  // Example data, replace with dynamic values if needed
                        backgroundColor: ['#ff6f61', '#ffcc00', '#4caf50'],
                        borderWidth: 0
                    }]
                },
                options: {
                    cutout: '80%',
                    rotation: 270,
                    circumference: 180,
                    plugins: {
                        tooltip: { enabled: false },
                        legend: { display: false },
                        datalabels: {
                            formatter: (value, ctx) => {
                                return value + '%';
                            },
                            color: '#000',
                            font: { weight: 'bold' }
                        }
                    }
                },
                plugins: [ChartDataLabels]
            });

            var ctxMemory = document.getElementById('memoryChart').getContext('2d');
            var memoryChart = new Chart(ctxMemory, {
                type: 'doughnut',
                data: {
                    labels: ['Used', 'Free'],
                    datasets: [{
                        data: [<?php echo $memory_percentage; ?>, <?php echo 100 - $memory_percentage; ?>],
                        backgroundColor: ['#4caf50', '#e0e0e0'],
                        borderWidth: 0
                    }]
                },
                options: {
                    cutout: '80%',
                    rotation: 270,
                    circumference: 180,
                    plugins: {
                        tooltip: { enabled: false },
                        legend: { display: false },
                        datalabels: {
                            formatter: (value, ctx) => {
                                return value + '%';
                            },
                            color: '#000',
                            font: { weight: 'bold' }
                        }
                    }
                },
                plugins: [ChartDataLabels]
            });

            var ctxDisk = document.getElementById('diskChart').getContext('2d');
            var diskChart = new Chart(ctxDisk, {
                type: 'doughnut',
                data: {
                    labels: ['Used', 'Free'],
                    datasets: [{
                        data: [<?php echo $disk_percentage; ?>, <?php echo 100 - $disk_percentage; ?>],
                        backgroundColor: ['#4caf50', '#e0e0e0'],
                        borderWidth: 0
                    }]
                },
                options: {
                    cutout: '80%',
                    rotation: 270,
                    circumference: 180,
                    plugins: {
                        tooltip: { enabled: false },
                        legend: { display: false },
                        datalabels: {
                            formatter: (value, ctx) => {
                                return value + '%';
                            },
                            color: '#000',
                            font: { weight: 'bold' }
                        }
                    }
                },
                plugins: [ChartDataLabels]
            });

            var ctxSwap = document.getElementById('swapChart').getContext('2d');
            var swapChart = new Chart(ctxSwap, {
                type: 'doughnut',
                data: {
                    labels: ['Used', 'Free'],
                    datasets: [{
                        data: [<?php echo $swap_percentage; ?>, <?php echo 100 - $swap_percentage; ?>],
                        backgroundColor: ['#4caf50', '#e0e0e0'],
                        borderWidth: 0
                    }]
                },
                options: {
                    cutout: '80%',
                    rotation: 270,
                    circumference: 180,
                    plugins: {
                        tooltip: { enabled: false },
                        legend: { display: false },
                        datalabels: {
                            formatter: (value, ctx) => {
                                return value + '%';
                            },
                            color: '#000',
                            font: { weight: 'bold' }
                        }
                    }
                },
                plugins: [ChartDataLabels]
            });

            var ctxBackup = document.getElementById('backupChart').getContext('2d');
            var backupChart = new Chart(ctxBackup, {
                type: 'bar',
                data: {
                    labels: <?php echo $dates_json; ?>,
                    datasets: [{
                        label: 'Number of Backups',
                        data: <?php echo $counts_json; ?>,
                        backgroundColor: '#4caf50',
                        borderColor: '#388e3c',
                        borderWidth: 1
                    }]
                },
                options: {
                    scales: {
                        y: {
                            beginAtZero: true
                        }
                    },
                    plugins: {
                        tooltip: { enabled: true },
                        datalabels: {
                            formatter: (value, ctx) => {
                                return value + '%';
                            },
                            anchor: 'end',
                            align: 'top',
                            color: '#000',
                            font: { weight: 'bold' }
                        }
                    }
                },
                plugins: [ChartDataLabels]
            });
        </script>
    </div>

</body>
</html>
