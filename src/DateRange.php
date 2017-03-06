<?php
namespace Wpup;

class DateRange {
	const DAY_IN_SECONDS = 24 * 3600;

	private $startTimestamp;
	private $endTimestamp;

	public function __construct($startDate = null, $endDate = null) {
		if (is_string($endDate)) {
			$endDate = strtotime($endDate);
		}
		if (empty($endDate)) {
			$endDate = strtotime('-1 day 00:00 UTC');
		}
		if (is_string($startDate)) {
			$startDate = strtotime($startDate);
		}
		if (empty($startDate)) {
			$startDate = $endDate - 31 * self::DAY_IN_SECONDS;
		}

		$this->startTimestamp = min($startDate, $endDate);
		$this->endTimestamp = max($startDate, $endDate);
	}

	public function getDayCount() {
		return intval(ceil(($this->endTimestamp - $this->startTimestamp) / self::DAY_IN_SECONDS));
	}

	public function getDateKeys() {
		static $days = null;
		if ($days === null) {
			$days = [];
			for (
				$timestamp = $this->startTimestamp;
				$timestamp <= $this->endTimestamp;
				$timestamp = $timestamp + self::DAY_IN_SECONDS
			) {
				$days[] = gmdate('Y-m-d', $timestamp);
			}
		}
		return $days;
	}

	public function startDate($format = 'Y-m-d') {
		return gmdate($format, $this->startTimestamp);
	}

	public function endDate($format = 'Y-m-d') {
		return gmdate($format, $this->endTimestamp);
	}

	public function getDuration() {
		return $this->endTimestamp - $this->startTimestamp;
	}
}