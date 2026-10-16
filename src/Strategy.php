<?php

namespace perfectwebteam\anonymize;

use Closure;
use yii\base\InvalidConfigException;

/**
 * What happens to one value, as the config writes it:
 *
 * - `'keep'`: stays as it is;
 * - `'null'`: emptied (NULL, or the key out of the content, as a save does);
 * - `'fullName'`, `'digits:8'`, `'city@adres'`, `'city@adres|upper'`: a value
 *   from Fake. `@group` shares the seed: `firstName@owner` and
 *   `surname@owner` are the same person;
 * - `['value' => …]`: this value;
 * - `['format' => 'SPAR {surname@owner}']`: generators in a text, and `{id}`;
 * - `['pseudonym' => 'Groep']`: "Groep 1", "Groep 2", … one per real value;
 * - `['placeholder' => 'document']`: an asset's filename or title,
 *   `document-{id}.{ext}`;
 * - a closure, `fn(Context $c) => …`: anything else.
 *
 * Every kind but `null` and a closure only replaces a filled value: an empty
 * one stays empty, so the data keeps its shape.
 */
final class Strategy
{
    public const KEEP = 'keep';
    public const NULL = 'null';
    public const FAKE = 'fake';
    public const VALUE = 'value';
    public const FORMAT = 'format';
    public const PSEUDONYM = 'pseudonym';
    public const PLACEHOLDER = 'placeholder';
    public const CLOSURE = 'closure';

    /** generator, number, group, modifier */
    private const SPEC = '([a-zA-Z]+)(?::(\d+))?(?:@([\w-]+))?(?:\|(upper|lower))?';

    private function __construct(
        public readonly string $kind,
        public readonly string $where,
        public readonly ?string $generator = null,
        public readonly ?int $arg = null,
        public readonly ?string $group = null,
        public readonly ?string $modifier = null,
        public readonly mixed $value = null,
        public readonly ?Closure $closure = null,
    ) {
    }

    public static function keep(string $where = ''): self
    {
        return new self(self::KEEP, $where);
    }

    /**
     * @throws InvalidConfigException when $spec is none of the above; $where says where it was
     */
    public static function parse(mixed $spec, string $where): self
    {
        if ($spec instanceof Closure) {
            return new self(self::CLOSURE, $where, closure: $spec);
        }
        if ($spec === self::KEEP || $spec === self::NULL) {
            return new self($spec, $where);
        }
        if (is_string($spec)) {
            [$generator, $arg, $group, $modifier] = self::generator($spec, $where);

            return new self(self::FAKE, $where, $generator, $arg, $group, $modifier);
        }
        if (is_array($spec) && count($spec) === 1) {
            $kind = array_key_first($spec);
            $value = $spec[$kind];
            switch ($kind) {
                case self::VALUE:
                    return new self(self::VALUE, $where, value: $value);
                case self::FORMAT:
                    if (!is_string($value)) {
                        break;
                    }
                    preg_match_all('/\{([^}]*)\}/', $value, $tokens);
                    foreach ($tokens[1] as $token) {
                        if ($token !== 'id') {
                            self::generator($token, "{$where} ({$value})");
                        }
                    }

                    return new self(self::FORMAT, $where, value: $value);
                case self::PSEUDONYM:
                case self::PLACEHOLDER:
                    if (is_string($value) && $value !== '') {
                        return new self($kind, $where, value: $value);
                    }
                    break;
            }
        }

        throw new InvalidConfigException("{$where}: " . self::describe($spec) . " is geen strategie (keep, null, een generator zoals 'fullName', ['value' => …], ['format' => …], ['pseudonym' => …], ['placeholder' => …] of een closure)");
    }

    /**
     * @return array{0: string, 1: ?int, 2: ?string, 3: ?string} generator, number, group, modifier
     * @throws InvalidConfigException
     */
    public static function generator(string $spec, string $where): array
    {
        if (!preg_match('/^' . self::SPEC . '$/', $spec, $m)) {
            throw new InvalidConfigException("{$where}: '{$spec}' is geen strategie");
        }
        $generator = $m[1];
        if (!array_key_exists($generator, Fake::GENERATORS)) {
            throw new InvalidConfigException("{$where}: '{$generator}' bestaat niet; kies uit " . implode(', ', array_keys(Fake::GENERATORS)));
        }
        $arg = ($m[2] ?? '') !== '' ? (int)$m[2] : null;
        if (Fake::GENERATORS[$generator] && $arg === null) {
            throw new InvalidConfigException("{$where}: '{$generator}' wil een aantal, bijvoorbeeld '{$generator}:8'");
        }

        return [$generator, $arg, ($m[3] ?? '') !== '' ? $m[3] : null, ($m[4] ?? '') !== '' ? $m[4] : null];
    }

    public function isKeep(): bool
    {
        return $this->kind === self::KEEP;
    }

    /** How check shows it. */
    public function label(): string
    {
        return match ($this->kind) {
            self::FAKE => $this->generator . ($this->arg !== null ? ":{$this->arg}" : '') . ($this->group !== null ? "@{$this->group}" : '') . ($this->modifier !== null ? "|{$this->modifier}" : ''),
            self::VALUE => 'vaste waarde',
            self::FORMAT => "'{$this->value}'",
            self::PSEUDONYM => "{$this->value} N",
            self::PLACEHOLDER => "{$this->value}-{id}",
            self::CLOSURE => 'closure',
            default => $this->kind,
        };
    }

    private static function describe(mixed $spec): string
    {
        return is_scalar($spec) ? var_export($spec, true) : get_debug_type($spec);
    }
}
