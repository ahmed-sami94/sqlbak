<script>
    var ctx = document.getElementById('backupChart').getContext('2d');
    var backupChart = new Chart(ctx, {
        type: 'bar',
        data: {
            labels: <?php echo $encoded_labels; ?>,
            datasets: [{
                label: 'Number of Backups',
                data: <?php echo $encoded_counts; ?>,
                backgroundColor: 'rgba(75, 192, 192, 0.2)',
                borderColor: 'rgba(75, 192, 192, 1)',
                borderWidth: 1
            }]
        },
        options: {
            scales: {
                y: {
                    beginAtZero: true
                }
            }
        }
    });
</script>

