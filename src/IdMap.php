<?php

namespace perfectwebteam\anonymize;

use RuntimeException;

/**
 * A small number per element id, in one string: one or two bytes an id, so
 * what the dump learns about millions of elements costs megabytes, not the
 * hundreds a PHP array would. Made as long as the highest id at once: growing
 * it would hold the old and the new string together for a moment.
 */
final class IdMap
{
    private string $bytes;

    public function __construct(private readonly int $width = 1, int $highestId = 0)
    {
        $this->bytes = str_repeat("\0", ($highestId + 1) * $width);
    }

    public function set(int $id, int $value): void
    {
        if ($value > ($this->width === 1 ? 0xff : 0xffff)) {
            throw new RuntimeException("{$value} past niet in {$this->width} byte(s)");
        }
        $offset = $id * $this->width;
        $length = strlen($this->bytes);
        if ($offset + $this->width > $length) {
            // An id past the highest one the snapshot gave: only by mistake, so no need to be clever.
            $this->bytes .= str_repeat("\0", $offset + $this->width - $length);
        }
        if ($this->width === 1) {
            $this->bytes[$offset] = chr($value);
        } else {
            $this->bytes[$offset] = chr($value >> 8);
            $this->bytes[$offset + 1] = chr($value & 0xff);
        }
    }

    public function get(int $id): int
    {
        $offset = $id * $this->width;
        if ($offset + $this->width > strlen($this->bytes)) {
            return 0;
        }

        return $this->width === 1
            ? ord($this->bytes[$offset])
            : (ord($this->bytes[$offset]) << 8) | ord($this->bytes[$offset + 1]);
    }
}
