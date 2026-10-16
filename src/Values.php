<?php

namespace perfectwebteam\anonymize;

use LogicException;

/**
 * Makes the value a Strategy asks for. The seed of a value is that of its
 * element or row plus its group, or its own name without one, so a re-run
 * gives the same copy; tokens mix in a secret of this run, so they cannot be
 * made again outside it.
 */
final class Values
{
    /** @var array<string, array<string, string>> strategy => real value => pseudonym */
    private array $pseudonyms = [];

    private readonly string $secret;

    public function __construct()
    {
        $this->secret = bin2hex(random_bytes(16));
    }

    /**
     * @param string $seed the element's or the row's
     * @param string $name the field handle or column: the group of a value that names none
     * @param int|string $id what `{id}` stands for
     * @param array<string, mixed> $row for a closure (Context::$row)
     */
    public function make(Strategy $strategy, mixed $original, string $seed, string $name, int|string $id, array $row = []): mixed
    {
        return match ($strategy->kind) {
            Strategy::KEEP => $original,
            Strategy::NULL => null,
            Strategy::VALUE => $strategy->value,
            Strategy::FAKE => $this->fake($strategy->generator, $strategy->arg, $strategy->group, $strategy->modifier, $original, $seed, $name),
            Strategy::FORMAT => $this->format($strategy->value, $original, $seed, $name, $id),
            Strategy::PSEUDONYM => $this->pseudonym($strategy, $original),
            Strategy::CLOSURE => ($strategy->closure)(new Context($original, $row, $seed, $id, $name)),
            Strategy::PLACEHOLDER => throw new LogicException("{$strategy->where}: placeholder is alleen voor de bestandsnaam en titel van assets"),
        };
    }

    private function fake(string $generator, ?int $arg, ?string $group, ?string $modifier, mixed $original, string $seed, string $name): mixed
    {
        $value = Fake::make($generator, $seed . ':' . ($group ?? $name), $arg, $original, $this->secret);

        return match ($modifier) {
            'upper' => mb_strtoupper((string)$value),
            'lower' => mb_strtolower((string)$value),
            default => $value,
        };
    }

    private function format(string $format, mixed $original, string $seed, string $name, int|string $id): string
    {
        return preg_replace_callback('/\{([^}]*)\}/', function(array $m) use ($original, $seed, $name, $id): string {
            if ($m[1] === 'id') {
                return (string)$id;
            }
            [$generator, $arg, $group, $modifier] = Strategy::generator($m[1], $name);
            $value = $this->fake($generator, $arg, $group, $modifier, $original, $seed, $name);

            return $value instanceof \DateTimeInterface ? $value->format('Y-m-d') : (string)$value;
        }, $format) ?? $format;
    }

    /** One made-up name per real value, numbered in the order they come by; the same real value gets the same one. */
    private function pseudonym(Strategy $strategy, mixed $original): string
    {
        $real = trim((string)$original);
        $map = &$this->pseudonyms[$strategy->where];
        $map ??= [];

        return $map[$real] ??= $strategy->value . ' ' . (count($map) + 1);
    }
}
