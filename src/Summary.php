<?php
namespace Wpup;

class Summary {
	/**
	 * @var array Stores running totals as a deeply nested associative array.
	 */
	public $items = [];

	public function recordEvent($date, $slug, $metric, $value, $siteUrl) {
		$array = &$this->items;
		foreach([$date, $slug, $metric, $value] as $key) {
			if (!isset($array[$key])) {
				$array[$key] = [];
			}
			$array = &$array[$key];
		}

		if (empty($array)) {
			$array['requests'] = 0;
			$array['sites'] = [];
		}

		$array['requests']++;
		$array['sites'][$siteUrl] = true;
	}

	public function clear() {
		$this->items = [];
	}

	public function getIterator() {
		//This isn't exactly elegant code, but I haven't found a better solution.
		foreach($this->items as $date => $slugs) {
			foreach ($slugs as $slug => $metrics) {
				foreach($metrics as $metric => $values) {
					foreach ($values as $value => $stats) {
						yield array(
							'date'       => $date,
							'slug'       => $slug,
							'metric'     => $metric,
							'value'      => $value,
							'requests'   => $stats['requests'],
							'unique_sites' => count($stats['sites']),
						);
					}
				}
			}
		}
	}
}