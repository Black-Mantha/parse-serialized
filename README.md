# parseSerialized.php

Parses a php-serialized string to a human-readable format.

## Usage

```
parseSerialized.php <input file>
```

Analyses a PHP session file or other serialized string and prints it in yaml format with comments so
that it's human readable.

If you give `-` as the input file, it will read from standard input.
