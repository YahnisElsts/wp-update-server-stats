<?php
namespace Wpup;
use PDO;

class Report {
	private $metricQuery;
	private $slug;
	private $dateRange;

	public function __construct(PDO $database, $slug, $parameters = null) {
		$this->slug = $slug;

		if (empty($parameters)) {
			$parameters = $_GET;
		}

		$from = isset($parameters['from']) ? strval($parameters['from']) : null;
		$to = isset($parameters['to']) ? strval($parameters['to']) : null;
		$dateRange = new DateRange($from . ' UTC', $to . ' UTC');
		$this->dateRange = $dateRange;

		$this->metricQuery = $database->prepare(
			'SELECT "datestamp", COALESCE("value", \'N/A\') as "value", "unique_sites", "requests"
			 FROM stats
			 JOIN slugs ON slugs.slug_id = stats.slug_id
			 JOIN metrics ON metrics.metric_id = stats.metric_id
			 WHERE
			     slugs.slug = :slug
			     AND metrics.metric = :metric
			     AND datestamp >= :fromDate AND datestamp <= :toDate
			 ORDER BY datestamp ASC'
		);
	}

	private function cache($func, $key = null) {
		static $cachedResults = [];
		if ($key === null) {
			$key = is_string($func) ? $func : spl_object_hash($func);
		}
		if (!array_key_exists($key, $cachedResults)) {
			$cachedResults[$key] = $func();
		}
		return $cachedResults[$key];
	}

	public function getActiveVersionChart() {
		return $this->cache(function() {
			return $this->getChart('installed_version', 'version_compare');
		}, __METHOD__);
	}

	public function getWordPressVersionChart() {
		return $this->cache(function() {
			return $this->getChart('wp_version_aggregate', 'version_compare');
		}, __METHOD__);
	}

	public function getPhpVersionChart() {
		return $this->cache(function() {
			return $this->getChart('php_version_aggregate', 'version_compare');
		}, __METHOD__);
	}

	public function getRequestChart() {
		return $this->cache(function() {
			return $this->getChart('action', 'strcasecmp', 'requests', 0);
		}, __METHOD__);
	}

	public function getActiveInstallsChart() {
		return $this->cache(function() {
			return $this->getChart('total_hits')->renameEmptyValueSeries('Unique sites');
		}, __METHOD__);
	}

	public function getChart(
		$metricName,
		$sortCallback = null,
		$lineValueColumn = 'unique_sites',
		$otherGroupFraction = 0.15,
		$groupDayThreshold = 0.1
	) {
		$this->metricQuery->execute([
			':slug' => $this->slug,
			':metric' => $metricName,
			':fromDate' => $this->dateRange->startDate(),
			':toDate' => $this->dateRange->endDate(),
		]);

		return new ChartData(
			$this->metricQuery->fetchAll(PDO::FETCH_ASSOC),
			$this->dateRange,
			$lineValueColumn,
			$sortCallback,
			$otherGroupFraction,
			$groupDayThreshold
		);
	}

	public function getTotalRequests() {
		return $this->cache(function() {
			$requestStats = $this->getRequestChart();
			return array_sum($requestStats->getTotalsByDate());
		}, __METHOD__);
	}

	/**
	 * Get the average number of active installs over the last X days.
	 *
	 * @param int $days
	 * @return float
	 */
	public function getActiveInstalls($days = 7) {
		if ($days <= 0) {
			throw new \LogicException('The number of days must be an integer greater than zero');
		}
		return array_sum(array_slice($this->getActiveInstallsChart()->getTotalsByDate(), -$days)) / $days;
	}

	public function getInstallsPerDay() {
		$dailyStats = $this->getActiveInstallsChart()->getTotalsByDate();

		//You can't compute a trend from one data point.
		if (count($dailyStats) <= 1) {
			return 0;
		}

		$firstDay = $this->dateRange->startDate();
		$lastDay = $this->dateRange->endDate();
		return ($dailyStats[$lastDay] - $dailyStats[$firstDay]) / count($dailyStats);
	}

	public function getRequestsPerSite() {
		$totalUniquesPerDay = array_sum($this->getActiveInstallsChart()->getTotalsByDate());
		if (($totalUniquesPerDay > 0) && ($this->getTotalRequests() > 0)) {
			return $this->getTotalRequests() / $totalUniquesPerDay;
		}
		return 0;
	}

	public function getDateRange() {
		return $this->dateRange;
	}

	public function getSlug() {
		return $this->slug;
	}
}