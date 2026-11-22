# Context Interpolation

PSR-3 §1.2 specifies a placeholder syntax for log messages: `{name}`, where
`name` is the key of an entry in the `$context` array. Every handler in this
package implements that syntax through the package-internal
`InitPHP\Logger\HelperTrait::interpolate()` method.

This page documents how each kind of value is rendered.

## Placeholder syntax

```php
$logger->info('User {user} hit a {kind} error', [
    'user' => 'jane',
    'kind' => 'transient',
]);
// → "User jane hit a transient error"
```

- Placeholder names must be **string** keys in the context array. Integer
  keys are skipped (they cannot appear as placeholders in the message anyway).
- Curly braces in the message that do not correspond to a context key are
  left **untouched**. That is intentional — it means you can include literal
  `{` and `}` in your messages without escaping, as long as they do not
  happen to spell a key you also passed.
- Replacement uses PHP's `strtr()`. It performs a single, simultaneous pass:
  one placeholder's expansion will not introduce another placeholder.

## How context values are rendered

For each context entry, the value is normalised to a string according to the
following table:

| Value type | Rendered as |
| --- | --- |
| `null` | empty string `""` |
| `true` | `"true"` |
| `false` | `"false"` |
| `int`, `float` | `(string) $value` |
| `string` | the value verbatim |
| `\Stringable` (including classes that define `__toString()`) | `(string) $value` |
| `\Throwable` | `"<Class>(<code>): <message> in <file>:<line>"` |
| arrays | placeholder is **left untouched** |
| non-stringable objects | placeholder is **left untouched** |

### Scalars and `null`

```php
$logger->info('a={a} b={b} c={c} d={d} e={e}', [
    'a' => null,
    'b' => true,
    'c' => false,
    'd' => 42,
    'e' => 3.14,
]);
// → "a= b=true c=false d=42 e=3.14"
```

`null` renders as the empty string rather than the literal `"null"` so the
common case `"user {ip}"` with `'ip' => null` produces `"user "` instead of
`"user null"`.

### Stringable values

Anything that implements `__toString()` (PHP automatically treats it as
`\Stringable`) is cast to string:

```php
$value = new class implements \Stringable {
    public function __toString(): string { return 'STR'; }
};

$logger->info('value={v}', ['v' => $value]);
// → "value=STR"
```

### `\Throwable` values

A `\Throwable` value renders as a single line that includes class, code,
message, file and line:

```php
try {
    throw new RuntimeException('disk full', 42);
} catch (RuntimeException $e) {
    $logger->error('write failed: {exception}', ['exception' => $e]);
}
// → "write failed: RuntimeException(42): disk full in /app/src/Writer.php:88"
```

This is deliberately compact — one line per log record keeps file logs
greppable and database log rows analytics-friendly. The full stack trace is
**not** included by default; pass it through a second placeholder if you
need it:

```php
$logger->error("write failed: {exception}\n{trace}", [
    'exception' => $e,
    'trace'     => $e->getTraceAsString(),
]);
```

### Arrays and arbitrary objects

Arrays and non-stringable objects are **not** rendered — their placeholders
remain as literal text in the message. This is by design: coercing them
("Array", "Object") would discard information; serialising them
(`json_encode`, `var_export`) would be expensive and surprising. If you want
to log structured data, format it explicitly:

```php
$logger->info('payload: {body}', ['body' => json_encode($data)]);
```

## Stringable messages

PSR-3 v3 accepts `string|\Stringable` for the message itself. The package
handles both transparently. Placeholders are applied on the **rendered**
string:

```php
final class FormattedMessage implements \Stringable
{
    public function __construct(private string $template, private array $args) {}

    public function __toString(): string
    {
        return vsprintf($this->template, $this->args);
    }
}

$logger->info(new FormattedMessage('took %d ms (%s)', [42, 'cache hit']));
// → "took 42 ms (cache hit)"

// Combining a Stringable message with PSR-3 placeholders:
$logger->warning(
    new FormattedMessage('user %d (%s)', [7, 'jane']),
    ['extra' => 'unused — no {extra} placeholder in the message']
);
// → "user 7 (jane)"
```

## The `'exception'` key

PSR-3 §1.3 requires implementations to honour the convention that a
`\Throwable` value placed under `context['exception']` should be available
for inspection by the handler. The package does so by rendering **any**
`\Throwable` value — regardless of which key it is under — as the single-line
representation shown above. The `'exception'` key receives no extra
special-casing.

```php
$logger->error('boom: {exception}',     ['exception' => $e]);    // works
$logger->error('boom: {err}',           ['err'       => $e]);    // also works
$logger->error('boom',                  ['exception' => $e]);    // value kept, but
                                                                 // no placeholder → nothing to interpolate
```

## Order of evaluation

The placeholder map is built **before** `strtr()` runs, so:

1. Iterate the context array;
2. For each `(key, value)`:
   - skip non-string keys;
   - skip values that render as `null` (arrays, non-stringable objects);
   - otherwise add `'{key}' => $rendered` to the replacement map.
3. Call `strtr($message, $replace)`.

A consequence: two placeholders that produce identical rendered values are
no problem — `strtr()` is deterministic, and `(string) $v` is pure.

## Performance notes

- Interpolation is **O(N + M)** where N is the message length and M is the
  number of context entries.
- If the context array is empty, the message is returned unchanged without
  even allocating the replacement map.
- If none of the context entries render to a string (e.g. all arrays), the
  message is still returned unchanged.

For very hot paths, prefer messages with few placeholders and small contexts.
For truly cold paths (debugging), the cost is negligible compared to the
file or database I/O of the actual handler.

## Related

- [PSR-3 Compliance](PSR-3-Compliance.md) — the full specification mapping.
- [FileLogger](File-Logger.md) and [PDOLogger](PDO-Logger.md) — both use the same
  interpolation logic.
- [API Reference › HelperTrait](API-Reference.md#helpertrait) — the internal
  helper method, for reference.
