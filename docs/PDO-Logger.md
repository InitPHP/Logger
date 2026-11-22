# PDOLogger

`InitPHP\Logger\PDOLogger` writes each PSR-3 record as a row in a relational
database table, through a user-supplied `PDO` connection.

```php
use InitPHP\Logger\PDOLogger;

$logger = new PDOLogger(['pdo' => $pdo, 'table' => 'logs']);
$logger->error('Order {id} could not be charged', ['id' => 9182]);
// INSERT INTO logs (level, message, date) VALUES ('ERROR', 'Order 9182 could not be charged', '2026-05-24 14:08:22')
```

## Constructor

```php
public function __construct(array $options = [])
```

| Option | Type | Required | Description |
| --- | --- | --- | --- |
| `pdo` | `\PDO` | **yes** | An already-configured PDO connection. |
| `table` | `string` | **yes** | Destination table name. Must match `/^[A-Za-z_][A-Za-z0-9_]*$/`. |

### Validation

The constructor performs eager validation and raises specific messages for
each failure mode:

| Trigger | Message |
| --- | --- |
| `pdo` key missing | `PDOLogger requires a "pdo" option.` |
| `pdo` not a `PDO` instance | `PDOLogger "pdo" option must be a PDO instance.` |
| `table` key missing | `PDOLogger requires a "table" option.` |
| `table` empty or non-string | `PDOLogger "table" option must be a non-empty string.` |
| `table` fails identifier regex | `PDOLogger "table" option "<value>" is not a valid SQL identifier; expected /^[A-Za-z_][A-Za-z0-9_]*$/.` |

All five conditions raise `\InvalidArgumentException`. Catching the exception
rarely helps — fix the configuration instead.

### Why the table-name regex?

SQL prepared statements parameterise **values**, not **identifiers**. Naming
the table dynamically would require dialect-specific identifier quoting
(`` ` `` for MySQL, `"` for ANSI/PostgreSQL, `[]` for MS SQL Server, …).

Rather than ship a per-dialect quoter, `PDOLogger` refuses any table name that
is not a plain SQL identifier. The regex
`/^[A-Za-z_][A-Za-z0-9_]*$/` accepts:

- ASCII letters, digits and underscores
- The name cannot start with a digit
- The name cannot contain spaces, hyphens, quotes or any SQL meta-character

If your schema needs a name outside that grammar (for example with hyphens),
wrap the logger and quote the identifier yourself — but be aware that the
responsibility for safe identifier handling then sits with your code.

## What gets inserted

For every call, exactly one row is inserted:

```sql
INSERT INTO <table> (level, message, date) VALUES (?, ?, ?)
```

| Column | Value |
| --- | --- |
| `level` | `strtoupper($level)` (e.g. `'ERROR'`) |
| `message` | The interpolated message — placeholders already expanded |
| `date` | `date('Y-m-d H:i:s')` at insertion time |

`message` is bound through PDO, so SQL meta-characters in the payload are
safe:

```php
$logger->info("'; DROP TABLE logs; --");
// stored verbatim as a string; the logs table is untouched.
```

## DDL examples

The package only requires `level`, `message` and `date` columns to exist on
the target table. You are free to add `id` columns, foreign keys, indexes,
JSON metadata columns or anything else — the INSERT only writes the three
defined columns.

### MySQL / MariaDB

```sql
CREATE TABLE `logs` (
    `id`      BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `level`   ENUM('EMERGENCY','ALERT','CRITICAL','ERROR','WARNING','NOTICE','INFO','DEBUG') NOT NULL,
    `message` TEXT NOT NULL,
    `date`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY `idx_logs_level_date` (`level`, `date`)
) ENGINE = InnoDB CHARSET = utf8mb4 COLLATE utf8mb4_general_ci;
```

Using `ENUM` for `level` saves storage and prevents stray values, but a
plain `VARCHAR(10)` works just as well.

### PostgreSQL

```sql
CREATE TYPE log_level AS ENUM (
    'EMERGENCY','ALERT','CRITICAL','ERROR','WARNING','NOTICE','INFO','DEBUG'
);

CREATE TABLE logs (
    id      BIGSERIAL PRIMARY KEY,
    level   log_level NOT NULL,
    message TEXT NOT NULL,
    date    TIMESTAMP NOT NULL DEFAULT NOW()
);

CREATE INDEX idx_logs_level_date ON logs(level, date);
```

### SQLite

```sql
CREATE TABLE logs (
    id      INTEGER PRIMARY KEY AUTOINCREMENT,
    level   TEXT NOT NULL,
    message TEXT NOT NULL,
    date    TEXT NOT NULL
);

CREATE INDEX idx_logs_level_date ON logs(level, date);
```

SQLite stores timestamps as text by convention; the package writes them as
`Y-m-d H:i:s` regardless of the driver.

## Recommended PDO configuration

```php
$pdo = new PDO($dsn, $username, $password, [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
]);
```

- `ERRMODE_EXCEPTION` ensures that PDO problems (lost connection, deadlock,
  duplicate key, …) surface as `PDOException` instead of failing silently.
- `EMULATE_PREPARES => false` gives you real server-side prepared statements
  on backends that support them (MySQL, PostgreSQL).

PSR-3 §1.1 only requires that **the logger** must not raise for ordinary
backend errors; the underlying PDO infrastructure is free to. If a database
outage must not abort the rest of your fan-out chain, wrap `PDOLogger` in a
fault-tolerant decorator — see
[Recipes › Database logger that survives outages](Recipes.md#database-logger-that-survives-outages).

## Reading the table name back

`getTable()` returns the validated, configured table name — useful for
diagnostics and administrative dashboards:

```php
$logger->getTable();   // "logs"
```

## Complete example

```php
<?php
require __DIR__ . '/vendor/autoload.php';

use InitPHP\Logger\PDOLogger;

$pdo = new PDO('mysql:host=localhost;dbname=app;charset=utf8mb4', 'app', 'secret', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);

$logger = new PDOLogger([
    'pdo'   => $pdo,
    'table' => 'logs',
]);

$logger->error('Payment {id} failed: {reason}', [
    'id'     => 9182,
    'reason' => 'gateway timeout',
]);
```

After the call the `logs` table contains:

| id | level | message | date |
| --- | --- | --- | --- |
| 1 | ERROR | Payment 9182 failed: gateway timeout | 2026-05-24 14:08:22 |

## Related

- [Multi-Logger](Multi-Logger.md) — combine `PDOLogger` with other handlers.
- [Context Interpolation](Context-Interpolation.md) — placeholder rules and
  `{exception}` rendering.
- [Error Handling](Error-Handling.md) — what happens when the database is down.
- [Recipes › Database logger that survives outages](Recipes.md#database-logger-that-survives-outages).
- [API Reference › PDOLogger](API-Reference.md#pdologger).
