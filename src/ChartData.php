<?php
namespace Wpup;

class ChartData {
	private $stats;
	private $dateRange;

	private $totalsByDate;

	public function __construct(
		$rows,
		DateRange $dateRange,
		$lineValueColumn = 'unique_sites',
		$sortCallback = null,
		$otherGroupFraction = 0.20,
		$groupDayThreshold = 0.09
	) {
		$this->dateRange = $dateRange;

		$data = [];
		$totalsByKey = [];
		$totalsByDate = array_fill_keys($dateRange->getDateKeys(), 0);
		foreach($rows as $row) {
			if (!isset($data[$row['value']])) {
				$data[$row['value']] = [];
				$totalsByKey[$row['value']] = 0;
			}

			$eventCount = intval($row[$lineValueColumn]);
			$data[$row['value']][$row['datestamp']] = $eventCount;

			$totalsByKey[$row['value']] += $eventCount;
			$totalsByDate[$row['datestamp']] += $eventCount;
		};

		$this->totalsByDate = $totalsByDate;

		//Order the keys from least common to most common.
		asort($totalsByKey);

		//Disable grouping for keys that meet the threshold.
		$canGroup = array();
		foreach($data as $key => $stats) {
			$canGroup[$key] = true;
			foreach($stats as $timestamp => $cnt) {
				$dailyRatio = ($totalsByDate[$timestamp] > 0) ? ($cnt / $totalsByDate[$timestamp]) : 0;
				if ($dailyRatio >= $groupDayThreshold) {
					$canGroup[$key] = false;
					break;
				}
			}
		}

		//Group up to XY% of the keys.
		$total = array_sum($totalsByKey);
		$groupedTotal = 0;
		$groupedData = array();
		foreach($totalsByKey as $key => $keyTotal) {
			if (!$canGroup[$key]) {
				continue;
			}

			if ((($groupedTotal + $keyTotal)/$total) <= $otherGroupFraction) {
				//printf('Grouping key "%s" that has %d total events<br>', $key, $keyTotal);
				$groupedTotal += $keyTotal;
				foreach($data[$key] as $timestamp => $cnt) {
					if ( !isset($groupedData[$timestamp]) ) {
						$groupedData[$timestamp] = 0;
					}
					$groupedData[$timestamp] += $cnt;
				}
				unset($data[$key]);
			} else {
				//We've already grouped as many uncommon keys as we can.
				break;
			}
		}

		if ($groupedTotal > 0) {
			$data['Other'] = $groupedData;
		}

		//Sort by version number.
		if (isset($sortCallback)) {
			uksort($data, $sortCallback);
		}

		//Special case: No data for this metric.
		if (empty($data)) {
			$data[sprintf('No data')] = array_fill_keys($dateRange->getDateKeys(), 0);
		}

		$this->stats = $data;
		//var_dump($data, $totalsByDate);
	}

	public function getAreaChartData() {
		$rows = [];

		//X-axis labels
		$rows[] = array_merge(
			[['label' => 'Date', 'type' => 'date']],
			array_keys($this->stats)
		);

		foreach($this->dateRange->getDateKeys() as $day) {
			$timestamp = strtotime($day . 'UTC');
			$row = [sprintf(
				'Date(%s, %d, %s)',
				gmdate('Y', $timestamp),
				intval(gmdate('m', $timestamp) - 1),
				gmdate('d', $timestamp)
			)];

			foreach($this->stats as $key => $eventsByDate) {
				$row[] = isset($eventsByDate[$day]) ? $eventsByDate[$day] : 0;
			}
			$rows[] = $row;
		}

		return $rows;
	}

	public function getTotalsByDate() {
		return $this->totalsByDate;
	}

	public function getPieChartData($label = 'Value', $dayIndex = -1) {
		$day = reset(array_keys(array_slice($this->totalsByDate, $dayIndex, 1)));

		$rows = [];
		foreach($this->stats as $key => $dailyStats) {
			$rows[] = [$key, isset($dailyStats[$day]) ? $dailyStats[$day] : 0];
		}
		$rows = array_reverse($rows);

		array_unshift($rows, ['Date', $label]);
		return $rows;
	}
}