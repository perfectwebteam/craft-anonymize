<?php

namespace perfectwebteam\anonymize;

use Closure;
use yii\base\InvalidConfigException;

/**
 * A project's config/anonymize.php with the presets it asks for underneath:
 * `craft` always, then those in `presets`, then the project's own file, each
 * over the one before.
 *
 * Lists (keep.*, presets) are joined; rules are replaced as a whole, so a
 * project that sets `tables.users.columns.email` replaces that one strategy
 * and keeps the preset's others. A key this does not know is an error rather
 * than a rule that quietly does nothing.
 */
final class Config
{
    public const PRESETS = __DIR__ . '/../presets';

    private const TOP = ['presets', 'strict', 'tableCollations', 'handlers', 'scan', 'placeholders', 'keep', 'fields', 'fieldTypes', 'elements', 'tables'];
    private const SCAN = ['allowEmails', 'allowDomains', 'fail'];
    private const PLACEHOLDERS = ['environments'];

    /** Where `anonymize/placeholders` may write, unless the config says otherwise: never production. */
    private const PLACEHOLDER_ENVIRONMENTS = ['local', 'dev', 'development', 'test', 'staging'];
    private const KEEP = ['users', 'elements', 'tables'];
    private const KEEP_USERS = ['emailDomains', 'emails', 'usernames', 'groups', 'ids'];
    private const ELEMENT_RULE = ['title', 'fields', 'seed', 'filename', 'alt'];
    private const TABLE_RULE = ['columns', 'keepRows', 'dropRows'];

    /** Joined rather than replaced. */
    private const LISTS = ['presets', 'handlers', 'scan.allowEmails', 'scan.allowDomains', 'keep.users.emailDomains', 'keep.users.emails', 'keep.users.usernames', 'keep.users.groups', 'keep.users.ids', 'keep.elements', 'keep.tables'];

    /** Merged key by key; anything below them is replaced whole. */
    private const MAPS = ['scan', 'placeholders', 'keep', 'keep.users', 'fields', 'fieldTypes', 'elements', 'elements.*', 'elements.*.fields', 'tables', 'tables.*', 'tables.*.columns'];

    /** @var array<string, mixed> */
    public array $data = [];

    /**
     * @var array<string, true> what only a preset sets: `tables.cache`,
     * `elements.address`, `keep.tables.categories`. A project may not have
     * those tables or layouts, and that is no error.
     */
    private array $presetOnly = [];

    private function __construct(public readonly string $path)
    {
    }

    /**
     * @param string $env the environment, for a file split up per environment as Craft's own are (`'*' => […]`)
     * @throws InvalidConfigException
     */
    public static function load(string $path, string $env): self
    {
        if (!is_file($path)) {
            throw new InvalidConfigException("Geen configbestand op {$path}");
        }
        $config = new self($path);
        $own = self::read($path, 'config');
        if (isset($own['*'])) {
            $own = self::merge($own['*'], is_array($own[$env] ?? null) ? $own[$env] : []);
        }
        self::validate($own, 'config');

        $presets = array_values(array_unique(array_merge(['craft'], $own['presets'] ?? [])));
        foreach ($presets as $name) {
            if (!is_string($name) || !preg_match('/^[a-z0-9-]+$/', $name) || !is_file(self::PRESETS . "/{$name}.php")) {
                throw new InvalidConfigException("presets: '{$name}' bestaat niet");
            }
            $preset = self::read(self::PRESETS . "/{$name}.php", "preset {$name}");
            self::validate($preset, "preset {$name}");
            $config->data = self::merge($config->data, $preset);
        }
        foreach (['tables', 'elements'] as $part) {
            foreach (array_keys($config->data[$part] ?? []) as $key) {
                $config->presetOnly["{$part}.{$key}"] = true;
            }
        }
        foreach (['tables', 'elements'] as $part) {
            foreach ($config->data['keep'][$part] ?? [] as $key) {
                $config->presetOnly["keep.{$part}.{$key}"] = true;
            }
        }

        foreach (['tables', 'elements'] as $part) {
            foreach (array_keys($own[$part] ?? []) as $key) {
                unset($config->presetOnly["{$part}.{$key}"]);
            }
            foreach ($own['keep'][$part] ?? [] as $key) {
                unset($config->presetOnly["keep.{$part}.{$key}"]);
            }
        }
        $config->data = self::merge($config->data, $own);
        $config->data['presets'] = $presets;

        return $config;
    }

    public function strict(): bool
    {
        return $this->data['strict'] ?? true;
    }

    /** Whether the dump keeps each table's default collation (SqlDumpWriter::$tableCollations); by default not. */
    public function tableCollations(): bool
    {
        return $this->data['tableCollations'] ?? false;
    }

    /** @return list<string> the environments `anonymize/placeholders` may write files in */
    public function placeholderEnvironments(): array
    {
        return array_map('strval', $this->data['placeholders']['environments'] ?? self::PLACEHOLDER_ENVIRONMENTS);
    }

    /** Whether only a preset sets $path (`tables.cache`), so it may target what this project lacks. */
    public function isPresetOnly(string $path): bool
    {
        return isset($this->presetOnly[$path]);
    }

    /**
     * @return array<string, mixed>
     * @throws InvalidConfigException
     */
    private static function read(string $path, string $what): array
    {
        $data = (static fn() => require $path)();
        if (!is_array($data)) {
            throw new InvalidConfigException("{$what} ({$path}) geeft geen array terug");
        }

        return $data;
    }

    /**
     * @throws InvalidConfigException
     */
    private static function validate(array $data, string $what): void
    {
        self::keys($data, self::TOP, $what);
        foreach (['strict', 'tableCollations'] as $flag) {
            if (isset($data[$flag]) && !is_bool($data[$flag])) {
                throw new InvalidConfigException("{$what}: {$flag} is true of false");
            }
        }
        self::list($data['presets'] ?? [], "{$what}: presets");
        self::list($data['handlers'] ?? [], "{$what}: handlers");
        self::map($data['scan'] ?? [], "{$what}: scan");
        self::keys($data['scan'] ?? [], self::SCAN, "{$what}: scan");
        self::list($data['scan']['allowEmails'] ?? [], "{$what}: scan.allowEmails");
        self::list($data['scan']['allowDomains'] ?? [], "{$what}: scan.allowDomains");
        if (isset($data['scan']['fail']) && !is_bool($data['scan']['fail'])) {
            throw new InvalidConfigException("{$what}: scan.fail is true of false");
        }
        self::map($data['placeholders'] ?? [], "{$what}: placeholders");
        self::keys($data['placeholders'] ?? [], self::PLACEHOLDERS, "{$what}: placeholders");
        self::list($data['placeholders']['environments'] ?? [], "{$what}: placeholders.environments");
        foreach ($data['placeholders']['environments'] ?? [] as $environment) {
            if (in_array(strtolower((string)$environment), ['production', 'prod', 'live'], true)) {
                throw new InvalidConfigException("{$what}: placeholders.environments: nooit op {$environment}");
            }
        }

        $keep = $data['keep'] ?? [];
        self::map($keep, "{$what}: keep");
        self::keys($keep, self::KEEP, "{$what}: keep");
        self::map($keep['users'] ?? [], "{$what}: keep.users");
        self::keys($keep['users'] ?? [], self::KEEP_USERS, "{$what}: keep.users");
        foreach (self::KEEP_USERS as $key) {
            self::list($keep['users'][$key] ?? [], "{$what}: keep.users.{$key}");
        }
        self::list($keep['elements'] ?? [], "{$what}: keep.elements");
        self::list($keep['tables'] ?? [], "{$what}: keep.tables");

        self::map($data['fields'] ?? [], "{$what}: fields");
        self::map($data['fieldTypes'] ?? [], "{$what}: fieldTypes");

        self::map($data['elements'] ?? [], "{$what}: elements");
        foreach ($data['elements'] ?? [] as $selector => $rule) {
            self::map($rule, "{$what}: elements.{$selector}");
            self::keys($rule, self::ELEMENT_RULE, "{$what}: elements.{$selector}");
            self::map($rule['fields'] ?? [], "{$what}: elements.{$selector}.fields");
            if (isset($rule['seed']) && !is_string($rule['seed'])) {
                throw new InvalidConfigException("{$what}: elements.{$selector}.seed is de handle van een relatieveld");
            }
        }

        self::map($data['tables'] ?? [], "{$what}: tables");
        foreach ($data['tables'] ?? [] as $table => $rule) {
            if ($rule === 'empty') {
                continue;
            }
            if (!is_array($rule)) {
                throw new InvalidConfigException("{$what}: tables.{$table} is 'empty' of een array met columns, keepRows en dropRows");
            }
            self::keys($rule, self::TABLE_RULE, "{$what}: tables.{$table}");
            self::map($rule['columns'] ?? [], "{$what}: tables.{$table}.columns");
            if (isset($rule['keepRows']) && !is_array($rule['keepRows']) && !$rule['keepRows'] instanceof Closure) {
                throw new InvalidConfigException("{$what}: tables.{$table}.keepRows is kolom => 'keptUsers', 'keptElements' of een lijst waarden, of een closure");
            }
            if (isset($rule['dropRows']) && !is_bool($rule['dropRows']) && !$rule['dropRows'] instanceof Closure) {
                throw new InvalidConfigException("{$what}: tables.{$table}.dropRows is true, false of een closure");
            }
        }
    }

    private static function keys(array $data, array $allowed, string $where): void
    {
        $unknown = array_diff(array_keys($data), $allowed);
        if ($unknown) {
            throw new InvalidConfigException("{$where}: onbekende sleutel " . implode(', ', $unknown) . ' (verwacht: ' . implode(', ', $allowed) . ')');
        }
    }

    private static function map(mixed $value, string $where): void
    {
        if (!is_array($value) || ($value !== [] && array_is_list($value))) {
            throw new InvalidConfigException("{$where} is een array met sleutels");
        }
    }

    private static function list(mixed $value, string $where): void
    {
        if (!is_array($value) || !array_is_list($value)) {
            throw new InvalidConfigException("{$where} is een lijst");
        }
        foreach ($value as $item) {
            if (!is_string($item) && !is_int($item)) {
                throw new InvalidConfigException("{$where} bevat iets anders dan tekst of een getal");
            }
        }
    }

    private static function merge(array $base, array $over, string $path = ''): array
    {
        foreach ($over as $key => $value) {
            $here = $path === '' ? (string)$key : "{$path}.{$key}";
            $pattern = preg_replace('/^(elements|tables)\.[^.]+/', '$1.*', $here);
            if (is_array($value) && is_array($base[$key] ?? null)) {
                if (in_array($pattern, self::LISTS, true)) {
                    $base[$key] = array_values(array_unique(array_merge($base[$key], $value)));
                    continue;
                }
                if (in_array($pattern, self::MAPS, true)) {
                    $base[$key] = self::merge($base[$key], $value, $here);
                    continue;
                }
            }
            $base[$key] = $value;
        }

        return $base;
    }
}
