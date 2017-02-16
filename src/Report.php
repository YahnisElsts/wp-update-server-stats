<?php
namespace Wpup;
use PDO;

class Report {
	private $metricQuery;
	private $slug;
	private $dateRange;

	public function __construct(PDO $database, $parameters = null) {
		if (empty($parameters)) {
			$parameters = $_GET;
		}

		$from = isset($parameters['from']) ? strval($parameters['from']) : null;
		$to = isset($parameters['to']) ? strval($parameters['to']) : null;
		$dateRange = new DateRange($from, $to);
		$this->dateRange = $dateRange;

		$availableSlugs = $database->query('SELECT slug FROM slugs')->fetchAll(PDO::FETCH_COLUMN);
		natcasesort($availableSlugs);

		$this->slug = isset($parameters['slug']) ? strval($parameters['slug']) : null;
		if (empty($this->slug) || !in_array($this->slug, $availableSlugs)) {
			$this->slug = reset($availableSlugs);
		}

		$this->metricQuery = $database->prepare(
			'SELECT "datestamp", "value", "unique_sites", "requests"
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

	public function getActiveVersionChart() {
		return $this->getChart('installed_version', 'unique_sites', 'version_compare');
	}

	public function getWordPressVersionChart() {
		return $this->getChart('wp_version_aggregate', 'unique_sites', 'version_compare');
	}

	public function getPhpVersionChart() {
		return $this->getChart('php_version_aggregate', 'unique_sites', 'version_compare');
	}

	public function getRequestChart() {
		return $this->getChart('action', 'requests', 'strcasecmp', 0);
	}

	public function getChart(
		$metricName,
		$lineValueColumn = 'unique_sites',
		$sortCallback = null,
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
}