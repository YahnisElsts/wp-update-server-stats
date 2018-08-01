<?php
require __DIR__ . '/vendor/autoload.php';

if (php_sapi_name() !== "cli") {
	echo 'Error: This script must be run from the command line.';
	exit;
}

(new \Wpup\LogParserCli)->run();