<?php

namespace perfectwebteam\anonymize;

use Closure;
use Craft;
use craft\base\ElementInterface;
use craft\base\FieldInterface;
use craft\elements\Address;
use craft\elements\Asset;
use craft\elements\Category;
use craft\elements\ContentBlock;
use craft\elements\Entry;
use craft\elements\GlobalSet;
use craft\elements\Tag;
use craft\elements\User;
use craft\fields\PlainText;
use craft\helpers\ElementHelper;
use craft\helpers\Json;
use craft\helpers\Search as SearchHelper;
use DateTimeInterface;
use perfectwebteam\anonymize\dump\SqlDumpWriter;
use RuntimeException;

/**
 * Plays the Plan on the rows as SqlDumpWriter streams them.
 *
 * From elements, entries, assets and addresses, which it has the dump write
 * first, it learns a few bytes per element: its kind, layout, section,
 * volume, whether it is in the trash and whether it is kept. With those it
 * rewrites elements_sites (content, title, slug, uri) and then searchindex,
 * whose keywords it works out again from the values it wrote. The elements of
 * a plugin with a rule (`elementType:…`) get their title, and keep only the
 * keywords of it. Every other table gets its rule from the config, or from a
 * Handler.
 *
 * The seed of an element is its canonical's id, so drafts and revisions get
 * the same made-up values as what they are drafts of; with `seed` in a rule,
 * the id of the element a relation field points at.
 *
 * The trash is emptied, also in sections, entry types and volumes the config
 * keeps: those say what live content holds, and the trash is where personal
 * data hides (a deleted section full of it, at SPAR). Only what is kept on its
 * own, by id or as a kept user, keeps what it has in the trash.
 */
final class Anonymizer
{
    private const KIND = 0x0f;
    /** Rewritten in elements_sites: its search keywords follow what was written, or go. */
    private const CHANGED = 0x10;
    /** Kept on its own: by id, or as a kept user (keeps its trash too). */
    private const PINNED = 0x20;
    private const TRASHED = 0x40;
    /** Kept: pinned, or in a section, layout, volume or element type that is kept. */
    private const KEPT = 0x80;

    /** The attributes Craft indexes for a user, and the column each comes from. */
    private const USER_ATTRIBUTES = ['email' => 'email', 'username' => 'username', 'fullname' => 'fullName', 'firstname' => 'firstName', 'lastname' => 'lastName'];

    private const ELEMENT_CLASSES = [
        Plan::ENTRY => Entry::class, Plan::USER => User::class, Plan::ASSET => Asset::class, Plan::GLOBAL_SET => GlobalSet::class,
        Plan::ADDRESS => Address::class, Plan::CATEGORY => Category::class, Plan::TAG => Tag::class, Plan::CONTENT_BLOCK => ContentBlock::class,
    ];

    /** @var list<string> */
    public array $problems = [];

    /** @var array<string, int> context label => elements_sites rows it changed */
    public array $touched = [];

    private IdMap $flags;
    private IdMap $layoutCodes;
    private IdMap $sectionCodes;
    private IdMap $volumeCodes;
    private IdMap $extensionCodes;
    private IdMap $classCodes;

    /** @var list<int|string> code => id or class; 0 is "none" */
    private array $layoutIds = [0];
    private array $sectionIds = [0];
    private array $volumeIds = [0];
    private array $classes = [0];

    /** @var array<int|string, int> id or class => code */
    private array $layoutCode = [];
    private array $sectionCode = [];
    private array $volumeCode = [];
    private array $classCode = [];

    /** @var list<string> code => extension; 255 means "in $rareExtensions" */
    private array $extensions = [''];

    /** @var array<string, int> */
    private array $extensionCode = ['' => 0];

    /** @var array<int, string> asset id => extension, past 254 distinct ones */
    private array $rareExtensions = [];

    /** @var array<int, int> draft or revision => canonical, in layouts that change */
    private array $canonicals = [];

    /** @var array<string, array{0: ?string, 1: ?string}> "elementId:siteId" => [title, slug] as written, where they changed */
    private array $titles = [];

    /**
     * @var array<string, string> "elementId:siteId" => "fieldId\x1fkeywords\x1e…", for the elements whose content
     * changed: one string per row rather than an array per field, for a site with a hundred thousand of them
     */
    private array $fieldKeywords = [];

    /** @var array{0: int, 1: array<int, array<int, string>>} the element of $fieldKeywords unpacked last: id, site id => field id => keywords */
    private array $unpacked = [0, []];

    /** @var array<int, string> user id => the indexed attributes as written, joined by \x1f (USER_ATTRIBUTES order) */
    private array $userValues = [];

    /** @var array<int, ElementInterface> kind => an empty element, for normalizeValue() and getSearchKeywords() */
    private array $blank = [];

    /** @var array<string, int> table => rows seen, the seed of a row in a table without a primary key */
    private array $rowNumbers = [];

    private readonly Values $values;
    private readonly string $prefix;

    public function __construct(private readonly Plan $plan)
    {
        $this->prefix = $plan->prefix;
        $this->values = $plan->values;
        // A byte per element id where the codes fit in one: the maps are as long as
        // the highest id, millions on a big site.
        $width = fn(int $count) => $count < 0xff ? 1 : 2;
        $highest = $plan->highest;
        $this->flags = new IdMap(1, $highest['elements']);
        $this->layoutCodes = new IdMap($width($plan->counts['layouts']), $highest['elements']);
        $this->sectionCodes = new IdMap($width($plan->counts['sections']), $highest['entries']);
        $this->volumeCodes = new IdMap($width($plan->counts['volumes']), $highest['assets']);
        $this->extensionCodes = new IdMap(1, $highest['assets']);
        // Only read for the rules per element type.
        $this->classCodes = new IdMap($width($plan->counts['classes']), $plan->elementTypeRules ? $highest['elements'] : 0);
    }

    public function register(SqlDumpWriter $writer): void
    {
        $p = $this->prefix;
        $writer->first(["{$p}elements", "{$p}entries", "{$p}users", "{$p}addresses", "{$p}assets", "{$p}elements_sites", "{$p}searchindex"]);

        $writer->filter("{$p}elements", ['id', 'canonicalId', 'fieldLayoutId', 'type', 'dateDeleted'], fn(array $row) => $this->element($row));
        $writer->filter("{$p}entries", ['id', 'sectionId'], fn(array $row) => $this->entry($row));
        $writer->filter("{$p}assets", ['id', 'volumeId', 'filename', 'alt'], fn(array $row) => $this->asset($row));
        $writer->filter("{$p}assets_sites", ['assetId', 'alt'], fn(array $row) => $this->assetSite($row));
        $writer->filter("{$p}elements_sites", ['id', 'elementId', 'siteId', 'title', 'slug', 'uri', 'content'], fn(array $row) => $this->elementSite($row));
        $writer->filter("{$p}searchindex", ['elementId', 'attribute', 'fieldId', 'siteId', 'keywords'], fn(array $row) => $this->keywordsRow($row));

        foreach ($this->plan->tables as $table => $rule) {
            if ($rule['empty']) {
                $writer->empty($table);
                continue;
            }
            $needs = array_merge($rule['key'], array_keys($rule['columns']), is_array($rule['keepRows']) ? array_keys($rule['keepRows']) : []);
            if ($table === "{$p}users") {
                $writer->filter($table, array_values(array_unique(array_merge($needs, ['id']))), fn(array $row) => $this->user($row, $rule));
            } elseif ($table === "{$p}addresses") {
                $writer->filter($table, array_values(array_unique(array_merge($needs, ['id', 'primaryOwnerId']))), fn(array $row) => $this->address($row, $rule));
            } else {
                $writer->filter($table, array_values(array_unique($needs)), fn(array $row) => $this->tableRow($table, $rule, $row));
            }
        }
        // Last: a handler gets the row as the rules and the script made it, and adds to that.
        foreach ($this->plan->handled as $table => $handlers) {
            $short = substr($table, strlen($p));
            foreach ($handlers as $handler) {
                $writer->filter($table, $this->plan->handledColumns[$table], fn(array $row) => $handler->row($short, $row));
            }
        }
    }

    /**
     * After the run: refuses a dump without Craft's own tables, and one in
     * which a context with rules and elements matched no row.
     *
     * @param list<string> $absent the filtered tables the database does not have
     */
    public function finish(array $absent): void
    {
        $missing = array_intersect($absent, array_map(fn($t) => $this->prefix . $t, ['elements', 'elements_sites', 'searchindex', 'users']));
        if ($missing) {
            throw new RuntimeException('De database heeft geen ' . implode(', ', $missing) . '; is het wel die van Craft?');
        }
        foreach ($this->plan->contexts as $context) {
            if ($context['inData'] && $context['changes'] && !$context['kept'] && !isset($this->touched[$context['label']])) {
                throw new RuntimeException("{$context['label']} heeft entries en regels, maar geen enkele rij werd herkend");
            }
        }
    }

    // What the dump learns
    // =========================================================================

    private function element(array $row): array
    {
        $id = (int)$row['id'];
        $kind = Plan::KINDS[$row['type']] ?? Plan::OTHER;
        $layoutId = $row['fieldLayoutId'] !== null ? (int)$row['fieldLayoutId'] : ($kind === Plan::USER ? (int)$this->plan->userLayoutId : 0);
        $trashed = $row['dateDeleted'] !== null;
        $pinned = isset($this->plan->keptIds[$id]) || ($kind === Plan::USER && isset($this->plan->keptUsers[$id]));
        $kept = $pinned || isset($this->plan->keptLayouts[$layoutId]) || isset($this->plan->keptTypes[$row['type']]);

        if ($row['canonicalId'] !== null) {
            $canonical = (int)$row['canonicalId'];
            $of = $this->flags->get($canonical);
            // Deleting a section dates only the canonical entries: their
            // drafts and revisions go with them here.
            $trashed = $trashed || ($of & self::TRASHED);
            $pinned = $pinned || ($of & self::PINNED);
            $kept = $kept || ($of & self::KEPT);
            if (isset($this->plan->changing[$layoutId])) {
                $this->canonicals[$id] = $canonical;
            }
        }

        $this->flags->set($id, $kind | ($trashed ? self::TRASHED : 0) | ($pinned ? self::PINNED : 0) | ($kept ? self::KEPT : 0));
        if ($layoutId) {
            $this->layoutCodes->set($id, self::code($layoutId, $this->layoutIds, $this->layoutCode));
        }
        if ($kind === Plan::OTHER && $this->plan->elementTypeRules) {
            $this->classCodes->set($id, self::code($row['type'], $this->classes, $this->classCode));
        }

        return [];
    }

    private function entry(array $row): array
    {
        if ($row['sectionId'] !== null) {
            $id = (int)$row['id'];
            $sectionId = (int)$row['sectionId'];
            $this->sectionCodes->set($id, self::code($sectionId, $this->sectionIds, $this->sectionCode));
            if (isset($this->plan->keptSections[$sectionId])) {
                $this->flags->set($id, $this->flags->get($id) | self::KEPT);
            }
        }

        return [];
    }

    private function asset(array $row): array
    {
        $id = (int)$row['id'];
        $volumeId = $row['volumeId'] !== null ? (int)$row['volumeId'] : 0;
        $this->volumeCodes->set($id, self::code($volumeId, $this->volumeIds, $this->volumeCode));

        $extension = strtolower(pathinfo((string)$row['filename'], PATHINFO_EXTENSION));
        if (!isset($this->extensionCode[$extension]) && count($this->extensions) < 255) {
            $this->extensions[] = $extension;
            $this->extensionCode[$extension] = count($this->extensions) - 1;
        }
        if (isset($this->extensionCode[$extension])) {
            $this->extensionCodes->set($id, $this->extensionCode[$extension]);
        } else {
            $this->extensionCodes->set($id, 255);
            $this->rareExtensions[$id] = $extension;
        }

        if ($volumeId && isset($this->plan->keptVolumes[$volumeId])) {
            $this->flags->set($id, $this->flags->get($id) | self::KEPT);
        }
        if ($this->flags->get($id) & self::KEPT) {
            return [];
        }

        $rule = $this->volumeRule($id);
        $changes = [];
        if ($rule['filename']->kind === Strategy::PLACEHOLDER && $row['filename'] !== null) {
            $changes['filename'] = $this->assetName($id, $rule)[1];
        }
        if ($rule['alt']->kind === Strategy::NULL && $row['alt'] !== null) {
            $changes['alt'] = null;
        }

        return $changes;
    }

    private function assetSite(array $row): array
    {
        $id = (int)$row['assetId'];
        if ($this->flags->get($id) & self::KEPT || $row['alt'] === null) {
            return [];
        }

        return $this->volumeRule($id)['alt']->kind === Strategy::NULL ? ['alt' => null] : [];
    }

    // Element tables
    // =========================================================================

    private function user(array $row, array $rule): array
    {
        $id = (int)$row['id'];
        if (isset($this->plan->keptUsers[$id]) || ($this->flags->get($id) & self::KEPT)) {
            return [];
        }
        $changes = $this->columns($rule, $row, (string)($this->canonicals[$id] ?? $id), $id);
        $written = $changes + $row;
        $this->userValues[$id] = implode("\x1f", array_map(fn($column) => (string)($written[$column] ?? ''), self::USER_ATTRIBUTES));

        return $changes;
    }

    private function address(array $row, array $rule): array
    {
        $id = (int)$row['id'];
        $owner = $row['primaryOwnerId'] !== null ? $this->flags->get((int)$row['primaryOwnerId']) : 0;
        // An address goes with whom it belongs to.
        $flags = $this->flags->get($id) | ($owner & (self::KEPT | self::PINNED));
        if ($flags & self::KEPT) {
            $this->flags->set($id, $flags);

            return [];
        }

        return $this->columns($rule, $row, (string)$id, $id);
    }

    // Content
    // =========================================================================

    private function elementSite(array $row): array
    {
        $elementId = (int)$row['elementId'];
        $flags = $this->flags->get($elementId);
        if ($flags === 0) {
            // A foreign key makes every row's element part of a full dump.
            throw new RuntimeException("Rij {$row['id']} van elements_sites hoort bij element {$elementId}, dat niet in elements staat");
        }
        if ($flags & self::TRASHED && !($flags & self::PINNED)) {
            // What a trashed element held goes; the element stays.
            return array_filter([
                'title' => $row['title'] === null ? false : "verwijderd-{$elementId}",
                'slug' => $row['slug'] === null ? false : "verwijderd-{$elementId}",
                'uri' => $row['uri'] === null ? false : null,
                'content' => $row['content'] === null ? false : '[]',
            ], fn($value) => $value !== false);
        }

        $kind = $flags & self::KIND;
        $context = $this->context($elementId, $kind);
        $typeRule = $kind === Plan::OTHER ? ($this->plan->elementTypeRules[$this->classes[$this->classCodes->get($elementId)]] ?? null) : null;
        if ($context !== null && $context['changes']) {
            // For finish(): this context's rules met its rows.
            $this->touched[$context['label']] = ($this->touched[$context['label']] ?? 0) + 1;
        }
        if ($flags & self::KEPT) {
            return [];
        }

        $changes = [];
        if ($kind === Plan::ASSET && $row['title'] !== null) {
            $title = $this->assetTitle($elementId);
            if ($title !== null && $title !== $row['title']) {
                $changes['title'] = $title;
            }
        }

        if ($context === null) {
            $layoutId = $this->layoutIds[$this->layoutCodes->get($elementId)];
            if ($layoutId && !isset($this->plan->layouts[$layoutId]) && self::hasContent($row['content'])) {
                throw new RuntimeException("Element {$elementId} staat niet in de prullenbak, maar zijn veldindeling ({$layoutId}) bestaat niet meer; de regels kennen het niet");
            }
        }
        $byContext = $context !== null && !$context['kept'] && $context['changes'];
        if (!$byContext && $typeRule === null) {
            return $changes;
        }
        // From here on the element changes, and its search keywords follow.
        $this->flags->set($elementId, $flags | self::CHANGED);

        $content = [];
        if ($row['content'] !== null) {
            $content = Json::decodeIfJson($row['content']) ?? [];
            if (!is_array($content)) {
                throw new RuntimeException("De content van rij {$row['id']} van elements_sites is geen JSON-object");
            }
        }
        $seedId = $this->seedId($elementId, $byContext ? $context : null, $content);
        $seed = (string)$seedId;
        $byHandle = [];
        foreach ($context['handles'] ?? [] as $handle => $uid) {
            $byHandle[$handle] = $content[$uid] ?? null;
        }

        $new = $content;
        if ($byContext) {
            foreach ($context['fields'] as $uid => ['strategy' => $strategy, 'handle' => $handle, 'field' => $field]) {
                $original = $content[$uid] ?? null;
                if ($strategy->kind === Strategy::NULL) {
                    unset($new[$uid]);
                    continue;
                }
                if ($strategy->kind !== Strategy::CLOSURE && !self::filled($original)) {
                    continue;
                }
                $stored = $this->store($field, $this->values->make($strategy, $original, $seed, $handle, $seedId, $byHandle), $kind);
                if ($stored === null || $stored === '' || $stored === []) {
                    unset($new[$uid]);
                } else {
                    $new[$uid] = $stored;
                }
            }
            // A save keeps only what the layout has: a key of an old field goes,
            // and whatever it held with it.
            $new = array_intersect_key($new, $context['keys']);
            if ($row['content'] !== null && $new !== $content) {
                $changes['content'] = Json::encode($new);
            }
        }

        $title = $row['title'];
        $titleRule = $byContext ? $context['title'] : null;
        if ($titleRule === null && $typeRule !== null && $typeRule['title'] !== null && !$typeRule['title']->isKeep()) {
            $titleRule = $typeRule['title'];
        }
        if ($title !== null && $titleRule !== null) {
            $title = self::text($this->values->make($titleRule, $title, $seed, 'title', $seedId, $byHandle));
        } elseif ($title !== null && $byContext && $context['titleFormat'] !== null) {
            $title = $this->formatTitle($context, $new);
        }
        if ($title !== $row['title']) {
            $changes['title'] = $title !== '' ? $title : "anoniem-{$seedId}";
            if ($row['slug'] !== null && !str_starts_with($row['slug'], '__temp_')) {
                $slug = ElementHelper::generateSlug("{$changes['title']} {$seedId}");
                if ($slug !== $row['slug']) {
                    $changes['slug'] = $slug;
                    if ($row['uri'] !== null) {
                        $uri = implode('/', array_map(fn($part) => $part === $row['slug'] ? $slug : $part, explode('/', $row['uri'])));
                        if ($uri !== $row['uri']) {
                            $changes['uri'] = $uri;
                        }
                    }
                }
            }
            $this->titles["{$elementId}:{$row['siteId']}"] = [$changes['title'], $changes['slug'] ?? $row['slug']];
        }

        $siteId = (int)$row['siteId'];
        $packed = '';
        foreach ($byContext ? $context['searchable'] : [] as $fieldId => $instances) {
            $packed .= $fieldId . "\x1f" . $this->fieldKeywords($instances, $new, $siteId, $kind) . "\x1e";
        }
        if ($packed !== '') {
            $this->fieldKeywords["{$elementId}:{$siteId}"] = $packed;
        }

        return $changes;
    }

    /** Search keywords as a save would write them now: from the content as it was written. */
    private function keywordsRow(array $row): array
    {
        $elementId = (int)$row['elementId'];
        $flags = $this->flags->get($elementId);
        $blank = $row['keywords'] === '' ? [] : ['keywords' => ''];
        // Gone, or in the trash: nothing to keep.
        if ($flags === 0 || (($flags & self::TRASHED) && !($flags & self::PINNED))) {
            return $blank;
        }
        if ($flags & self::KEPT) {
            return [];
        }

        $kind = $flags & self::KIND;
        $attribute = $row['attribute'];
        $siteId = (int)$row['siteId'];
        if ($kind === Plan::ADDRESS) {
            return $blank;
        }
        if ($kind === Plan::USER && $attribute !== 'field') {
            $position = array_search($attribute, array_keys(self::USER_ATTRIBUTES), true);
            if ($position === false || !isset($this->userValues[$elementId])) {
                return $blank;
            }

            return $this->keywordsChange($row, explode("\x1f", $this->userValues[$elementId])[$position]);
        }
        if ($kind === Plan::ASSET && $attribute !== 'field') {
            $rule = $this->volumeRule($elementId);

            return match ($attribute) {
                'filename' => $rule['filename']->kind === Strategy::PLACEHOLDER ? $this->keywordsChange($row, $this->assetName($elementId, $rule)[1]) : [],
                'title' => ($title = $this->assetTitle($elementId)) !== null ? $this->keywordsChange($row, $title) : [],
                'alt' => $rule['alt']->kind === Strategy::NULL ? $blank : [],
                'extension', 'kind' => [],
                default => $blank,
            };
        }

        if (!($flags & self::CHANGED)) {
            return [];
        }
        $key = "{$elementId}:{$siteId}";
        if ($attribute === 'field') {
            // The rows of an element come together (the table's key starts with
            // elementId, its sites alternate within): unpack all its sites once,
            // and let the element before go.
            if ($this->unpacked[0] !== $elementId) {
                $fields = [];
                foreach (array_keys($this->plan->languages) as $site) {
                    unset($this->fieldKeywords["{$this->unpacked[0]}:{$site}"]);
                    foreach (explode("\x1e", $this->fieldKeywords["{$elementId}:{$site}"] ?? '') as $part) {
                        if ($part !== '') {
                            [$fieldId, $keywords] = explode("\x1f", $part, 2);
                            $fields[$site][(int)$fieldId] = $keywords;
                        }
                    }
                }
                $this->unpacked = [$elementId, $fields];
            }
            // A field the layout no longer has: whatever it held goes.
            $keywords = $this->unpacked[1][$siteId][(int)$row['fieldId']] ?? '';

            return $keywords === $row['keywords'] ? [] : ['keywords' => $keywords];
        }

        return match ($attribute) {
            'title' => isset($this->titles[$key]) ? $this->keywordsChange($row, (string)$this->titles[$key][0]) : [],
            'slug' => isset($this->titles[$key]) ? $this->keywordsChange($row, (string)$this->titles[$key][1]) : [],
            default => $blank,
        };
    }

    // Other tables
    // =========================================================================

    private function tableRow(string $table, array $rule, array $row): array|false
    {
        if ($this->keepRow($rule['keepRows'], $row)) {
            return [];
        }
        $drop = $rule['dropRows'];
        if ($drop === true || ($drop instanceof Closure && $drop($row))) {
            return false;
        }
        // Without a primary key, the row's place in the table.
        $id = $rule['key']
            ? implode(':', array_map(fn($column) => (string)$row[$column], $rule['key']))
            : (string)($this->rowNumbers[$table] = ($this->rowNumbers[$table] ?? 0) + 1);

        return $this->columns($rule, $row, substr($table, strlen($this->prefix)) . ":{$id}", $id);
    }

    private function keepRow(mixed $keepRows, array $row): bool
    {
        if ($keepRows instanceof Closure) {
            return (bool)$keepRows($row);
        }
        foreach ($keepRows as $column => $ref) {
            $value = $row[$column];
            $hit = match (true) {
                $ref === 'keptUsers' => $value !== null && isset($this->plan->keptUsers[(int)$value]),
                $ref === 'keptElements' => $value !== null && (bool)($this->flags->get((int)$value) & self::KEPT),
                is_array($ref) => $value !== null && in_array($value, array_map('strval', $ref), true),
                default => false,
            };
            if ($hit) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string, ?string> the columns that change */
    private function columns(array $rule, array $row, string $seed, int|string $id): array
    {
        $changes = [];
        foreach ($rule['columns'] as $column => $strategy) {
            $original = $row[$column];
            if ($strategy->kind === Strategy::NULL) {
                if ($original !== null) {
                    $changes[$column] = null;
                }
                continue;
            }
            if ($strategy->kind !== Strategy::CLOSURE && !self::filled($original)) {
                continue;
            }
            $value = self::column($this->values->make($strategy, $original, $seed, $column, $id, $row), $rule['types'][$column] ?? '');
            if ($value === null && !($rule['nullable'][$column] ?? true)) {
                // An import refuses NULL here; better to stop now than halfway through it.
                throw new RuntimeException("{$strategy->where} geeft NULL, maar die kolom mag niet leeg zijn");
            }
            if ($value !== $original) {
                $changes[$column] = $value;
            }
        }

        return $changes;
    }

    // Helpers
    // =========================================================================

    private function context(int $elementId, int $kind): ?array
    {
        $layoutId = $this->layoutIds[$this->layoutCodes->get($elementId)];
        if (!$layoutId) {
            return null;
        }
        $sectionId = $kind === Plan::ENTRY ? $this->sectionIds[$this->sectionCodes->get($elementId)] : 0;

        return $this->plan->context($layoutId, $sectionId);
    }

    private function seedId(int $elementId, ?array $context, array $content): int
    {
        $canonical = $this->canonicals[$elementId] ?? $elementId;
        if ($context !== null && $context['seedField'] !== null) {
            $target = $this->plan->relationSeeds[$elementId] ?? $this->plan->relationSeeds[$canonical] ?? null;
            if ($target === null) {
                $linked = $content[$context['seedUid']] ?? null;
                $target = is_array($linked) && $linked ? (int)reset($linked) : null;
            }
            if ($target) {
                return $target;
            }
        }

        return $canonical;
    }

    /** The value as the content stores it: through the field, as a save would. */
    private function store(FieldInterface $field, mixed $value, int $kind): mixed
    {
        if ($value === null) {
            return null;
        }
        if ($this->plan->isText($field)) {
            $text = self::text($value);

            return $field instanceof PlainText && $field->charLimit ? mb_substr($text, 0, $field->charLimit) : $text;
        }
        $element = $this->blank($kind);

        return $field->serializeValueForDb($field->normalizeValue($value, $element), $element);
    }

    /**
     * Search::indexElementAttributes(): a field's keywords are those of its
     * instances in the layout, in order. Of a type whose keywords would need a
     * query (a relation's), they are left empty.
     *
     * @param list<array{0: FieldInterface, 1: string}> $instances
     */
    private function fieldKeywords(array $instances, array $content, int $siteId, int $kind): string
    {
        $parts = [];
        $element = $this->blank($kind);
        foreach ($instances as [$field, $uid]) {
            if (!$this->plan->keywordSafe($field)) {
                return '';
            }
            $parts[] = $field->getSearchKeywords($field->normalizeValue($content[$uid] ?? null, $element), $element);
        }

        return $this->keywords(implode(' ', $parts), $siteId);
    }

    /** Search::_indexKeywords() for $text on the site. */
    private function keywords(string $text, int $siteId): string
    {
        $keywords = SearchHelper::normalizeKeywords($text, [], true, $this->plan->languages[$siteId] ?? Craft::$app->language);

        return $keywords !== '' ? " {$keywords} " : '';
    }

    private function keywordsChange(array $row, string $text): array
    {
        $keywords = $this->keywords($text, (int)$row['siteId']);

        return $keywords === $row['keywords'] ? [] : ['keywords' => $keywords];
    }

    /** The title an entry type's titleFormat gives with the content as written. */
    private function formatTitle(array $context, array $content): string
    {
        return trim(preg_replace_callback('/\{(\w+)\}/', function(array $m) use ($context, $content): string {
            $uid = $context['handles'][$m[1]] ?? null;

            return $uid !== null ? self::text($content[$uid] ?? '') : '';
        }, $context['titleFormat']) ?? '');
    }

    private function volumeRule(int $assetId): array
    {
        $volumeId = $this->volumeIds[$this->volumeCodes->get($assetId)];

        return $this->plan->volumeRules[$volumeId] ?? $this->plan->volumeRules[0];
    }

    /** The title an asset gets, or null to keep its own. */
    private function assetTitle(int $assetId): ?string
    {
        $rule = $this->volumeRule($assetId);

        return match ($rule['title']->kind) {
            Strategy::KEEP => null,
            Strategy::PLACEHOLDER => $this->assetName($assetId, $rule)[0],
            default => self::text($this->values->make($rule['title'], null, (string)$assetId, 'title', $assetId)),
        };
    }

    /** @return array{0: string, 1: string} the made-up title and filename of an asset */
    private function assetName(int $assetId, array $rule): array
    {
        $code = $this->extensionCodes->get($assetId);
        $extension = $code === 255 ? ($this->rareExtensions[$assetId] ?? '') : ($this->extensions[$code] ?? '');
        $title = "{$rule['stem']}-{$assetId}";

        return [$title, $title . ($extension !== '' ? ".{$extension}" : '')];
    }

    private function blank(int $kind): ElementInterface
    {
        $class = self::ELEMENT_CLASSES[$kind] ?? Entry::class;

        return $this->blank[$kind] ??= new $class();
    }

    /** @param array<int|string, int> $codes id or class => code */
    private static function code(int|string $id, array &$ids, array &$codes): int
    {
        if (!isset($codes[$id])) {
            if (count($ids) > 0xffff) {
                throw new RuntimeException('Meer dan 65.535 veldindelingen, secties, volumes of elementtypes');
            }
            $ids[] = $id;
            $codes[$id] = count($ids) - 1;
        }

        return $codes[$id];
    }

    /** What a column holds: text, with dates in the column's own form. */
    private static function column(mixed $value, string $type): ?string
    {
        return match (true) {
            $value === null => null,
            $value instanceof DateTimeInterface => $value->format(match ($type) {
                'date' => 'Y-m-d',
                'time' => 'H:i:s',
                default => 'Y-m-d H:i:s',
            }),
            is_bool($value) => $value ? '1' : '0',
            is_array($value) => Json::encode($value),
            default => (string)$value,
        };
    }

    private static function text(mixed $value): string
    {
        return match (true) {
            $value === null => '',
            $value instanceof DateTimeInterface => $value->format('Y-m-d'),
            is_array($value) => '',
            default => (string)$value,
        };
    }

    /** Filled, as a value in the content or a column: not null, not blank, not an empty list. */
    private static function filled(mixed $value): bool
    {
        return $value !== null && !(is_string($value) && trim($value) === '') && $value !== [];
    }

    private static function hasContent(?string $content): bool
    {
        return $content !== null && $content !== '' && $content !== '[]' && $content !== '{}';
    }
}
