<?php
namespace Wpup;
use PDO;
require 'vendor/autoload.php';

$db = new PDO("sqlite:" . __DIR__ . '/db/stats.db3');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$availableSlugs = $db->query('SELECT slug FROM slugs ORDER BY slug ASC', PDO::FETCH_COLUMN, 0)->fetchAll();
natcasesort($availableSlugs);

if (empty($availableSlugs)) {
	die('No data available. Please run <code>php update.php --log "/path/to/request.log"</code>');
} else if (!empty($_GET['slug'])) {
	$slug = strval($_GET['slug']);
	if (!in_array($slug, $availableSlugs)) {
		die(sprintf('Error: slug "%s" not found', htmlentities($slug)));
	}
} else {
	$slug = reset($availableSlugs);
}

$report = new Report($db, $slug, $_GET);

$similarCharts = [
	'activeInstalls' => $report->getChart('total_hits')->renameEmptyValueSeries('Unique sites'),
	'activeVersions' => $report->getActiveVersionChart(),
	'wordPressVersions' => $report->getWordPressVersionChart(),
	'phpVersions' => $report->getPhpVersionChart(),
	'requests' => $report->getRequestChart(),
];

$basicChartData = [];
foreach($similarCharts as $key => $chart) {
	$basicChartData[$key] = [
		'area' => $chart->getAreaChartData(),
		'pie'  => $chart->getPieChartData(),
	];
}

$requestsPerSecond = $report->getTotalRequests() / $report->getDateRange()->getDuration();
?>
<!DOCTYPE html>
<html>
<head>
	<meta charset="utf-8">
	<meta http-equiv="X-UA-Compatible" content="IE=edge">
	<meta name="viewport" content="width=device-width, initial-scale=1">

	<title>WP Update Server Stats - <?php echo htmlentities($report->getSlug()); ?></title>

	<!-- Bootstrap -->
	<link href="vendor/twbs/bootstrap/dist/css/bootstrap.min.css" rel="stylesheet">
	<!-- Bootstrap theme -->
	<link href="vendor/twbs/bootstrap/dist/css/bootstrap-theme.css" rel="stylesheet">
	<!-- Project-specific styles -->
	<link href="css/main.css" rel="stylesheet">

	<script type="text/javascript" src="https://www.gstatic.com/charts/loader.js"></script>
	<script type="text/javascript">
		google.charts.load('current', {'packages': ['corechart']});
		google.charts.setOnLoadCallback(drawAllCharts);

		function drawAreaChart(id, data) {
			data = google.visualization.arrayToDataTable(data);

			var options = {
				titlePosition: 'none',
				hAxis: {title: ''},
				vAxis: {minValue: 0},
				isStacked: true,

				chartArea: {
					width: '75%',
					height: '88%',
					left: 60,
					top: 10
				}
			};

			var chart = new google.visualization.AreaChart(document.getElementById(id));
			chart.draw(data, options);
		}

		function drawPieChart(id, data) {
			data = google.visualization.arrayToDataTable(data);

			var pieOptions = {
				chartArea: {
					width: '100%',
					height: '100%',
					left: 10,
					top: 10
				}
			};

			var pieChart = new google.visualization.PieChart(document.getElementById(id));
			pieChart.draw(data, pieOptions);
		}

		function drawAllCharts() {
			var chartData = (<?php echo json_encode($basicChartData); ?>);

			drawAreaChart('active-install-history', chartData.activeInstalls.area);

			drawAreaChart('active-version-history', chartData.activeVersions.area);
			drawPieChart('active-version-pie', chartData.activeVersions.pie);

			drawAreaChart('wordpress-version-history', chartData.wordPressVersions.area);
			drawPieChart('wordpress-version-pie', chartData.wordPressVersions.pie);

			drawAreaChart('php-version-history', chartData.phpVersions.area);
			drawPieChart('php-version-pie', chartData.phpVersions.pie);

			drawAreaChart('request-chart', chartData.requests.area);
		}
	</script>
</head>
<body>
	<!-- Fixed navbar -->
	<nav class="navbar navbar-default navbar-fixed-top">
		<div class="container">
			<div class="navbar-header">
				<button type="button" class="navbar-toggle collapsed" data-toggle="collapse" data-target="#navbar" aria-expanded="false" aria-controls="navbar">
					<span class="sr-only">Toggle navigation</span>
					<span class="icon-bar"></span>
					<span class="icon-bar"></span>
					<span class="icon-bar"></span>
				</button>
				<a class="navbar-brand" href="?refresh">Update Server Stats</a>
			</div>

			<div id="navbar" class="navbar-collapse collapse">
				<form action="" class="navbar-form">
					<div class="form-group">
						<label for="selected-slug">
							Slug:
						</label>
						<select name="slug" id="selected-slug" class="form-control">
							<?php
							foreach($availableSlugs as $option) {
								printf(
									'<option value="%1$s" %2$s>%1$s</option>',
									htmlentities($option),
									$option === $report->getSlug() ? ' selected="selected"' : ''
								);
							}
							?>
						</select>

						<label for="datepicker">
							Range:
						</label>
						<div class="input-daterange" id="datepicker" style="display: inline-block">
							<label for="from_date" class="sr-only">From date</label>
							<input class="form-control" name="from" id="from_date" value="<?php
								echo htmlentities($report->getDateRange()->startDate());
							?>" type="date">

							<span class="form-control-static range-infix">to</span>

							<label for="to_date" class="sr-only">To date</label>
							<input class="form-control input-small" name="to" id="to_date" value="<?php
								echo htmlentities($report->getDateRange()->endDate());
							?>" type="text">
						</div>
					</div>

					<button type="submit" class="btn btn-default">Apply</button>

				</form>
			</div><!--/.nav-collapse -->


		</div>
	</nav>

	<div class="container-fluid" role="main">

		<div class="row">
			<div class="col-md-12"><h2>Active installs</h2></div>
		</div>
		<div class="row">
			<div class="col-md-9">
				<div id="active-install-history" style="width: 100%; height: 400px;"></div>
			</div>
			<div class="col-md-3">
				<table class="table" id="summary-sidebar">
					<tr>
						<th>
							Active installs
							<small>(7 day average)</small>
						</th>
						<td><span id="active-installs"><?php
								echo number_format($report->getActiveInstalls(7), 0, '.', ',');
								?></span></td>
					</tr>
					<tr class="stats-sub-field">
						<th>&plusmn;</th>
						<td id="new-installs-per-day">
							<span class="change-num <?php
							echo ($report->getInstallsPerDay() >= 0) ? 'positive-change' : 'negative-change';
							?>">
								<?php echo number_format($report->getInstallsPerDay(), 2, '.', ','); ?>
							</span>
							per day
						</td>
					</tr>

					<tr>
						<th colspan="2">API requests</th>
					</tr>
					<tr class="stats-sub-field">
						<th>Per minute</th>
						<td id="api-requests-per-minute">
							<?php echo number_format($requestsPerSecond * 60, 2, '.', ','); ?>
						</td>
					</tr>

					<?php if ($requestsPerSecond > 1) : ?>
						<tr class="stats-sub-field">
							<th>Per second</th>
							<td id="api-requests-per-second">
								<?php echo number_format($requestsPerSecond, 2, '.', ','); ?>
							</td>
						</tr>
					<?php endif; ?>

					<tr class="stats-sub-field">
						<th>Per site <small>(daily average)</small></th>
						<td id="api-requests-per-site-per-day">
							<?php echo number_format($report->getRequestsPerSite(), 2, '.', ','); ?>
						</td>
					</tr>

					<tr class="stats-sub-field">
						<th>Total</th>
						<td id="total-api-requests">
							<?php echo number_format($report->getTotalRequests(), 0, '.', ','); ?>
						</td>
					</tr>
				</table>
			</div>
		</div>

		<div class="row">
			<div class="col-md-12"><h2>Active versions</h2></div>
		</div>
		<div class="row">
			<div class="col-md-9">
				<div id="active-version-history" class="chart area-chart"></div>
			</div>
			<div class="col-md-3">
				<div id="active-version-pie" class="chart pie-chart"></div>
			</div>
		</div>

		<div class="row">
			<div class="col-md-12"><h2>WordPress versions</h2></div>
		</div>
		<div class="row">
			<div class="col-md-9">
				<div id="wordpress-version-history" class="chart area-chart"></div>
			</div>
			<div class="col-md-3">
				<div id="wordpress-version-pie" class="chart"></div>
			</div>
		</div>

		<div class="row">
			<div class="col-md-12"><h2>PHP versions</h2></div>
		</div>
		<div class="row">
			<div class="col-md-9">
				<div id="php-version-history" class="chart"></div>
			</div>
			<div class="col-md-3">
				<div id="php-version-pie" class="chart"></div>
			</div>
		</div>

		<div class="row">
			<div class="col-md-12"><h2>API requests</h2></div>
		</div>
		<div class="row" id="general-api-stats">
			<div class="col-md-9">
				<div id="request-chart" class="chart"></div>
			</div>
			<div class="col-md-3">
				<!-- Nothing here for now. -->
			</div>
		</div>
	</div>

	<!-- jQuery (necessary for Bootstrap's JavaScript plugins). -->
	<script src="vendor/components/jquery/jquery.min.js"></script>
	<!-- Include all compiled Bootstrap plugins. -->
	<script src="vendor/twbs/bootstrap/dist/js/bootstrap.min.js"></script>
</body>
</html>

