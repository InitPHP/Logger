# InitPHP Logger

Logger class in accordance with PSR-3 standards

[![Latest Stable Version](http://poser.pugx.org/initphp/logger/v)](https://packagist.org/packages/initphp/logger) [![Total Downloads](http://poser.pugx.org/initphp/logger/downloads)](https://packagist.org/packages/initphp/logger) [![Latest Unstable Version](http://poser.pugx.org/initphp/logger/v/unstable)](https://packagist.org/packages/initphp/logger) [![License](http://poser.pugx.org/initphp/logger/license)](https://packagist.org/packages/initphp/logger) [![PHP Version Require](http://poser.pugx.org/initphp/logger/require/php)](https://packagist.org/packages/initphp/logger)

## Features

- Keeping logs to the database with PDO.
- Printing log records to a file.
- Logging feature with multiple drivers.

## Requirements

- PHP 5.6 or higher
- [PSR-3 Interface Package](https://www.php-fig.org/psr/psr-3/)
- PDO Extension (Only `PDOLogger`)

## Installation

```
composer require initphp/logger
```

## Using

### FileLogger

```php 
require_once "vendor/autoload.php";
use \InitPHP\Logger\Logger;
use \InitPHP\Logger\FileLogger;

$logFile = __DIR__ . '/logfile.log';

$logger = new Logger(new FileLogger($logFile));
```

### PdoLogger

```php 
require_once "vendor/autoload.php";
use \InitPHP\Logger\Logger;
use \InitPHP\Logger\PDOLogger;

$table = 'logs';
$pdo = new \PDO('mysql:dbname=project;host=localhost', 'root', '');

$logger = new Logger(new PDOLogger($pdo, $table));

$logger->error('User {user} caused an error.', array('user' => 'muhametsafak'));
// INSERT INTO logs (level, message, date) VALUES ('ERROR', 'User muhametsafak caused an error.', '2022-03-11 13:05:45')
```

You can use the following SQL statement to create a sample MySQL table.

```sql 
CREATE TABLE `logs` (
    `level` ENUM('EMERGENCY','ALERT','CRITICAL','ERROR','WARNING','NOTICE','INFO','DEBUG') NOT NULL,
    `message` TEXT NOT NULL,
    `date` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE = InnoDB CHARSET=utf8mb4 COLLATE utf8mb4_general_ci;
```

### Multi Logger

```php 
require_once "vendor/autoload.php";
use \InitPHP\Logger\Logger;
use \InitPHP\Logger\PDOLogger;
use \InitPHP\Logger\FileLogger;

$logFile = __DIR__ . '/logfile.log';

$table = 'logs';
$pdo = new \PDO('mysql:dbname=project;host=localhost', 'root', '');

$logger = new Logger(new FileLogger($logFile), new PDOLogger($pdo, $table));
```

## Methods

```php
public function emergency(string $msg, array $context = array()): void;

public function alert(string $msg, array $context = array()): void;

public function critical(string $msg, array $context = array()): void;

public function error(string $msg, array $context = array()): void;

public function warning(string $msg, array $context = array()): void;

public function notice(string $msg, array $context = array()): void;

public function info(string $msg, array $context = array()): void;

public function debug(string $msg, array $context = array()): void;

public function log(string $level, string $msg, array $context = array()): void;
```

All of the above methods are used the same way, except for the `log()` method. You can use the `log()` method for your own custom error levels.

**Example 1 :**

```php
$logger->emergency("Something went wrong");
```

It prints an output like this to the log file.

```
2021-09-29T13:34:47+02:00 [EMERGENCY] Something went wrong
```

**Example 2:**

```php
$logger->error("User {username} caused an error.", ["username" => "john"]);
```

It prints an output like this to the log file.

```
2021-09-29T13:34:47+02:00 [ERROR] User john caused an error.
```

That is all.

***

## Getting Help

If you have questions, concerns, bug reports, etc, please file an issue in this repository's Issue Tracker.

## Credits

- [Muhammet ŞAFAK](https://www.muhammetsafak.com.tr)

## License

Copyright &copy; 2022 [MIT License](./LICENSE)
