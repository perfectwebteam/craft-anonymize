<?php

namespace perfectwebteam\anonymize\handlers;

use craft\db\Query;
use craft\helpers\Json;
use perfectwebteam\anonymize\Fake;
use perfectwebteam\anonymize\Handler;
use perfectwebteam\anonymize\Plan;
use RuntimeException;
use yii\db\Connection;

/**
 * What a Formie 3 submission holds (`formie_submissions.content`): JSON keyed
 * by the uid of each field, and nested the same way for groups, repeaters and
 * the parts of a name, address or date. The field types and the options of
 * the choice fields come from formie_fields, read once before the dump.
 *
 * - Names, e-mail addresses, addresses, phone numbers and dates get made-up
 *   values, one person and one address per submission.
 * - A choice stays when it is what such a field can hold: one of its options,
 *   an id, yes or no, AM or PM, a country code. Anything else in it, typed in
 *   or sent another way, is made up like free text.
 * - Signatures and password hashes go.
 * - Everything else, and every field this does not know, keeps its shape
 *   with its text made up: an e-mail address stays an e-mail address, digits
 *   stay digits, other text becomes lorem of the same length.
 *
 * The submissions of kept users stay as they are, and the IP address goes.
 */
final class FormieSubmissions implements Handler
{
    /** Hold one of their options. */
    private const OPTIONS = ['Dropdown', 'Radio', 'Checkboxes'];

    /** Hold ids of elements or assets. */
    private const IDS = ['Categories', 'Entries', 'Tags', 'Users', 'Products', 'Variants', 'Submissions', 'Forms', 'FileUpload'];

    private const REMOVE = ['Signature', 'Password'];

    private Plan $plan;

    /** @var array<string, string> field uid => type, without its namespace */
    private array $types = [];

    /** @var array<string, array<string, true>> field uid => the values of its options */
    private array $options = [];

    public function prepare(Plan $plan, Connection $db): void
    {
        $this->plan = $plan;
        foreach ((new Query())->select(['uid', 'type', 'settings'])->from('{{%formie_fields}}')->all($db) as $row) {
            $type = substr($row['type'], strrpos($row['type'], '\\') + 1);
            $this->types[$row['uid']] = $type;
            if (in_array($type, self::OPTIONS, true)) {
                $settings = Json::decodeIfJson((string)$row['settings']);
                foreach (is_array($settings) ? ($settings['options'] ?? []) : [] as $option) {
                    if (is_array($option) && isset($option['value']) && is_scalar($option['value'])) {
                        $this->options[$row['uid']][(string)$option['value']] = true;
                    }
                }
            }
        }
    }

    public function tables(): array
    {
        return ['formie_submissions' => ['id', 'content', 'userId', 'ipAddress']];
    }

    public function row(string $table, array $row): array
    {
        if ($row['userId'] !== null && isset($this->plan->keptUsers[(int)$row['userId']])) {
            return [];
        }
        $seed = "formie:{$row['id']}";
        $changes = [];
        if ($row['content'] !== null && $row['content'] !== '') {
            $content = Json::decodeIfJson($row['content']);
            // Written as a JSON string, the json column encodes it once more: read twice, write twice.
            $twice = is_string($content);
            if ($twice) {
                $content = Json::decodeIfJson($content);
            }
            if (!is_array($content)) {
                throw new RuntimeException("formie_submissions {$row['id']}: de content is geen JSON-object");
            }
            $new = $this->values($content, $seed);
            if ($new !== $content) {
                $changes['content'] = $twice ? Json::encode(Json::encode($new)) : Json::encode($new);
            }
        }
        if ($row['ipAddress'] !== null && $row['ipAddress'] !== '') {
            $changes['ipAddress'] = Fake::make('ip', $seed);
        }

        return $changes;
    }

    /**
     * Fields by uid, at any depth: a key that is a field's uid gets that
     * field's treatment, anything else is made up keeping its shape.
     */
    private function values(array $values, string $seed): array
    {
        foreach ($values as $key => $value) {
            $type = $this->types[$key] ?? null;
            $values[$key] = $type !== null ? $this->field($type, (string)$key, $value, $seed) : $this->scrub($value, "{$seed}:{$key}");
        }

        return $values;
    }

    private function field(string $type, string $uid, mixed $value, string $seed): mixed
    {
        if ($value === null || $value === '' || $value === []) {
            return $value;
        }
        if (in_array($type, self::REMOVE, true)) {
            return null;
        }
        $fits = match (true) {
            in_array($type, self::OPTIONS, true) => fn($v) => is_scalar($v) && isset($this->options[$uid][(string)$v]),
            in_array($type, self::IDS, true) => fn($v) => is_int($v) || (is_string($v) && ctype_digit($v)),
            $type === 'Agree' => fn($v) => is_bool($v) || in_array(strtolower((string)$v), ['0', '1', 'true', 'false', 'yes', 'no'], true),
            $type === 'DateAmPmDropdown', $type === 'DateAmPmNumber' => fn($v) => is_string($v) && in_array(strtoupper($v), ['AM', 'PM'], true),
            $type === 'AddressCountry' => fn($v) => is_string($v) && (bool)preg_match('/^[A-Za-z]{2,3}$/', $v),
            $type === 'NamePrefix' => fn($v) => is_string($v) && (bool)preg_match('/^[\p{L}.]{1,10}$/u', $v),
            $type === 'Calculations' => fn($v) => is_int($v) || is_float($v) || (is_string($v) && is_numeric($v)),
            default => null,
        };
        if ($fits !== null) {
            if (!is_array($value)) {
                return $fits($value) ? $value : $this->scrub($value, "{$seed}:{$uid}");
            }
            foreach ($value as $key => $item) {
                $value[$key] = $fits($item) ? $item : $this->scrub($item, "{$seed}:{$uid}:{$key}");
            }

            return $value;
        }
        if (is_array($value)) {
            // Groups, repeaters, a name or address in parts, a phone number with its country.
            if ($type === 'Phone') {
                return array_key_exists('number', $value) && $value['number'] !== null && $value['number'] !== ''
                    ? ['number' => Fake::phone("{$seed}:telefoon")] + $value : $this->scrub($value, "{$seed}:{$uid}");
            }

            return $this->values($value, $seed);
        }
        $person = Fake::person("{$seed}:persoon");
        $address = Fake::address("{$seed}:adres");

        return match ($type) {
            'Email' => Fake::email("{$seed}:persoon"),
            'Name' => $person['fullName'],
            'NameFirst' => $person['firstName'],
            'NameMiddle' => $person['infix'],
            'NameLast' => $person['lastName'],
            'Address', 'Address1', 'AddressAutoComplete' => $address['streetAddress'],
            'Address2', 'Address3' => null,
            'AddressCity' => $address['city'],
            'AddressZip' => $address['postcode'],
            'AddressState' => $address['province'],
            'Phone' => Fake::phone("{$seed}:telefoon"),
            'Date' => self::date((string)$value, $seed),
            default => $this->scrub($value, "{$seed}:{$uid}"),
        };
    }

    /** Made up, in the same shape: an e-mail address, digits, a date or lorem; numbers as digits, true and false as they are. */
    private function scrub(mixed $value, string $seed): mixed
    {
        return match (true) {
            is_array($value) => $this->values($value, $seed),
            is_int($value) => (int)Fake::digits($seed, strlen((string)abs($value))),
            !is_string($value) || trim($value) === '' => $value,
            (bool)filter_var(trim($value), FILTER_VALIDATE_EMAIL) => Fake::email($seed),
            (bool)preg_match('/^\d+$/', $value) => Fake::digits($seed, strlen($value)),
            (bool)preg_match('/^\d{4}-\d{2}-\d{2}/', $value) => self::date($value, $seed),
            default => Fake::make('lorem', $seed, null, $value),
        };
    }

    /** A made-up day in the format of $real: a date, or a date and time. */
    private static function date(string $real, string $seed): string
    {
        $date = Fake::date("{$seed}:datum", 1945, 2007);

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $real) ? $date->format('Y-m-d') : $date->format('Y-m-d H:i:s');
    }
}
