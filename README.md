# wp-update-server-stats
Gathers statistics from [wp-update-server](https://github.com/YahnisElsts/wp-update-server) logs and displays a bunch of pretty charts.

Installation using Composer
---------------------------

```
composer create-project yahnis-elsts/wp-update-server-stats
```

Requirements
------------
PHP 5.6 or above with the SQLite extension.

Usage
-----

Run `update.php` to analyse log files:
```
php update.php --dir "/path/to/wp-update-server/logs" --from-last-date
```

This will process all `.log` files in the specified directory and dump various statistics into an SQLite database. Initial processing can take a while. If your logs are very large, you can restrict analysis to a specific date range by using the `--from` and `--to` parameters. Run `php update.php --help` to see all available options.

After the process is complete, navigate to `wp-update-server-stats/index.php` in the browser to view the results.

Screenshots
-----------

![Update stats](screenshots/update-stats-fullpage.png?raw=true)