#!/usr/bin/env php
<?php

if (count($argv) !== 2) {
  fwrite(STDERR, <<<EXPLAIN
Usage: parseSerialized.php <input file>

Analyses a PHP session file or other serialized string and prints it in yaml format with comments so
that it's human readable.

If you give - as the input file, it will read from standard input.

EXPLAIN
  );
  exit(1);
}

$path = $argv[1];

if ($path === '-') {

  $obj = new SerializedStreamParser(STDIN, STDOUT);
  $obj->parse();

} else {

  $fIn = fopen($path, 'r');
  if ($fIn === false) {
    fwrite(STDERR, 'could not open ' . $path);
    exit(1);
  }
  $obj = new SerializedStreamParser($fIn, STDOUT);
  $obj->parse();
  fclose($fIn);

}


class SerializedStreamParser {
  protected $in, $out;
  protected $iByte = 0;
  protected $depth = 0;
  protected $references = [[-1, '<placeholder>']];
  protected $iLine = 1;
  protected $lineStartByte = 0;
  protected $writeStartByte = true;
  protected $comments = [];

  const COMMENT_LINE = 0;
  const COMMENT_VISIBILITY = 1;
  const COMMENT_CLASS = 2;
  const COMMENT_BYTE = 3;
  const COMMENT_REFERENCE = 4;

  public function __construct($in, $out)
  {
    $this->in = $in;
    $this->out = $out;
  }

  public function parse()
  {
    $this->parseValue();
    $this->writeNewline();
  }

  protected function read()
  {
    $result = fread($this->in, 1);
    if ($result === false) {
      throw new Exception('read failure');
    }
    $this->iByte++;
    return $result;
  }

  protected function readAssert($expected)
  {
    $value = $this->read();
    if ($value != $expected) {
      if ($value === '') {
        throw new Exception('Unexpected end of input');
      } else {
        $this->throwUnexpected($value, $this->export($expected));
      }
    }
  }

  protected function readBytes(int $n)
  {
    if ($n === 0) {
      return '';
    }
    $result = fread($this->in, $n);
    if ($result === false) {
      throw new Exception('read failure');
    }
    if (strlen($result) < $n) {
      throw new Exception('Unexpected end of input');
    }
    $this->iByte += $n;
    return $result;
  }

  protected function throwUnexpected($actual, $expectedStr)
  {
    throw new Exception(
      'Encountered ' . $this->export($actual)
      . ', expected ' . $expectedStr
      . ' at byte ' . $this->iByte
    );
  }

  protected function addReference($reference)
  {
//    $this->comments[self::COMMENT_REFERENCE] = 'reference #'.count($this->references);
    $this->references[] = [$this->iLine, $reference];
  }

  protected function parseValue($reference = '#')
  {
    $type = $this->read();
    switch ($type) {
      case 'N':
        $this->addReference($reference);
        $this->readAssert(';');
        $value = 'NULL';
        break;
      case 'b':
        $this->addReference($reference);
        $this->readAssert(':');
        $value = $this->read();
        switch ($value) {
          case '0':
            $value = 'false';
            break;
          case '1':
            $value = 'true';
            break;
          default:
            $this->throwUnexpected($value, '"0" or "1"');
        }
        $this->readAssert(';');
        break;
      case 'i':
        $this->addReference($reference);
        $this->readAssert(':');
        $value = strval($this->readNumber());
        break;
      case 'd':
        $this->addReference($reference);
        $this->readAssert(':');
        $value = strval($this->readNumber(true));
        break;
      case 's':
        $this->addReference($reference);
        $this->readAssert(':');
        $value = $this->export($this->readString());
        $this->readAssert(';');
        break;
      case 'a':
        $this->addReference($reference);
        $this->readAssert(':');
        if ($this->depth === 0) {
          fwrite($this->out, 'array:');
        }
        $this->parseArray($reference);
        $value = '';
        break;
      case 'O':
        $this->addReference($reference);
        $this->readAssert(':');
        if ($this->depth === 0) {
          fwrite($this->out, 'object:');
        }
        $class = $this->readString();
        $this->comments[self::COMMENT_CLASS] = $class;
        $this->readAssert(':');
        $this->parseArray($reference, $class);
        $value = '';
        break;
      case 'C': // objects with a custom serializer can't be garanteed to be parseable; we just hope the serialized
                // string is the result of a single serialize() call.
        $this->addReference($reference);
        $this->readAssert(':');
        $class = $this->readString();
        $this->comments[self::COMMENT_CLASS] = $class;
        $this->readAssert(':');
        $nLength = $this->readNumber();
        $this->readAssert('{');
//        $value = var_export($this->readBytes($nLength), true);
        if ($nLength > 0) {
          $stopAt = $this->iByte + $nLength;
          $this->parseValue($reference); // hopen dat het klopt...
          if ($this->iByte !== $stopAt) {
            throw new Exception('Serializable object was expected to end at '.$stopAt.', stopped at '.$this->iByte);
          }
        }
        $this->readAssert('}');
        $value = '';
        break;
      case 'r':
        $this->addReference($reference);
        // fallthrough
      case 'R':
        $this->readAssert(':');
        $iRef = $this->readNumber();
        if ($iRef <= 0 || $iRef >= count($this->references)) {
          $this->throwUnexpected($iRef, 'legal reference number (1 t/m '.(count($this->references)-1).')');
        }
        $ref = $this->references[$iRef];
        $this->depth++;
        $this->writeNewline();
        $value = '$ref: ' . $this->export($ref[1]);
        $this->comments[self::COMMENT_LINE] = sprintf('line %d', $ref[0]);
        $this->depth--;
        break;
      default:
        $this->throwUnexpected($type, 'a,b,C,d,i,N,O,R,r or s');
        return; // unreachable
    }

    fwrite($this->out, $value);
  }

  protected function parseArray($baseRef, ?string $propertiesOf = null)
  {
    $nItems = $this->readNumber();
    $this->readAssert('{');

    if ($nItems > 0) {
      $this->depth++;
      for ($i = 0; $i < $nItems; $i++) {
        $this->writeNewline();
        $this->parseValuePair($baseRef, $propertiesOf);
      }
      $this->depth--;
    } else {
      fwrite($this->out, '[]');
    }

    $this->readAssert('}');
  }

  protected function parseValuePair(string $reference, ?string $propertiesOf)
  {
    // Key
    $type = $this->read();
    switch ($type) {
      case 'i':
        $this->readAssert(':');
        $key = strval($this->readNumber());
        $reference .= '/'.$key;
        break;
      case 's':
        $this->readAssert(':');
        $key = $this->readString();
        if ($propertiesOf && $key[0] === "\0") {
          $iNul = strpos($key, "\0", 1);
          if (!$iNul) {
            $this->throwUnexpected(substr($key, 1), 'string containing another \0');
          }
          $class = substr($key, 1, $iNul-1);
          $key = substr($key, $iNul+1);
          if ($class !== '*' && $class == $propertiesOf) {
            // private member of a parent class
            $this->comments[self::COMMENT_VISIBILITY] = 'private of '.$class;
          }
        }
        $reference .= '/'.$key;
        $this->readAssert(';');
        break;
      default:
        $this->throwUnexpected($type, 'i or s');
        return; // unreachable
    }
    fwrite($this->out, $this->export($key). ': ');

    // Value
    $this->parseValue($reference);
  }

  protected function readNumber($fraction = false)
  {
    $negative = false;
    $char = $this->read();
    if ($char === '-') {
      $negative = true;
      $value = 0;
    } elseif ($char >= '0' && $char <= '9') {
      $value = intval($char);
    } else {
      $this->throwUnexpected($char, 'a number or "-"');
      return 0; // unreachable
    }
    while (true) {
      $char = $this->read();
      if ($char >= '0' && $char <= '9') {
        $value = 10*$value + intval($char);
      } elseif ($char === ';' || $char === ':') {
        return $negative ? -$value : $value;
      } elseif ($fraction && $char === '.') {
        break;
      } else {
        $this->throwUnexpected($char, 'a number, ":" or ";"');
      }
    }
    $multiplier = 0.1;
    while (true) {
      $char = $this->read();
      if ($char >= '0' && $char <= '9') {
        $value = $value + $multiplier*intval($char);
      } elseif ($char === ';' || $char === ':') {
        return $negative ? -$value : $value;
      } else {
        $this->throwUnexpected($char, 'a number or ";"');
      }
      $multiplier = $multiplier/10;
    }
  }

  protected function readString()
  {
    $nBytes = $this->readNumber();
    $this->readAssert('"');
    $result = $this->readBytes($nBytes);
    $this->readAssert('"');
    return $result;
  }

  protected function writeNewline()
  {
    // comment and/or byte number
    if ($this->writeStartByte) {
      $this->comments[self::COMMENT_BYTE] = strval($this->lineStartByte);
    }
    if (count($this->comments)) {
      ksort($this->comments);
      fwrite($this->out, '  # ' . implode(' - ', $this->comments));
    }
    $this->comments = [];

    fwrite($this->out, PHP_EOL . str_repeat('  ', $this->depth));
    $this->iLine++;
    $this->writeStartByte = $this->lineStartByte !== $this->iByte;
    $this->lineStartByte = $this->iByte;

  }

  /**
   * As var_export, but make sure there are no newlines in the result
   * @param mixed $value
   * @return string
   */
  protected function export($value)
  {
    if (is_string($value)) {
      if (preg_match('/[\x00-\x1F\x7F]/', $value)) {
        return json_encode($value);
      } else {
        return var_export($value, true);
      }
    } else {
      return str_replace(["\n", "\r"], [' ', ' '], var_export($value, true));
    }
  }
}
