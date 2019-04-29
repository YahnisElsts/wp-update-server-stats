<?php
namespace Wpup;
use PDO;
require __DIR__ . '/vendor/autoload.php';

$db = new PDO("sqlite:" . __DIR__ . '/db/stats.db3');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$tableExists = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='slugs'");
if ($tableExists->fetch() === false) {
	echo "<h1>Error: Database hasn't been initialised. See readme.md for usage instructions.</h1>";
	exit;
}

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

$charts = [
	'activeInstalls' => $report->getChart('total_hits')->renameEmptyValueSeries('Unique sites'),
	'activeVersions' => $report->getActiveVersionChart(),
	'cmsVersions' => $report->getCMSVersionChart(),
	'phpVersions' => $report->getPhpVersionChart(),
	'requests' => $report->getRequestChart(),
];

$basicChartData = [];
foreach($charts as $key => $chart) {
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

	<title>Update Server Stats - <?php echo htmlentities($report->getSlug()); ?></title>

	<!-- Bootstrap -->
	<link href="vendor/twbs/bootstrap/dist/css/bootstrap.min.css" rel="stylesheet">
	<!-- Bootstrap theme -->
	<link href="vendor/twbs/bootstrap/dist/css/bootstrap-theme.css" rel="stylesheet">
	<!-- Project-specific styles -->
	<link href="assets/main.css" rel="stylesheet">

	<!-- jQuery -->
	<script type="text/javascript" src="vendor/components/jquery/jquery.min.js"></script>
	<!-- Moment.js -->
	<script type="text/javascript" src="vendor/moment/moment/min/moment.min.js"></script>

	<!-- Bootstrap date range picker -->
	<script type="text/javascript" src="assets/daterangepicker/daterangepicker.js"></script>
	<link href="assets/daterangepicker/daterangepicker.css" rel="stylesheet">

	<script type="text/javascript" src="https://www.gstatic.com/charts/loader.js"></script>
	<script type="text/javascript">
		google.charts.load('current', {'packages': ['corechart']});
		google.charts.setOnLoadCallback(drawAllCharts);

		function drawAreaChart(id, data) {
			//Reverse column order so that stacking happens top-to-bottom.
			for(var i = 0; i < data.length; i++) {
				data[i].reverse();
				var realFirstColumn = data[i].pop();
				data[i].unshift(realFirstColumn);
			}

			//Also reverse series colors to match.
			var defaultColors = [
				"#3366cc", "#dc3912", "#ff9900", "#109618", "#990099", "#0099c6", "#dd4477", "#66aa00", "#b82e2e",
				"#316395", "#994499", "#22aa99", "#aaaa11", "#6633cc", "#e67300", "#8b0707", "#651067", "#329262",
				"#5574a6", "#3b3eac", "#b77322", "#16d620", "#b91383", "#f4359e", "#9c5935", "#a9c413", "#2a778d",
				"#668d1c", "#bea413", "#0c5922", "#743411"
			];

			var seriesSettings = [];
			var numOfSeries = data[0].length - 1;
			for (var j = 0; j < numOfSeries; j++) {
				seriesSettings.push({ color: defaultColors[j % defaultColors.length] });
			}
			seriesSettings.reverse();

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
				},

				series: seriesSettings
			};

			var chart = new google.visualization.AreaChart(document.getElementById(id));
			chart.draw(google.visualization.arrayToDataTable(data), options);
		}

		function drawPieChart(id, data) {
			var pieOptions = {
				legend: 'none',
				chartArea: {
					width: '100%',
					height: '100%',
					left: 8,
					right: 8,
					top: 0
				}
			};

			var pieChart = new google.visualization.PieChart(document.getElementById(id));
			pieChart.draw(google.visualization.arrayToDataTable(data), pieOptions);
		}

		function drawAllCharts() {
			var chartData = (<?php echo json_encode($basicChartData); ?>);

			drawAreaChart('active-install-history', chartData.activeInstalls.area);

			drawAreaChart('active-version-history', chartData.activeVersions.area);
			drawPieChart('active-version-pie', chartData.activeVersions.pie);

			drawAreaChart('cms-version-history', chartData.cmsVersions.area);
			drawPieChart('cms-version-pie', chartData.cmsVersions.pie);

			drawAreaChart('php-version-history', chartData.phpVersions.area);
			drawPieChart('php-version-pie', chartData.phpVersions.pie);

			drawAreaChart('request-chart', chartData.requests.area);
		}

		//Initialise the date range picker.
		jQuery(function($) {
			var startDate = moment('<?php echo $report->getDateRange()->startDate(); ?>'),
				endDate = moment('<?php echo $report->getDateRange()->endDate(); ?>');

			function setDateRangeDisplay(start, end) {
				$('#date-range').find('span').text(
					start.format('MMMM D, YYYY') + ' - ' + end.format('MMMM D, YYYY')
				);

				$('#from-date').val(start.format('YYYY-MM-DD'));
				$('#to-date').val(end.format('YYYY-MM-DD'));
			}

			setDateRangeDisplay(startDate, endDate);

			$('#date-range').daterangepicker(
				{
					opens: 'left',
					locale: {
						format: 'YYYY-MM-DD'
					},
					ranges: {
						'Last 7 Days': [moment().subtract(6, 'days'), moment()],
						'Last 30 Days': [moment().subtract(30, 'days'), moment().subtract(1, 'days')],
						'This Month': [moment().startOf('month'), moment().endOf('month')],
						'Last Month': [moment().subtract(1, 'month').startOf('month'), moment().subtract(1, 'month').endOf('month')]
					},
					alwaysShowCalendars: true,
					startDate: startDate,
					endDate: endDate
				},
				function(start, end) {
					setDateRangeDisplay(start, end);
					$('#query-parameters').submit();
				}
			);

			$('#selected-slug').change(function () {
				$('#query-parameters').submit();
			});
		});
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
				<form class="navbar-form" id="query-parameters" action="" method="get">
					<div class="form-group">
						<label for="selected-slug">
							Slug:
						</label>
						<select name="slug" id="selected-slug" class="form-control">
							<?php
							foreach($availableSlugs as $option) {
								/** @noinspection HtmlUnknownAttribute */
								printf(
									'<option value="%1$s" %2$s>%1$s</option>',
									htmlentities($option),
									$option === $report->getSlug() ? ' selected="selected"' : ''
								);
							}
							?>
						</select>

						<!-- Date range picker -->
						<div id="date-range" class="form-control">
							<i class="glyphicon glyphicon-calendar fa fa-calendar"></i>&nbsp;
							<span></span> <b class="caret"></b>
						</div>

						<input class="form-control" name="from" id="from-date" value="<?php
							echo htmlentities($report->getDateRange()->startDate());
						?>" type="hidden">
						<input class="form-control input-small" name="to" id="to-date" value="<?php
							echo htmlentities($report->getDateRange()->endDate());
						?>" type="hidden">

					</div> <!-- /.form-group -->
				</form>
			</div><!--/.nav-collapse -->


		</div>
	</nav>

	<div class="container" role="main">

		<div class="row">
			<div class="col-md-12"><h2>Active installs</h2></div>
		</div>
		<div class="row">
			<div class="col-md-9">
				<div id="active-install-history" class="chart"></div>
			</div>
			<div class="col-md-3">
				<table class="table table-hover" id="summary-sidebar">
					<tr>
						<th>
							Active installs
							<br><small>(7 day average)</small>
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
						<th>
							Per site
							<br><small>(daily average)</small>
						</th>
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
			<div class="col-md-12"><h2>CMS versions</h2></div>
		</div>
		<div class="row">
			<div class="col-md-9">
				<div id="cms-version-history" class="chart area-chart"></div>
			</div>
			<div class="col-md-3">
				<div id="cms-version-pie" class="chart"></div>
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

		<div class="row">
		<?php
		$combos = [
			'CMS vs Package version' => [
				'CMS' => 'cms_version_aggregate',
				'Package version' => 'installed_version',
			],
			'PHP vs Package version' => [
				'PHP' => 'php_version_aggregate',
				'Package version' => 'installed_version',
			],
		];

		foreach($combos as $title => $metricNames):
			$rows = $report->getVersionCombinations(...array_values($metricNames));
			$totalSites = max($report->getActiveInstalls(1), 1);
		?>

			<div class="col-md-6">
				<h2>
					<?php echo $title; ?>
					<small>(<?php
						echo htmlentities($report->getDateRange()->endDate('M j, Y'));
						?>)</small>
				</h2>

				<?php if (!empty($rows)): ?>
				<table class="table">
					<thead>
						<tr>
							<th><?php echo key($metricNames); ?></th>
							<th>10th percentile</th>
							<th><abbr title="Median">50th percentile</abbr></th>
							<th>90th percentile</th>
						</tr>
					</thead>
					<tbody>
					<?php
					$maxSites = max(array_map(function($row) { return $row['unique_sites']; }, $rows));

					foreach($rows as $row):
						//Display a percentage bar behind the base version number. Note that it's relative
						//to the most common version, not the total number of installs. This way the bars
						//are longer and it's easier to visually compare the popularity of different versions.
						$barWidth = sprintf('%.2f', max(0.01, min($row['unique_sites'] / $maxSites, 1)) * 100) . '%';
						//Include a mouse-over tooltip that shows the actual values.
						$baseMetricTooltip = sprintf(
							'%.2f%% of active installs (%s sites)',
							$row['unique_sites'] / $totalSites * 100,
							number_format($row['unique_sites'], 0)
						);
					?>
						<tr>
							<td class="combo-base-version-number"
							    title="<?php echo htmlentities($baseMetricTooltip); ?>">
								<div
									class="bg-success cell-background-bar"
									style="width: <?php echo htmlentities($barWidth); ?>">
								</div>
								<?php echo htmlentities($row['metric1_value']); ?>
							</td>
							<td><?php echo htmlentities($row['percentile10th']); ?></td>
							<td><?php echo htmlentities($row['percentile50th']); ?></td>
							<td><?php echo htmlentities($row['percentile90th']); ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
				<?php else: ?>
					<p>No data available.</p>
				<?php endif; ?>
			</div>

		<?php
		endforeach;
		?>
		</div>

	</div>

	<!-- jQuery (necessary for Bootstrap's JavaScript plugins). -->
	<script src="vendor/components/jquery/jquery.min.js"></script>
	<!-- Include all compiled Bootstrap plugins. -->
	<script src="vendor/twbs/bootstrap/dist/js/bootstrap.min.js"></script>
</body>
</html>

