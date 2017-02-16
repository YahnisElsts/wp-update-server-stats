<?php
namespace Wpup;
use PDO;

class LogParserCli {

	public function run() {
		$options = $this->parseOptions();
		if ($options === null) {
			return;
		}

		$db = new PDO("sqlite:" . $options['database']);
		$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
		$analyser = new BasicLogAnalyser($options['log'], $db);

		$fromLastDate = !empty($options['from-last-date']);
		if ($fromLastDate && !isset($dateRange['from'])) {
			$fromDate = $analyser->getLastProcessedDate();
			if ($fromDate !== null) {
				$options['dateRange']['from'] = strtotime($fromDate . ' UTC');
			} else {
				echo "Ignoring --from-last-date because the database is empty or does not exist.\n";
			}
		}

		$analyser->parse($options['dateRange']['from'], $options['dateRange']['to']);

		printf(
			"Peak memory usage: %.2f MiB\n",
			memory_get_peak_usage(true) / (1024 * 1024)
		);
	}

	/**
	 * Parse and validate command-line arguments.
	 *
	 * @return array|null
	 */
	private function parseOptions() {
		/** @noinspection PhpParamsInspection False positive. */
		$options = getopt(
			'',
			array(
				'log:',
				'database:',
				'from:',
				'to:',
				'from-last-date',
				'help'
			)
		);

		if (empty($options) || isset($options['help'])) {
			$this->showUsage();
			return null;
		}

		if (empty($options['log'])) {
			echo "You must specify a log file name. Example: --log \"/path/to/request.log\"\n";
			return null;
		}

		$log = $options['log'];
		if (!file_exists($log)) {
			echo "Error: Log file not found.\n";
			return null;
		}

		$dbFilename = realpath(__DIR__ . '/../db/stats.db3');
		if (!empty($options['database'])) {
			$dbFilename = $options['database'];
		}
		$options['database'] = $dbFilename;

		//Figure out which part of the log file to analyse.
		$dateRange = array('from' => null, 'to' => null);
		foreach(array_keys($dateRange) as $point) {
			if (!empty($options[$point])) {
				$timestamp = strtotime($options[$point] . ' UTC');
				if (empty($timestamp)) {
					printf("Error: \"%s\" is not a valid date. Expected format: YYYY-MM-DD\n", $options[$point]);
					return null;
				} else {
					$dateRange[$point] = $timestamp;
				}
			}
		}
		$options['dateRange'] = $dateRange;

		$options['from-last-date'] = isset($options['from-last-date']);
		if ($options['from-last-date'] && isset($options['dateRange']['from'])) {
			$options['from-last-date'] = false;
			echo "Ignoring --from-last-date because --from is specified.\n";
		}

		return $options;
	}

	/**
	 * Display usage instructions.
	 */
	private function showUsage() {
		$fileName = basename(__FILE__);
		echo <<<EOT
Usage: php {$fileName} --log "/path/to/reqest.log" [args...]

All options except "--log" are optional.
  --log <file>        Parse this log file for request statistics. Required.
  --database <file>   Override the default database file name.
  --from <YYYY-MM-DD> Start parsing from this date (UTC).
  --to   <YYYY-MM-DD> Parse up to this date (UTC).
  --from-last-date    Automatically restart analysis from the last processed date.
                      If the database is empty this flag has no effect.
  --help              Display this message.

EOT;
	}
}
