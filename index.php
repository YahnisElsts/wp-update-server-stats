<?php
namespace Wpup;
use PDO;
require 'vendor/autoload.php';

$db = new PDO("sqlite:" . __DIR__ . '/db/stats.db3');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$report = new Report($db, array_merge(['slug' => 'admin-menu-editor-pro'], $_GET));

$activeVersions = $report->getActiveVersionChart();
$wordPressVersion = $report->getWordPressVersionChart();
$requestStats = $report->getRequestChart();
$phpVersions = $report->getPhpVersionChart();

$activeInstalls = array_sum(array_slice($activeVersions->getTotalsByDate(), -7)) / 7;
?>
<html>
<head>
	<title>Update Server Stats</title>
	<script type="text/javascript" src="https://www.gstatic.com/charts/loader.js"></script>
	<script type="text/javascript">
		google.charts.load('current', {'packages': ['corechart']});
		google.charts.setOnLoadCallback(drawChart);

		function drawChart() {
			var data = google.visualization.arrayToDataTable(<?php
				echo json_encode($activeVersions->getAreaChartData());
			?>);

			var options = {
				title: 'Active versions',
				hAxis: {title: 'Date',  titleTextStyle: {color: '#333'}},
				vAxis: {minValue: 0},
				isStacked: true
			};

			var chart = new google.visualization.AreaChart(document.getElementById('chart_div'));
			chart.draw(data, options);

			var pieData = google.visualization.arrayToDataTable(<?php
				echo json_encode($activeVersions->getPieChartData());
			?>);

			var pieOptions = {
				title: 'Active versions'
			};

			var pieChart = new google.visualization.PieChart(document.getElementById('piechart'));
			pieChart.draw(pieData, pieOptions);
		}
	</script>
</head>
<body>
<div id="chart_div" style="width: 100%; height: 500px;"></div>
<div id="piechart" style="width: 900px; height: 500px;"></div>

<?php
printf('Active installs: %d', $activeInstalls);
?>
</body>
</html>

