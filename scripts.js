// Chart.js Configuration
document.addEventListener('DOMContentLoaded', function () {
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
                    formatter: (value, ctx) => value + '%',
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
                data: [0.25, 0.22, 0.19],  // Example data
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
                    formatter: (value, ctx) => value + '%',
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
                    formatter: (value, ctx) => value + '%',
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
                    formatter: (value, ctx) => value + '%',
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
                    formatter: (value, ctx) => value + '%',
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
                    formatter: (value, ctx) => value + '%',
                    anchor: 'end',
                    align: 'top',
                    color: '#000',
                    font: { weight: 'bold' }
                }
            }
        },
        plugins: [ChartDataLabels]
    });
});
