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
		$groupDayThreshold = 0.09,
		$maxUngroupedSeries = 19
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

			$isFractionOk = (($groupedTotal + $keyTotal)/$total) <= $otherGroupFraction;
			$tooManyUngroupedSeries = count($data) > $maxUngroupedSeries;
			if (($total > 0) && ($isFractionOk || $tooManyUngroupedSeries)) {
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

		//Sort by version number.
		if (isset($sortCallback)) {
			uksort($data, $sortCallback);
		}

		if ($groupedTotal > 0) {
			$data['Other'] = $groupedData;
		}

		//Special case: No data for this metric.
		if (empty($data)) {
			$data[sprintf('No data')] = array_fill_keys($dateRange->getDateKeys(), 0);
		}

		$this->stats = $data;
	}

	public function renameEmptyValueSeries($newName) {
		if (!isset($this->stats[''])) {
			return $this;
		}

		$series = $this->stats[''];
		$this->stats[$newName] = $series;
		unset($this->stats['']);
		return $this;
	}

	public function getAreaChartData() {
		$rows = [];

		//X-axis labels
		$rows[] = array_merge(
			[['label' => 'Date', 'type' => 'date']],
			array_map('strval', array_keys($this->stats))
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
		$day = (array_keys(array_slice($this->totalsByDate, $dayIndex, 1)))[0];

		$rows = [];
		$isAllZero = true;
		foreach($this->stats as $key => $dailyStats) {
			$pointValue = isset($dailyStats[$day]) ? $dailyStats[$day] : 0;
			$rows[] = [strval($key), $pointValue];
			$isAllZero = $isAllZero && ($pointValue === 0);
		}

		array_unshift($rows, ['Key', $label]);

		if (!$isAllZero) {
			return $rows;
		} else {
			return [
				['Key', 'Value'],
				['No data for ' . $day, 1]
			];
		}
	}
}