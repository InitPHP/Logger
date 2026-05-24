# Context Placeholders and `{exception}`

PSR-3 §1.2 specifies a uniform placeholder syntax for log messages:
**`{name}`**, where `name` is the key of an entry in the `$context` array.
This page documents how the built-in handlers expand each kind of value.

## Placeholder syntax

```php
$logger->info('User {user} hit a {kind} error', [
    'user' => 'jane',
    'kind' => 'transient',
]);
// → "User jane hit a transient error"
```

- Keys must be strings. Integer keys are ignored (they cannot appear as
  placeholders in the message anyway).
- Curly braces inside the message that do not correspond to a context key are
  left **untouched**. This is intentional — it means you can include literal
  `{` and `}` in your messages without escaping, as long as they do not happen
  to spell a key you also passed.

## How context values are rendered

| Value type | Rendered as |
| --- | --- |
| `null` | empty string `""` |
| `true` | `"true"` |
| `false` | `"false"` |
| `int`, `float` | `(string) $value` |
| `string` | the value verbatim |
| `\Stringable` (incl. classes with `__toString()`) | `(string) $value` |
| `\Throwable` | `"<Class>(<code>): <message> in <file>:<line>"` |
| arrays | placeholder **left untouched** |
| non-stringable objects | placeholder **left untouched** |

Examples:

```php
$logger->info('a={a} b={b} c={c} d={d}', [
    'a' => null,
    'b' => true,
    'c' => false,
    'd' => 3.14,
]);
// → "a= b=true c=false d=3.14"
```

```php
try {
    throw new RuntimeException('disk full', 42);
} catch (RuntimeException $e) {
    $logger->error('write failed: {exception}', ['exception' => $e]);
}
// → "write failed: RuntimeException(42): disk full in /app/src/Writer.php:88"
```

Arrays and arbitrary objects are deliberately skipped rather than coerced to
`"Array"` or `"Object"`. If you want to log structured data, format it
yourself:

```php
$logger->info('payload: {body}', ['body' => json_encode($data)]);
```

## The `{exception}` convention

PSR-3 §1.3 says:

> Implementors MUST still ensure that the `exception` key is not interfered
> with should they get one from the user.

The built-in handlers honour this. The `exception` key is **not** treated as
magic at the API surface: it follows the same `{exception}` placeholder rule as
every other key. The only special-casing is that **any** `\Throwable` in the
context — under any key — renders as
`"<Class>(<code>): <message> in <file>:<line>"`.

If you also want the stack trace, format it yourself:

```php
$logger->error(
    "write failed: {exception}\n{trace}",
    [
        'exception' => $e,
        'trace'     => $e->getTraceAsString(),
    ]
);
```

## Stringable messages

The message itself may be a `\Stringable` rather than a plain string:

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
```

`{}` placeholders work on the **rendered** string, so combining `Stringable`
messages with context placeholders is fully supported:

```php
$logger->warning(
    new FormattedMessage('user %d (%s)', [7, 'jane']),
    ['extra' => 'context unused — no placeholder']
);
```
