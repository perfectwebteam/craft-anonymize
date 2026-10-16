<?php

namespace perfectwebteam\anonymize;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Made-up but well-formed personal data, the same for the same seed: a re-run
 * gives the same copy, and values that share a seed describe the same person
 * or address (`firstName@owner` and `surname@owner`).
 *
 * Everything that could reach a real person is unmistakably fake: street
 * names are made up, e-mail addresses end in `@example.invalid` (RFC 2606,
 * never deliverable), telephone numbers start with 000, IP addresses come
 * from TEST-NET-1 (RFC 5737), URLs point at example.invalid and IBANs at a
 * bank that does not exist. The word lists come from SPAR, where they made
 * its dummy data.
 */
final class Fake
{
    /** Never deliverable (RFC 2606), so a test environment cannot mail anyone. */
    public const EMAIL_DOMAIN = 'example.invalid';

    /** What a strategy may name, and whether it needs a number (`digits:8`). */
    public const GENERATORS = [
        'firstName' => false, 'infix' => false, 'surname' => false, 'lastName' => false, 'fullName' => false,
        'email' => false, 'phone' => false, 'company' => false,
        'street' => false, 'houseNumber' => false, 'streetAddress' => false, 'postcode' => false, 'city' => false,
        'province' => false, 'latitude' => false, 'longitude' => false,
        'date' => false, 'birthDate' => false, 'iban' => false, 'ip' => false, 'url' => false,
        'lorem' => false, 'digits' => true, 'token' => false,
    ];

    private const FIRST_NAMES = [
        'Anna', 'Bram', 'Carla', 'Daan', 'Eva', 'Finn', 'Gijs', 'Hanna', 'Iris', 'Joost',
        'Kim', 'Lars', 'Maud', 'Noor', 'Olaf', 'Puck', 'Ruben', 'Sanne', 'Tom', 'Vera',
    ];

    /** [infix, surname] */
    private const SURNAMES = [
        ['de', 'Boer'], ['van', 'Dijk'], ['', 'Bakker'], ['', 'Visser'], ['', 'Smit'],
        ['', 'Meijer'], ['de', 'Groot'], ['', 'Mulder'], ['', 'Bos'], ['', 'Vos'],
        ['', 'Peters'], ['', 'Hendriks'], ['van', 'Leeuwen'], ['', 'Dekker'], ['', 'Brouwer'],
        ['de', 'Wit'], ['', 'Dijkstra'], ['', 'Smits'], ['de', 'Graaf'], ['van der', 'Meer'],
    ];

    /**
     * Made up on purpose: "Kerkstraat 12" exists in half the towns, so a real
     * street name could hand someone another person's real address, and makes
     * a leftover real address impossible to tell from a dummy one.
     */
    private const STREETS = [
        'Voorbeeldstraat', 'Proefweg', 'Testlaan', 'Demoplein', 'Oefenstraat',
        'Modelweg', 'Schetslaan', 'Ontwerpkade', 'Proeflaan', 'Voorbeeldplein',
        'Testweg', 'Demostraat', 'Oefenlaan', 'Modelplein', 'Schetsstraat',
    ];

    /** [city, province, first two postcode digits, latitude, longitude] */
    private const CITIES = [
        ['Amsterdam', 'Noord-Holland', '10', 52.3676, 4.9041],
        ['Haarlem', 'Noord-Holland', '20', 52.3874, 4.6462],
        ['Alkmaar', 'Noord-Holland', '18', 52.6324, 4.7534],
        ['Rotterdam', 'Zuid-Holland', '30', 51.9244, 4.4777],
        ['Leiden', 'Zuid-Holland', '23', 52.1601, 4.4970],
        ['Utrecht', 'Utrecht', '35', 52.0907, 5.1214],
        ['Amersfoort', 'Utrecht', '38', 52.1561, 5.3878],
        ['Groningen', 'Groningen', '97', 53.2194, 6.5665],
        ['Leeuwarden', 'Friesland', '89', 53.2012, 5.7999],
        ['Sneek', 'Friesland', '86', 53.0326, 5.6589],
        ['Assen', 'Drenthe', '94', 52.9925, 6.5649],
        ['Emmen', 'Drenthe', '78', 52.7792, 6.9069],
        ['Zwolle', 'Overijssel', '80', 52.5168, 6.0830],
        ['Deventer', 'Overijssel', '74', 52.2661, 6.1552],
        ['Enschede', 'Overijssel', '75', 52.2215, 6.8937],
        ['Lelystad', 'Flevoland', '82', 52.5185, 5.4714],
        ['Arnhem', 'Gelderland', '68', 51.9851, 5.8987],
        ['Nijmegen', 'Gelderland', '65', 51.8126, 5.8372],
        ['Apeldoorn', 'Gelderland', '73', 52.2112, 5.9699],
        ['Eindhoven', 'Noord-Brabant', '56', 51.4416, 5.4697],
        ['Tilburg', 'Noord-Brabant', '50', 51.5555, 5.0913],
        ['Breda', 'Noord-Brabant', '48', 51.5719, 4.7683],
        ['Maastricht', 'Limburg', '62', 50.8514, 5.6910],
        ['Venlo', 'Limburg', '59', 51.3704, 6.1724],
        ['Middelburg', 'Zeeland', '43', 51.4988, 3.6110],
        ['Goes', 'Zeeland', '44', 51.5040, 3.8890],
    ];

    /** Postcode letters in use; SA, SD and SS are never issued. */
    private const POSTCODE_LETTERS = 'ABCEGHJKLMNPRTVWXZ';

    private const COMPANY_SUFFIXES = ['B.V.', 'Holding B.V.', 'Retail B.V.', 'V.O.F.', '& Zonen'];

    private const LOREM = [
        'lorem', 'ipsum', 'dolor', 'sit', 'amet', 'consectetur', 'adipiscing', 'elit', 'sed', 'do', 'eiusmod',
        'tempor', 'incididunt', 'ut', 'labore', 'et', 'dolore', 'magna', 'aliqua', 'enim', 'ad', 'minim',
        'veniam', 'quis', 'nostrud', 'exercitation', 'ullamco', 'laboris', 'nisi', 'aliquip', 'ex', 'ea',
        'commodo', 'consequat',
    ];

    /**
     * The value $generator makes for $seed.
     *
     * @param mixed $original the real value, for the generators that keep its shape (lorem)
     * @param string $secret what token() mixes in, so a token cannot be made again outside the run
     */
    public static function make(string $generator, string $seed, ?int $arg = null, mixed $original = null, string $secret = ''): mixed
    {
        return match ($generator) {
            'firstName', 'infix', 'surname', 'lastName', 'fullName' => self::person($seed)[$generator],
            'street', 'houseNumber', 'streetAddress', 'postcode', 'city', 'province', 'latitude', 'longitude' => self::address($seed)[$generator],
            'email' => self::email($seed),
            'phone' => self::phone($seed),
            'company' => self::company($seed),
            'date' => self::date($seed, 2000, 2025),
            'birthDate' => self::date($seed, 1945, 2007),
            'iban' => self::iban($seed),
            'ip' => '192.0.2.' . self::number("{$seed}:ip", 1, 254),
            'url' => 'https://' . self::EMAIL_DOMAIN . '/' . substr(hash('sha256', $seed), 0, 10),
            'lorem' => is_string($original) && str_contains($original, '<')
                ? self::loremHtml($original, $seed)
                : self::lorem($seed, is_string($original) ? max(1, mb_strlen(trim($original))) : 40),
            'digits' => self::digits($seed, $arg ?? 6),
            'token' => hash_hmac('sha256', $seed, $secret),
        };
    }

    /**
     * A person: first name, infix (often ''), surname, the last name with its
     * infix, and all of it joined the Dutch way.
     *
     * @return array{firstName: string, infix: string, surname: string, lastName: string, fullName: string}
     */
    public static function person(string $seed): array
    {
        $first = self::pick(self::FIRST_NAMES, "{$seed}:voornaam");
        [$infix, $surname] = self::pick(self::SURNAMES, "{$seed}:achternaam");

        return [
            'firstName' => $first,
            'infix' => $infix,
            'surname' => $surname,
            'lastName' => trim("{$infix} {$surname}"),
            'fullName' => implode(' ', array_filter([$first, $infix, $surname])),
        ];
    }

    /**
     * An address in one of the towns above, with the town's own province,
     * postcode area and coordinates (moved up to about a kilometre, so two
     * addresses in one town do not sit on the same spot of a map).
     *
     * @return array{streetAddress: string, street: string, houseNumber: string, postcode: string, city: string, province: string, latitude: float, longitude: float}
     */
    public static function address(string $seed): array
    {
        [$city, $province, $area, $latitude, $longitude] = self::pick(self::CITIES, "{$seed}:plaats");
        $street = self::pick(self::STREETS, "{$seed}:straat");
        $number = (string)self::number("{$seed}:huisnummer", 1, 199);
        $letters = self::POSTCODE_LETTERS;
        $postcode = $area . sprintf('%02d', self::number("{$seed}:postcode", 0, 99)) . ' '
            . $letters[self::number("{$seed}:letter1", 0, strlen($letters) - 1)]
            . $letters[self::number("{$seed}:letter2", 0, strlen($letters) - 1)];

        return [
            'streetAddress' => "{$street} {$number}",
            'street' => $street,
            'houseNumber' => $number,
            'postcode' => $postcode,
            'city' => $city,
            'province' => $province,
            'latitude' => round($latitude + (self::number("{$seed}:lat", 0, 200) - 100) / 10000, 7),
            'longitude' => round($longitude + (self::number("{$seed}:lng", 0, 200) - 100) / 10000, 7),
        ];
    }

    /** `voornaam.achternaam-1234@example.invalid`, for the person of the same seed. */
    public static function email(string $seed): string
    {
        $person = self::person($seed);
        $local = strtolower("{$person['firstName']}.{$person['surname']}") . '-' . sprintf('%04d', self::number("{$seed}:nr", 0, 9999));

        return $local . '@' . self::EMAIL_DOMAIN;
    }

    /** '000-1234567': the shape of a Dutch number, and no one's. */
    public static function phone(string $seed): string
    {
        return '000-' . sprintf('%07d', self::number("{$seed}:telefoon", 0, 9999999));
    }

    public static function company(string $seed): string
    {
        return self::person($seed)['surname'] . ' ' . self::pick(self::COMPANY_SUFFIXES, "{$seed}:rechtsvorm");
    }

    /** A day between 1 January $from and 31 December $to, at midnight UTC. */
    public static function date(string $seed, int $from, int $to): DateTimeImmutable
    {
        $start = gmmktime(0, 0, 0, 1, 1, $from);
        $days = intdiv(gmmktime(0, 0, 0, 12, 31, $to) - $start, 86400);

        return (new DateTimeImmutable('@' . ($start + self::number("{$seed}:dag", 0, $days) * 86400)))->setTimezone(new DateTimeZone('UTC'));
    }

    /** A Dutch IBAN with a valid check, at bank code TEST, which no bank has. */
    public static function iban(string $seed): string
    {
        $bban = 'TEST' . self::digits("{$seed}:rekening", 10);
        $numeric = '';
        foreach (str_split($bban . 'NL00') as $char) {
            $numeric .= ctype_alpha($char) ? (string)(ord($char) - 55) : $char;
        }
        $remainder = 0;
        foreach (str_split($numeric, 7) as $chunk) {
            $remainder = (int)($remainder . $chunk) % 97;
        }

        return sprintf('NL%02d%s', 98 - $remainder, $bban);
    }

    /** A string of $length digits, for identifiers (KvK, EAN, customer numbers). */
    public static function digits(string $seed, int $length): string
    {
        $digits = '';
        for ($i = 0; strlen($digits) < $length; $i++) {
            $digits .= sprintf('%09d', self::number("{$seed}:{$i}", 0, 999999999));
        }

        return substr($digits, 0, $length);
    }

    /** Latin words up to $length characters, starting with a capital. */
    public static function lorem(string $seed, int $length): string
    {
        $text = '';
        for ($i = 0; mb_strlen($text) < $length; $i++) {
            $text .= ($text === '' ? '' : ' ') . self::pick(self::LOREM, "{$seed}:{$i}");
        }

        return ucfirst(rtrim(mb_substr($text, 0, $length)));
    }

    /**
     * HTML with its text made up and its tags kept: markup, CKEditor's nested
     * entries and Craft's reference tags stay. Links and images that point
     * outside go to example.invalid; alt and title attributes become lorem.
     */
    public static function loremHtml(string $html, string $seed): string
    {
        $parts = preg_split('/(<[^>]*>)/', $html, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$html];
        foreach ($parts as $i => $part) {
            if ($i % 2 === 1) {
                $parts[$i] = self::scrubTag($part, "{$seed}:{$i}");
                continue;
            }
            $text = html_entity_decode($part, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if (trim($text) === '') {
                continue;
            }
            preg_match('/^\s*/u', $text, $lead);
            preg_match('/\s*$/u', $text, $trail);
            $parts[$i] = $lead[0] . htmlspecialchars(self::lorem("{$seed}:{$i}", mb_strlen(trim($text))), ENT_QUOTES | ENT_HTML5) . $trail[0];
        }

        return implode('', $parts);
    }

    /** An entry from $list, the same one for the same seed. */
    public static function pick(array $list, string $seed): mixed
    {
        return $list[self::number($seed, 0, count($list) - 1)];
    }

    /** An int in [$min, $max], the same one for the same seed. */
    public static function number(string $seed, int $min, int $max): int
    {
        return $min + (crc32($seed) % ($max - $min + 1));
    }

    private static function scrubTag(string $tag, string $seed): string
    {
        return preg_replace_callback('/\b(href|src|alt|title)\s*=\s*("[^"]*"|\'[^\']*\')/i', static function(array $m) use ($seed): string {
            $value = substr($m[2], 1, -1);
            $attribute = strtolower($m[1]);
            if ($attribute === 'alt' || $attribute === 'title') {
                $value = $value === '' ? '' : self::lorem("{$seed}:{$attribute}", mb_strlen($value));
            } elseif ($value !== '' && $value[0] !== '{' && $value[0] !== '#') {
                $value = match (true) {
                    str_starts_with($value, 'mailto:') => 'mailto:anoniem@' . self::EMAIL_DOMAIN,
                    str_starts_with($value, 'tel:') => 'tel:000',
                    default => 'https://' . self::EMAIL_DOMAIN . '/',
                };
            }

            return $m[1] . '="' . htmlspecialchars($value, ENT_QUOTES | ENT_HTML5) . '"';
        }, $tag) ?? $tag;
    }
}
