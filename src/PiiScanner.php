<?php

namespace perfectwebteam\anonymize;

/**
 * Looks at what the dump writes, after the rules, for e-mail addresses that
 * should not be there: not a kept user's, not made up, not on a domain the
 * config allows (a client's own info@ on its pages). It counts them per
 * table and column and keeps a few, masked, so the report can be shared.
 *
 * A safety net, not a rule: it finds what no rule covers, such as a plugin's
 * log, a queue payload or an old table, wherever an address ended up.
 */
final class PiiScanner
{
    private const EMAIL = '/[A-Za-z0-9._%+\-]+@[A-Za-z0-9\-]+(?:\.[A-Za-z0-9\-]+)*\.[A-Za-z]{2,}/';

    /** @var array<string, int> "table.column" => addresses found */
    public array $hits = [];

    /** @var array<string, list<string>> "table.column" => a few, masked */
    public array $samples = [];

    /** @var array<string, true> */
    private array $emails;

    /** @var list<string> */
    private array $domains;

    /**
     * @param list<string> $emails addresses that may be there (the kept users')
     * @param list<string> $domains domains that may be there, subdomains included
     */
    public function __construct(array $emails, array $domains)
    {
        $this->emails = array_fill_keys(array_map('strtolower', $emails), true);
        $this->domains = array_map(fn($d) => strtolower(ltrim(trim($d), '@.')), array_merge([Fake::EMAIL_DOMAIN], $domains));
    }

    public function inspect(string $table, string $column, string $value): void
    {
        if (!preg_match_all(self::EMAIL, $value, $matches)) {
            return;
        }
        foreach ($matches[0] as $email) {
            $email = strtolower($email);
            if (isset($this->emails[$email]) || $this->allowedDomain(substr($email, strrpos($email, '@') + 1))) {
                continue;
            }
            $key = "{$table}.{$column}";
            $this->hits[$key] = ($this->hits[$key] ?? 0) + 1;
            $masked = self::mask($email);
            if (count($this->samples[$key] ?? []) < 3 && !in_array($masked, $this->samples[$key] ?? [], true)) {
                $this->samples[$key][] = $masked;
            }
        }
    }

    /** `j***@g***.com`: enough to recognise a kind of address, not whose it is. */
    public static function mask(string $email): string
    {
        [$local, $domain] = explode('@', $email, 2);
        $dot = strrpos($domain, '.');

        return mb_substr($local, 0, 1) . '***@' . mb_substr($domain, 0, 1) . '***' . ($dot !== false ? substr($domain, $dot) : '');
    }

    private function allowedDomain(string $domain): bool
    {
        foreach ($this->domains as $allowed) {
            if ($domain === $allowed || str_ends_with($domain, ".{$allowed}")) {
                return true;
            }
        }

        return false;
    }
}
