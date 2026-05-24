# `PDOLogger`

`InitPHP\Logger\PDOLogger` writes each PSR-3 record as a row in a relational
database table, through a user-supplied `PDO` connection.

## Options

| Key | Type | Required | Description |
| --- | --- | --- | --- |
| `pdo` | `\PDO` | yes | An already-configured PDO connection. |
| `table` | `string` | yes | Destination table name. Must match `/^[A-Za-z_][A-Za-z0-9_]*$/`. |

The constructor validates both keys and raises `\InvalidArgumentException` with
a specific message for each failure mode:

```text
PDOLogger requires a "pdo" option.
PDOLogger "pdo" option must be a PDO instance.
PDOLogger requires a "table" option.
PDOLogger "table" option must be a non-empty string.
PDOLogger "table" option "<value>" is not a valid SQL identifier; expected /^[A-Za-z_][A-Za-z0-9_]*$/.
```

## Why the table-name regex?

Prepared statements parameterise **values**, not **identifiers**. To keep
`PDOLogger` injection-safe without depending on a per-driver quoting helper,
the constructor refuses any table name that is not a plain SQL identifier.

If your schema needs a name outside that grammar (e.g. with hyphens), wrap the
logger and quote the identifier yourself — but be aware that the responsibility
for safe identifier handling then sits with your code.

## What gets inserted

For every call, exactly one row is inserted:

```sql
INSERT INTO <table> (level, message, date) VALUES (?, ?, ?)
```

| Column | Value |
| --- | --- |
| `level` | `strtoupper($level)` — e.g. `'ERROR'` |
| `message` | The interpolated message (placeholders already expanded) |
| `date` | `date('Y-m-d H:i:s')` at insertion time |

`message` is bound through PDO, so SQL metacharacters in the payload are safe.

## Reference DDL

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

You only need the `level`, `message` and `date` columns — extra columns are
ignored by the INSERT. Add an `id` (or other surrogate key), foreign keys,
hashed indexes etc. to suit your operational needs.

## Recommended PDO configuration

```php
$pdo = new PDO($dsn, $username, $password, [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
]);
```

`ERRMODE_EXCEPTION` ensures that PDO problems (lost connection, deadlock, etc.)
surface as `PDOException` rather than silently failing. PSR-3 §1.1 only
mandates that **the logger** must not raise for ordinary backend errors; the
underlying PDO infrastructure is free to.

If you wrap `PDOLogger` in `InitPHP\Logger\Logger` together with a
`FileLogger`, a database outage will not prevent your file log from being
written — see [`04-multi-logger.md`](04-multi-logger.md#fault-isolation).

## Example

```php
use InitPHP\Logger\PDOLogger;

$pdo = new PDO('mysql:host=localhost;dbname=app;charset=utf8mb4', 'app', 'secret', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);

$logger = new PDOLogger([
    'pdo'   => $pdo,
    'table' => 'logs',
]);

$logger->error('Order {id} could not be charged', ['id' => 9182]);
```

After this call the `logs` table contains:

| level | message | date |
| --- | --- | --- |
| `ERROR` | `Order 9182 could not be charged` | `2026-05-24 14:08:22` |

## Reading the table name back

`getTable()` returns the validated, configured table name — useful for
diagnostics:

```php
$logger->getTable();   // "logs"
```
