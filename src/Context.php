<?php

namespace perfectwebteam\anonymize;

/**
 * What a closure strategy gets: the real value, the row it is in, and the
 * seed the other strategies of that row use. Returns the value to write; the
 * real one leaves it as it is.
 */
final class Context
{
    /**
     * @param array<string, mixed> $row a table's row as read (column => value), or an element's content by field handle
     */
    public function __construct(
        public readonly mixed $value,
        public readonly array $row,
        public readonly string $seed,
        public readonly int|string $id,
        public readonly string $name,
    ) {
    }

    /** A value from Fake for this row: `$c->fake('fullName')`, `$c->fake('city', 'adres')`. */
    public function fake(string $generator, ?string $group = null, ?int $arg = null): mixed
    {
        return Fake::make($generator, "{$this->seed}:" . ($group ?? $this->name), $arg, $this->value);
    }
}
