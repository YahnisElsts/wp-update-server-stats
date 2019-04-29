<?php
namespace Wpup;
use \PDO, \PDOStatement;

class BasicLogAnalyser {
	/**
	 * A replacement for version numbers that are clearly invalid or obfuscated.
	 */
	const INVALID_VER_REPLACEMENT = 'obfuscated';

	const MAX_CONSECUTIVE_BAD_LINES = 30;

	private $logFiles;

	/**
	 * @var int Current log line. Note that this is relative to the first processed entry, not the start of the file.
	 */
	private $currentLineNumber = 0;

	/**
	 * @var string[] We'll generate daily stats for these API request parameters.
	 */
	private $enabledParameters = array(
		'installed_version', 'cms', 'cms_version', 'php_version', 'action',
		'cms_version_aggregate', 'php_version_aggregate',
	);

	/**
	 * @var int[string] Lookup table for metric IDs.
	 */
	private $metricIds = array();

	/**
	 * @var int[string] Lookup table for plugin/theme slugs.
	 */
	private $slugIds = array();

	/**
	 * @var string The date that's currently being processed.
	 */
	private $currentDate = null;

	/**
	 * @var int The number of log lines parsed that have the current date.
	 */
	private $currentDayLineCount = 0;

	private $encounteredSlugs = array();

	/**
	 * @var PDO Statistics database
	 */
	private $database;

	/** @var PDOStatement */
	private $insertStatement;

	/**
	 * Constructor.
	 *
	 * @param string[]|string $fileNames
	 * @param PDO $database
	 */
	public function __construct($fileNames, $database) {
		$this->logFiles = $this->sortByFirstTimestamp((array)$fileNames);
		$this->database = $database;

		$this->createTables();
		$this->populateLookUps();
		$this->insertStatement = $this->database->prepare(
			'INSERT OR REPLACE INTO stats(datestamp, slug_id, metric_id, value, requests, unique_sites)
			 VALUES(:date, :slug_id, :metric_id, :value, :requests, :unique_sites)'
		);
	}

	/**
	 * Sort log files by the timestamp of the first entry (oldest to newest).
	 *
	 * @param string[] $fileNames
	 * @return string[]
	 */
	private function sortByFirstTimestamp($fileNames) {
		$timestamps = [];

		foreach($fileNames as $fileName) {
			//In case we can't parse the first few entries, the modification time is the fallback.
			$timestamps[$fileName] = filemtime($fileName);

			$sample = file_get_contents($fileName, null, null, 0, 5 * 1024);
			if (empty($sample)) {
				continue;
			}
			foreach(explode("\n", $sample) as $line) {
				try {
					$entry = $this->parseLogEntry($line);
					if (!empty($entry['timestamp'])) {
						$timestamps[$fileName] = $entry['timestamp'];
						break;
					}
				} catch (LogParserException $ex) {
					//Skip malformed line.
				}
			}
		}

		asort($timestamps);
		return array_keys($timestamps);
	}

	/**
	 * Analyse a log file and save various statistics to the database.
	 *
	 * You can use the optional $fromTimestamp and $toTimestamp arguments to restrict analysis to a specific
	 * date range. This is useful if the log file is very large and/or you want to do incremental stats updates.
	 * Note: This method assumes that the file is sorted in chronological order.
	 *
	 * @param string|int $fromTimestamp
	 * @param string|int $toTimestamp
	 * @param bool $ignoreConsecutiveErrors
	 */
	public function parse($fromTimestamp = null, $toTimestamp = null, $ignoreConsecutiveErrors = false) {
		if (isset($fromTimestamp) && !is_int($fromTimestamp)) {
			$fromTimestamp = strtotime($fromTimestamp);
		}
		if (isset($toTimestamp) && !is_int($toTimestamp)) {
			$toTimestamp = strtotime($toTimestamp);
		}

		$this->currentDate = null;

		$input = new MultiFileReader($this->logFiles);

		if ( isset($fromTimestamp) ) {
			$this->output(sprintf(
				'Searching for the first entry with a timestamp equal or greater than %s',
				gmdate('Y-m-d H:i:s O', $fromTimestamp)
			));

			//Binary search the file to find the first entry that's >= $fromTimestamp.
			$firstLineOffset = $this->findFirstEntryByTimestamp($input, $fromTimestamp);
			if ( $firstLineOffset === null ) {
				$this->output("There are no log entries matching that timestamp.");
				return;
			} else {
				$this->output(sprintf(
					'Found an entry with a timestamp >= %s at offset %.0f.',
					gmdate('Y-m-d H:i:s O', $fromTimestamp),
					$firstLineOffset
				));
				$input->fseek($firstLineOffset);
			}
		}

		$this->currentLineNumber = 0;

		/** @noinspection SqlResolve PhpStorm doesn't recognize temporary databases, big surprise. */
		$insertLogEntry = $this->database->prepare(
			'INSERT OR IGNORE INTO "scratch"."log" (
				"slug_id", "action", "installed_version", "cms", "cms_version", "php_version", 
				"cms_version_aggregate", "php_version_aggregate", "site_url"
			)
			 VALUES(
			 	:slug_id, :action, :installed_version, :cms, :cms_version, :php_version, 
			 	:cms_version_aggregate, :php_version_aggregate, :site_url
			 )'
		);

		$this->database->beginTransaction();
		$lastHour = -1;
		$consecutiveMalformedLines = 0;

		while (!$input->feof()) {
			$this->currentLineNumber++;

			$line = $input->fgets();

			//Skip empty lines.
			if (empty($line)) {
				continue;
			}

			try {
				$entry = $this->parseLogEntry($line);
				$consecutiveMalformedLines = 0;
			} catch (LogParserException $ex) {
				$this->output($ex->getMessage());
				$consecutiveMalformedLines++;

				if (!$ignoreConsecutiveErrors && ($consecutiveMalformedLines > self::MAX_CONSECUTIVE_BAD_LINES)) {
					$this->output('Error: Too many consecutive bad lines. Is this really a valid log file?');
					$this->output('Parsing was stopped. Use --ignore-bad-lines to disable this safeguard.');
					break;
				}
				continue;
			}

			$timestamp = $entry['timestamp'];
			$date = gmdate('Y-m-d', $timestamp);
			$slug = $entry['slug'];

			//Skip all entries older than the specified "from" timestamp.
			if (isset($fromTimestamp) && $timestamp < $fromTimestamp) {
				continue;
			}

			//The log file is sorted in chronological order, so if we reach an entry newer than the "to" timestamp,
			//we can safely skip *all* following entries.
			if (isset($toTimestamp) && $timestamp >= $toTimestamp) {
				break;
			}

			//Keep track of which slugs we've seen recently.
			if (isset($this->encounteredSlugs[$slug])) {
				$this->encounteredSlugs[$slug] = max($timestamp, $this->encounteredSlugs[$slug]);
			} else {
				$this->encounteredSlugs[$slug] = $timestamp;
			}

			if ($date !== $this->currentDate) {
				$this->database->commit();

				if (isset($this->currentDate)) {
					$this->flushDay();
				}

				$this->currentDate = $date;
				$this->currentDayLineCount = 0;
				printf('%s [', $this->currentDate);
				$lastHour = -1;

				$this->database->beginTransaction();
			}

			$insertLogEntry->execute(array(
				':slug_id' => $this->slugToId($slug),
				':action' => $entry['action'],
				':installed_version' => $entry['installed_version'],
				':cms_version' => $entry['cms_version'],
				':cms_version_aggregate' => $entry['cms_version_aggregate'],
				':php_version' => $entry['php_version'],
				':php_version_aggregate' => $entry['php_version_aggregate'],
				':site_url' => $entry['site_url'],
			));
			$this->currentDayLineCount++;

			//Rudimentary progress bar.
			$thisHour = intval(gmdate('H', $timestamp));
			if ($thisHour !== $lastHour) {
				echo str_repeat('=', max($thisHour - $lastHour, 1));
				$lastHour = $thisHour;
			}
		}

		$this->database->commit();
		$this->flushDay();
		$this->output("Done.");
	}

	/**
	 * Parse a WP Update Server log entry.
	 *
	 * @param string $line
	 * @return array
	 * @throws LogParserException
	 */
	private function parseLogEntry($line) {
		$columns = array(
			'http_method', 'action', 'slug', 'installed_version', 'cms_version',
			'site_url', 'query_string',
		);

		$result = array_fill_keys($columns, null);


		if ( preg_match('/^\[(?P<timestamp>[^\]]+)\]\s(?P<ip>\S+)\s+(?P<remainder>.+)$/', $line, $matches) ) {
			
			$result['timestamp'] = strtotime($matches['timestamp']);
			$result['ip'] = $matches['ip'];

			$values = explode("\t", $matches['remainder']);
			
			foreach($values as $index => $value) {
				if ( isset($columns[$index]) ) {
					$result[$columns[$index]] = $value;
				}
			}

			//In theory, every request must include a slug. In practice, I've seen log entries where the slug
			//was missing. It was probably due to someone manually fiddling with download links.
			if (!isset($result['slug'])) {
				$result['slug'] = '-';
			}

			//PHP version and locale were added much later than other parameters, so they
			//don't have their own log columns. Extract them from the query string.
			$result['php_version'] = null;
			$result['locale'] = null;


			if (!empty($result['query_string'])) {
				parse_str($result['query_string'], $parameters);
				
				if (isset($parameters['cms'])) {
					$parameters['cms'] = str_replace('=', '', $parameters['cms']);
					
					
					if (strpos($parameters['cms'], 'ClassicPress') !== false) {
						$result['cms'] = 'CP';

						/**
						 *
						 * If ClassicPress is detected, will override the cms version with the param "ver" of the site_url
						 *
						 */
						$parts = parse_url($result['site_url']);						
						if(isset($parts['query'])){
							$parts = explode('&', $parts['query']);

							$param = array();
							foreach ($parts as $key => $value) {
								$compatible = explode('=', $value)[0];
								$classicPressVersion = explode('=', $value)[1];
								$param[$compatible] = $classicPressVersion;
							}

							if($param['wp_compatible']){							
								$result['cms_version'] = $param['ver'];
							}							
						}

					}else{
						$result['cms'] = 'WP';						
					}
					
				}else{
					$result['cms'] = 'WP';					
				}
				if (isset($parameters['php'])) {
					$result['php_version'] = strval($parameters['php']);
				}
				if (isset($parameters['locale'])) {
					$result['locale'] = strval($parameters['locale']);
				}
			}

			/**
			 *
			 * If there is not CMS detected, we assume WordPress
			 *
			 */			
			if(!isset($result['cms'])){
				$result['cms'] = 'WP';
			}


			//Some sites obfuscate their WordPress version number or replace it with something weird. We don't
			//want to pollute the stats with those bogus numbers, so we'll group them all together.
			if (isset($result['cms_version'])) {
				if ($result['cms_version'] === '') {
					$result['cms_version'] = '-';
				}
				if (($result['cms_version'] !== '-') && (!$this->looksLikeNormalWpVersion($result['cms_version']))) {
					$result['cms_version'] = self::INVALID_VER_REPLACEMENT;
				}
			}

			//Aggregate WordPress (e.g. 4.7.1 => 4.7).
			foreach(['cms_version'] as $field) {	
				$result[$field . '_aggregate'] = $result['cms'] . '/' .  $this->getAggregateVersion( $result[$field]);
			}


			//Aggregate PHP patch versions (e.g. 4.7.1 => 4.7).
			foreach(['php_version'] as $field) {
				$result[$field . '_aggregate'] = $this->getAggregateVersion( $result[$field]);
			}

		} else {
			throw new LogParserException(sprintf(
				'Failed to parse line #%d',
				$this->currentLineNumber
			));
		}

		return $result;
	}

	/**
	 * Get the major and minor parts of a version number.
	 * For example, "1.2.3-RC1" becomes "1.2".
	 *
	 * @param string|null $versionNumber
	 * @return string|null
	 */
	private function getAggregateVersion($versionNumber) {
		if ($versionNumber === null) {
			return null;
		} else if (preg_match('/^(\d{1,2}\.\d{1,3})(?:\.|$)/', $versionNumber, $matches)) {
			return $matches[1];
		} else if ($versionNumber === self::INVALID_VER_REPLACEMENT) {
			return $versionNumber;
		}
		return null;
	}

	private function flushDay() {
		$startTime = microtime(true);
		$this->database->beginTransaction();

		$this->flushSlugs();

		foreach($this->enabledParameters as $metricName) {
			$statement = $this->database->prepare(
				"INSERT OR REPLACE INTO stats(datestamp, slug_id, metric_id, value, requests, unique_sites) 
				 SELECT 
				 	:datestamp, slug_id, :metric_id, \"$metricName\", 
				 	COUNT(*) as requests, COUNT(DISTINCT site_url) AS unique_sites 
				 FROM scratch.log
				 GROUP BY slug_id, \"$metricName\""
			);
			$statement->execute(array(
				':datestamp' => $this->currentDate,
				':metric_id' => $this->metricToId($metricName),
			));
		}

		//Track the total number of requests + uniques.
		$storeTotalHits = $this->database->prepare(
			"INSERT OR REPLACE INTO stats(datestamp, slug_id, metric_id, value, requests, unique_sites) 
			 SELECT 
			    :datestamp, slug_id, :metric_id, '',
			    COUNT(*) as requests, COUNT(DISTINCT site_url) AS unique_sites 
			 FROM scratch.log
			 GROUP BY slug_id"
		);
		$storeTotalHits->execute(array(
			':datestamp' => $this->currentDate,
			':metric_id' => $this->metricToId('total_hits'),
		));

		//Track the different combinations of platform version vs plugin version.
		//This is useful for seeing if people running old WP/PHP versions update plugins. Storing every
		//combination would take a lot of space, so we just record some percentiles.
		$combinations = [
			['cms_version_aggregate', 'installed_version'],
			['php_version_aggregate', 'installed_version'],
		];
		$insertCombination = $this->database->prepare(
			"INSERT OR REPLACE INTO combinations(
				datestamp, slug_id, metric1_id, metric1_value, metric2_id, 
				percentile10th, percentile50th, percentile90th
			 )
			 VALUES(
				:datestamp, :slug_id, :metric1_id, :metric1_value, :metric2_id, 
				:percentile10th, :percentile50th, :percentile90th
			 )"
		);

		foreach($combinations as list($metric1, $metric2)) {
			$pairData = $this->database->prepare(
				"SELECT slug_id, $metric1, $metric2, COUNT(DISTINCT site_url) as unique_sites
			 	 FROM scratch.log
			 	 WHERE 
			 	 	$metric1 IS NOT NULL 
			 	 	AND $metric2 IS NOT NULL
			 	 	AND $metric1 <> ''
			 	 	AND $metric1 <> '-'
			 	 	AND $metric2 <> ''
			 	 	AND $metric2 <> '-'
			 	 GROUP BY slug_id, $metric1, $metric2"
			);
			$pairData->execute();

			$table = new CompositeIndex(4);
			foreach($pairData as $row) {
				$table->add($row['slug_id'], $row[$metric1], $row[$metric2], $row['unique_sites']);
			}

			foreach($table->rows(2) as list($slugId, $metric1Value, $metric2Values)) {
				uksort($metric2Values, 'version_compare');

				$total = array_sum($metric2Values);
				$thresholds = [$total * 0.1, $total * 0.5, $total * 0.9];
				$thresholdIndex = 0;

				$percentiles = [];
				$sitesBelowThreshold = 0;

				foreach($metric2Values as $version => $sites) {
					$sitesBelowThreshold += $sites;
					while (
						($thresholdIndex < count($thresholds))
						&& ($sitesBelowThreshold >= $thresholds[$thresholdIndex])
					) {
						$percentiles[$thresholdIndex] = $version;
						$thresholdIndex++;
					}
					if ($thresholdIndex >= count($thresholds)) {
						break;
					}
				}

				$insertCombination->execute([
					':datestamp' => $this->currentDate,
					':slug_id' => $slugId,
					':metric1_id' => $this->metricToId($metric1),
					':metric1_value' => $metric1Value,
					':metric2_id' => $this->metricToId($metric2),
					':percentile10th' => $percentiles[0],
					':percentile50th' => $percentiles[1],
					':percentile90th' => $percentiles[2]
				]);
			}
		}

		//Clear the temporary table.
		$this->database->exec('DELETE FROM scratch.log');

		$this->database->commit();

		$this->output(sprintf(
			"] %s lines, DB flush: %.3fs",
			number_format($this->currentDayLineCount, 0, '.', ','),
			microtime(true) - $startTime
		));
		flush();
	}

	/**
	 * @param string $slug
	 * @return int
	 */
	private function slugToId($slug) {
		if (!isset($this->slugIds[$slug])) {
			$insert = $this->database->prepare('INSERT INTO slugs(slug) VALUES(:slug)');
			$insert->execute(array('slug' => $slug));
			$this->slugIds[$slug] = intval($this->database->lastInsertId());
		}
		return $this->slugIds[$slug];
	}

	/**
	 * @param string $metricName
	 * @return int
	 */
	private function metricToId($metricName) {
		if (!isset($this->metricIds[$metricName])) {
			$insert = $this->database->prepare('INSERT INTO metrics(metric) VALUES(:metric)');
			$insert->execute(array('metric' => $metricName));
			$this->metricIds[$metricName] = intval($this->database->lastInsertId());
		}
		return $this->metricIds[$metricName];
	}

	private function flushSlugs() {
		$insertSlug = $this->database->prepare(
			'INSERT INTO slugs(slug, last_seen_on) VALUES(:slug, :last_seen_on)'
		);
		$updateSlugTimestamp = $this->database->prepare(
			'UPDATE slugs SET last_seen_on = MAX(last_seen_on, :last_seen_on) WHERE slug = :slug'
		);

		foreach($this->encounteredSlugs as $slug => $lastSeenTimestamp) {
			$params = array(
				':slug' => $slug,
				':last_seen_on' => gmdate('Y-m-d', $lastSeenTimestamp),
			);
			if ( !isset($this->slugIds[$slug]) ) {
				//New slug, save it.
				$insertSlug->execute($params);
				$this->slugIds[$slug] = $this->database->lastInsertId();
			} else {
				//Previously known slug, update the last_seen_on timestamp.
				$updateSlugTimestamp->execute($params);
			}
		}
	}

	private function looksLikeNormalWpVersion($version) {
		return preg_match('/^\d{1,2}\.\d/', $version);
	}

	/**
	 * Find the first log entry that has a timestamp greater or equal to a specific timestamp.
	 *
	 * @param MultiFileReader $reader File reader object.
	 * @param int $targetTimestamp Unix timestamp to look for.
	 * @return int File offset of the found entry, or NULL if there are no entries with the required timestamp.
	 */
	private function findFirstEntryByTimestamp($reader, $targetTimestamp) {
		$originalPosition = $reader->ftell();

		//Calculate the file size.
		$reader->fseek(0, SEEK_END);
		$fileSize = $reader->ftell();

		//An empty file definitely doesn't contain the timestamp that we're looking for.
		if ($fileSize == 0) {
			return null;
		}

		//Check the first line. Since we skip the first line after each seek, there's no way we
		//would reach it otherwise.
		$reader->fseek(0);
		$line = $reader->fgets();
		$entry = $this->parseLogEntry($line);
		$timestamp = $entry['timestamp'];
		if ( $timestamp >= $targetTimestamp ) {
			//The first line has a timestamp greater or equal to the one we're looking for.
			return 0;
		}

		$beginning = 0;
		$end = $fileSize - 1;

		while($beginning <= $end) {
			$middle = floor($beginning + (($end - $beginning) / 2));
			$reader->fseek($middle);

			//Read and discard a line since we're probably in the middle of one.
			$reader->fgets();

			//Find a line that we can parse.
			$comparison = null;
			while( !$reader->feof() && ($comparison === null) ) {
				$line = $reader->fgets();
				$comparison = null;

				if ( !empty($line) ) {
					try {
						$entry = $this->parseLogEntry($line);
						$timestamp = $entry['timestamp'];
						$comparison = $timestamp - $targetTimestamp;
					} catch (LogParserException $ex) {
						//Eat the exception and skip the line.
					}
				}
			}

			if ( $comparison === null || $comparison >= 0 ) {
				//We found a line that's greater or equal to the target, or all lines after the current
				//midpoint are malformed. So look before the midpoint.
				$end = $middle - 1;
			} else {
				//Otherwise, continue searching after the midpoint.
				$beginning = $middle + 1;
			}
		}

		$reader->fseek($beginning);
		$reader->fgets(); //Discard a line

		$targetPosition = $reader->ftell();
		if ( $targetPosition >= $fileSize ) {
			//We reached the end of the file without ever finding the specified timestamp.
			return null;
		}

		//Restore the original file position.
		$reader->fseek($originalPosition);

		return $targetPosition;
	}

	/**
	 * Create database tables for summary statistics, if they don't already exist.
	 */
	private function createTables() {
		$this->database->exec(
			'CREATE TABLE IF NOT EXISTS metrics (
				metric_id integer not null primary key autoincrement,
				metric varchar(50) unique not null
			)'
		);

		$this->database->beginTransaction();

		$result = $this->database->query('SELECT COUNT(*) FROM metrics', \PDO::FETCH_COLUMN, 0);
		if (intval($result->fetchColumn()) === 0) {
			$metrics = array_merge($this->enabledParameters, ['total_hits']);
			$insert = $this->database->prepare('INSERT INTO metrics(metric) VALUES(:metric)');
			foreach($metrics as $metric) {
				$insert->execute(array('metric' => $metric));
			}
		}

		$this->database->commit();

		$this->database->exec(
			'CREATE TABLE IF NOT EXISTS slugs (
			   slug_id integer not null primary key autoincrement,
			   slug varchar(250) unique not null,
			   last_seen_on datetime
			);'
		);

		$this->database->exec(
			'CREATE TABLE IF NOT EXISTS stats (
			   datestamp date not null,
			   slug_id unsigned integer not null,
			   metric_id unsigned integer not null,
			   value text,
			   requests unsigned integer not null default 0,
			   unique_sites unsigned integer not null default 0
			);'
		);

		$this->database->exec('CREATE UNIQUE INDEX IF NOT EXISTS id_context on stats (datestamp, slug_id, metric_id, value);');

		$this->database->exec(
			'CREATE TABLE IF NOT EXISTS combinations (
				datestamp date not null,
				slug_id integer not null,
				metric1_id integer not null,
				metric1_value varchar(30) null,
				metric2_id integer not null,
				percentile10th varchar(30) null,
				percentile50th varchar(30) null,
				percentile90th varchar(30) null
			)'
		);

		$this->database->exec(
			'CREATE UNIQUE INDEX IF NOT EXISTS idx_combinations ON combinations(
				datestamp ASC,
				slug_id ASC,
				metric1_id ASC,
				metric1_value ASC,
				metric2_id ASC
			)'
		);

		//A temporary database for one day of log data.
		$this->database->exec("ATTACH DATABASE ':memory:' AS scratch");

		$this->database->exec(
			'CREATE TABLE scratch.log (
				"slug_id" integer not null,
				"action" varchar(30) null,
				"installed_version" varchar(30) null,
				"cms" varchar(20) null,
				"cms_version" varchar(20) null,
				"php_version" varchar(20) null,
				"cms_version_aggregate" varchar(15) null,
				"php_version_aggregate" varchar(15) null,
				"site_url" varchar(100) null
			)'
		);

	}

	private function populateLookUps() {
		$metrics = $this->database->query('SELECT metric_id, metric FROM metrics', PDO::FETCH_ASSOC);
		foreach($metrics as $row) {
			$this->metricIds[$row['metric']] = intval($row['metric_id']);
		}
		$metrics->closeCursor();

		$slugs = $this->database->query('SELECT slug_id, slug FROM slugs', PDO::FETCH_ASSOC);
		foreach($slugs as $row) {
			$this->slugIds[$row['slug']] = intval($row['slug_id']);
		}
		$slugs->closeCursor();
	}

	public function getLastProcessedDate() {
		$lastDate = $this->database->query('SELECT MAX("datestamp") AS last_processed_date FROM "stats"')->fetchColumn(0);
		if ( !empty($lastDate) ) {
			return $lastDate;
		}
		return null;
	}

	private function output($message) {
		echo $message, "\n";
	}
}