<?php

namespace perfectwebteam\anonymize;

use Craft;
use craft\base\FieldInterface;
use craft\db\Query;
use craft\db\Table;
use craft\elements\Address;
use craft\elements\Asset;
use craft\elements\Category;
use craft\elements\ContentBlock;
use craft\elements\Entry;
use craft\elements\GlobalSet;
use craft\elements\Tag;
use craft\elements\User;
use craft\fields\BaseRelationField;
use craft\models\FieldLayout;
use yii\base\InvalidConfigException;
use yii\db\Connection;
use yii\db\Expression;

/**
 * The config, worked out against this database: which content keys of which
 * field layout get which strategy, in every section an entry type is used in;
 * who and what is kept; what every table gets. All of it read before the dump
 * starts, in the dump's own snapshot: while a table streams, the connection
 * takes no other query.
 *
 * Strict (the default) refuses what has no destination: a field that can
 * hold free text, the title of an element, the filename of an asset, a
 * table, an element type. So a field added later stops the next dump instead
 * of going out as it is. Names the config uses must exist, and a rule must
 * hit something: a typo is an error, not a rule that quietly does nothing.
 */
final class Plan
{
    // What an element is, as the dump knows it.
    public const ENTRY = 1;
    public const USER = 2;
    public const ASSET = 3;
    public const GLOBAL_SET = 4;
    public const ADDRESS = 5;
    public const CATEGORY = 6;
    public const TAG = 7;
    public const CONTENT_BLOCK = 8;
    public const OTHER = 9;

    public const KINDS = [
        Entry::class => self::ENTRY,
        User::class => self::USER,
        Asset::class => self::ASSET,
        GlobalSet::class => self::GLOBAL_SET,
        Address::class => self::ADDRESS,
        Category::class => self::CATEGORY,
        Tag::class => self::TAG,
        ContentBlock::class => self::CONTENT_BLOCK,
    ];

    /** Tables the Anonymizer reads and writes itself. */
    public const BUILT_IN = ['elements', 'entries', 'elements_sites', 'searchindex', 'assets', 'assets_sites', self::MARKER];

    /** The table a dump ends with, saying what the copy is; a source that has one, does not pass it on. */
    public const MARKER = 'anonymized';

    /** Tables with a built-in meaning that take only `columns` (the craft preset sets them). */
    public const ELEMENT_TABLES = ['users', 'addresses'];

    /** Field types that hold no free text: an option, a switch, a relation, nested elements (classified on their own). */
    private const SAFE_TYPES = [
        'craft\fields\Dropdown', 'craft\fields\RadioButtons', 'craft\fields\Checkboxes', 'craft\fields\MultiSelect',
        'craft\fields\ButtonGroup', 'craft\fields\Lightswitch', 'craft\fields\Color', 'craft\fields\Country',
        'craft\fields\Icon', 'craft\fields\Range', 'craft\fields\Time', 'craft\fields\Money',
        'craft\fields\BaseRelationField', 'craft\fields\Matrix', 'craft\fields\Addresses', 'craft\fields\ContentBlock',
    ];

    /** Field types whose search keywords come without a query (a relation's would load its targets). */
    private const KEYWORD_TYPES = [
        'craft\fields\PlainText', 'craft\fields\Email', 'craft\fields\Url', 'craft\fields\Number', 'craft\fields\Money',
        'craft\fields\Date', 'craft\fields\Time', 'craft\fields\Dropdown', 'craft\fields\RadioButtons',
        'craft\fields\Checkboxes', 'craft\fields\MultiSelect', 'craft\fields\ButtonGroup', 'craft\fields\Lightswitch',
        'craft\fields\Color', 'craft\fields\Country', 'craft\fields\Table', 'craft\fields\Range', 'craft\fields\Icon',
    ];

    /** Field types whose content is the text itself, written as it is made. */
    private const TEXT_TYPES = ['craft\fields\PlainText', 'craft\fields\Email', 'craft\fields\Url', 'craft\ckeditor\Field', 'craft\redactor\Field'];

    /** @var list<string> what stops the dump */
    public array $errors = [];

    /** @var list<string> */
    public array $warnings = [];

    public readonly string $prefix;
    public readonly bool $strict;
    public readonly bool $tableCollations;

    /** @var array<int, string> site id => language */
    public array $languages = [];

    /**
     * @var array<int, array{id: int, owner: string, kind: int, hasTitle: bool, titleFormat: ?string, fields: array<string, array{uid: string, handle: string, field: FieldInterface}>}>
     */
    public array $layouts = [];

    /** @var array<int, string> section id => handle */
    public array $sections = [];

    /** @var array<int, string> volume id => handle */
    public array $volumes = [];

    public ?int $userLayoutId = null;

    /** @var array<int, true> */
    public array $keptUsers = [];

    /** @var list<string> addresses the PII scanner lets pass: the kept users' and `scan.allowEmails` */
    public array $keptEmails = [];

    /** @var list<string> domains the PII scanner lets pass: the kept users' and `scan.allowDomains` */
    public array $scanDomains = [];

    /** Whether e-mail addresses the PII scanner finds stop the dump (`scan.fail`). */
    public bool $scanFail = false;

    /** @var array<int, true> elements kept by id, with their drafts, revisions, nested elements and addresses */
    public array $keptIds = [];

    /** @var array<int, true> */
    public array $keptLayouts = [];

    /** @var array<int, true> */
    public array $keptSections = [];

    /** @var array<int, true> */
    public array $keptVolumes = [];

    /** @var array<string, true> element classes kept whole */
    public array $keptTypes = [];

    /** @var array<string, true> full table names */
    public array $keptTables = [];

    /**
     * @var array<string, array> "layoutId:sectionId" => what happens to the elements_sites rows of such elements
     * @see context()
     */
    public array $contexts = [];

    /** @var array<int, array{filename: Strategy, title: Strategy, alt: Strategy, stem: string}> volume id (0: none) => rule */
    public array $volumeRules = [];

    /**
     * @var array<string, array{empty: bool, columns?: array<string, Strategy>, keepRows?: mixed, dropRows?: mixed, key?: list<string>, types?: array<string, string>}>
     * full table name => rule
     */
    public array $tables = [];

    /** @var array<int, int> element => the element whose seed it takes (`seed` in a rule) */
    public array $relationSeeds = [];

    /** @var array<int, true> layouts some context changes: their drafts need the seed of their canonical */
    public array $changing = [];

    /** @var array<string, array> element class => its rule (`elements['elementType:…']`), for elements of plugins */
    public array $elementTypeRules = [];

    /** @var array<string, list<Handler>> full table name => the handlers that rewrite it, in the order they run */
    public array $handled = [];

    /** @var array<string, list<string>> full table name => the columns its handlers read */
    public array $handledColumns = [];

    public readonly Values $values;

    /** @var list<string> the element classes the elements table has */
    private array $elementClasses = [];

    /**
     * @var array{layouts: int, sections: int, volumes: int, classes: int} how many of each the database has,
     * soft-deleted ones too: how wide the Anonymizer's maps need to be
     */
    public array $counts = ['layouts' => 0, 'sections' => 0, 'volumes' => 0, 'classes' => 0];

    /** @var array{elements: int, entries: int, assets: int} the highest ids: how long the Anonymizer's maps are */
    public array $highest = ['elements' => 0, 'entries' => 0, 'assets' => 0];

    /** @var array<string, int> owner ("entryType:x") => layout id */
    private array $owners = [];

    /** @var array<int, list<int>> section id => layout ids of its entry types */
    private array $sectionLayouts = [];

    /** @var array<int, list<string>> section id => its uri formats */
    private array $sectionUriFormats = [];

    /** @var array<int, true> layouts of entry types that nest in other elements */
    private array $nestedLayouts = [];

    /** @var array<int, int> volume id => layout id */
    private array $volumeLayouts = [];

    /** @var array<int, array> */
    private array $sectionRules = [];

    /** @var array<int, array> */
    private array $layoutRules = [];

    /** @var array<int, array> volume id => rule */
    private array $volumeRuleSpecs = [];

    /** @var array<string, Strategy> */
    private array $fieldRules = [];

    /** @var array<string, Strategy> field class => strategy */
    private array $typeRules = [];

    /** @var array<string, array{columns: array<string, string>, key: list<string>}> full table name => columns (name => data type) and primary key */
    private array $dbTables = [];

    private function __construct(private readonly Config $config, private readonly Connection $db)
    {
        $this->prefix = $db->tablePrefix;
        $this->strict = $config->strict();
        $this->tableCollations = $config->tableCollations();
        $this->values = new Values();
    }

    public static function build(Config $config, Connection $db): self
    {
        $plan = new self($config, $db);
        $plan->collectSchema();
        $plan->resolveKeep();
        $plan->resolveRules();
        $plan->resolveContexts();
        $plan->resolveVolumes();
        $plan->resolveHandlers();
        $plan->resolveTables();
        $plan->resolveRelationSeeds();
        $plan->checkElementTypes();

        return $plan;
    }

    public function context(int $layoutId, int $sectionId): ?array
    {
        return $this->contexts["{$layoutId}:{$sectionId}"] ?? null;
    }

    public function keywordSafe(FieldInterface $field): bool
    {
        return self::isA($field, self::KEYWORD_TYPES);
    }

    public function isText(FieldInterface $field): bool
    {
        return self::isA($field, self::TEXT_TYPES);
    }

    /**
     * What check prints: per context that changes something, what it changes.
     *
     * @return list<string>
     */
    public function describe(): array
    {
        $lines = [];
        foreach ($this->contexts as $context) {
            if ($context['kept'] || !$context['changes']) {
                continue;
            }
            $parts = [];
            if ($context['title'] !== null) {
                $parts[] = 'titel: ' . $context['title']->label();
            } elseif ($context['titleFormat'] !== null) {
                $parts[] = "titel: doorgerekend uit '{$context['titleFormat']}'";
            }
            foreach ($context['fields'] as $field) {
                $parts[] = "{$field['handle']}: " . $field['strategy']->label();
            }
            $lines[] = "{$context['label']}" . ($context['seedField'] !== null ? ' (seed van een relatie)' : '') . "\n      " . implode(', ', $parts);
        }
        foreach ($this->volumeRules as $volumeId => $rule) {
            if ($volumeId === 0) {
                continue;
            }
            $lines[] = "volume:{$this->volumes[$volumeId]}\n      bestandsnaam: {$rule['filename']->label()}, titel: {$rule['title']->label()}, alt: {$rule['alt']->label()}";
        }
        foreach ($this->elementTypeRules as $class => $rule) {
            $lines[] = "elementType:{$class}\n      titel: " . ($rule['title']?->label() ?? 'keep') . ', zoekwoorden behalve de titel leeg';
        }
        foreach ($this->handled as $table => $handlers) {
            $lines[] = 'tabel ' . substr($table, strlen($this->prefix)) . "\n      door " . implode(', ', array_map(fn(Handler $handler) => $handler::class, $handlers));
        }

        return $lines;
    }

    // Schema
    // =========================================================================

    private function collectSchema(): void
    {
        foreach (Craft::$app->getSites()->getAllSites(true) as $site) {
            $this->languages[$site->id] = $site->language;
        }

        $entries = Craft::$app->getEntries();
        foreach ($entries->getAllEntryTypes() as $type) {
            $this->addLayout($type->getFieldLayout(), "entryType:{$type->handle}", self::ENTRY, $type->hasTitleField, $type->hasTitleField ? null : ($type->titleFormat ?: null));
        }
        foreach ($entries->getAllSections() as $section) {
            $this->sections[$section->id] = $section->handle;
            foreach ($section->getEntryTypes() as $type) {
                $this->sectionLayouts[$section->id][] = (int)$type->fieldLayoutId;
            }
            foreach ($section->getSiteSettings() as $settings) {
                if ($settings->hasUrls && $settings->uriFormat) {
                    $this->sectionUriFormats[$section->id][] = $settings->uriFormat;
                }
            }
        }
        foreach (Craft::$app->getVolumes()->getAllVolumes() as $volume) {
            $this->volumes[$volume->id] = $volume->handle;
            $this->volumeLayouts[$volume->id] = (int)$volume->fieldLayoutId;
            $this->addLayout($volume->getFieldLayout(), "volume:{$volume->handle}", self::ASSET, true);
        }
        foreach (Craft::$app->getGlobals()->getAllSets() as $set) {
            $this->addLayout($set->getFieldLayout(), "globalSet:{$set->handle}", self::GLOBAL_SET, false);
        }
        foreach (Craft::$app->getCategories()->getAllGroups() as $group) {
            $this->addLayout($group->getFieldLayout(), "categoryGroup:{$group->handle}", self::CATEGORY, true);
        }
        foreach (Craft::$app->getTags()->getAllTagGroups() as $group) {
            $this->addLayout($group->getFieldLayout(), "tagGroup:{$group->handle}", self::TAG, true);
        }
        $userLayout = Craft::$app->getFields()->getLayoutByType(User::class);
        $this->userLayoutId = $userLayout->id ? (int)$userLayout->id : null;
        $this->addLayout($userLayout, 'user', self::USER, false);
        $this->addLayout(Craft::$app->getAddresses()->getFieldLayout(), 'address', self::ADDRESS, true);

        foreach (Craft::$app->getFields()->getAllFields() as $field) {
            if ($field instanceof \craft\fields\ContentBlock) {
                $this->addLayout($field->getFieldLayout(), "contentBlock:{$field->handle}", self::CONTENT_BLOCK, false);
            }
            // Matrix, and CKEditor with nested entries.
            if (method_exists($field, 'getEntryTypes')) {
                foreach ($field->getEntryTypes() as $type) {
                    $this->nestedLayouts[(int)$type->fieldLayoutId] = true;
                }
            }
        }
        foreach (Craft::$app->getFields()->getAllLayouts() as $layout) {
            if ($layout->id && !isset($this->layouts[$layout->id])) {
                $this->addLayout($layout, "layout:{$layout->id}", self::OTHER, false);
            }
        }
        $this->elementClasses = (new Query())->select(['type'])->distinct()->from(Table::ELEMENTS)->column($this->db);
        $this->counts = [
            'layouts' => (int)(new Query())->from(Table::FIELDLAYOUTS)->count('*', $this->db),
            'sections' => (int)(new Query())->from(Table::SECTIONS)->count('*', $this->db),
            'volumes' => (int)(new Query())->from(Table::VOLUMES)->count('*', $this->db),
            'classes' => count($this->elementClasses),
        ];
        $this->highest = [
            'elements' => (int)(new Query())->from(Table::ELEMENTS)->max('id', $this->db),
            'entries' => (int)(new Query())->from(Table::ENTRIES)->max('id', $this->db),
            'assets' => (int)(new Query())->from(Table::ASSETS)->max('id', $this->db),
        ];

        $columns = (new Query())
            ->select(['t' => 'table_name', 'c' => 'column_name', 'd' => 'data_type', 'n' => 'is_nullable'])
            ->from('information_schema.columns')
            ->where(['table_schema' => new Expression('DATABASE()')])
            ->andWhere(['or', ['generation_expression' => null], ['generation_expression' => '']])
            ->orderBy(['table_name' => SORT_ASC, 'ordinal_position' => SORT_ASC])
            ->all($this->db);
        foreach ($columns as $row) {
            $this->dbTables[$row['t']]['columns'][$row['c']] = strtolower($row['d']);
            $this->dbTables[$row['t']]['nullable'][$row['c']] = $row['n'] === 'YES';
            $this->dbTables[$row['t']]['key'] ??= [];
        }
        $keys = (new Query())
            ->select(['t' => 'table_name', 'c' => 'column_name'])
            ->from('information_schema.statistics')
            ->where(['table_schema' => new Expression('DATABASE()'), 'index_name' => 'PRIMARY'])
            ->orderBy(['table_name' => SORT_ASC, 'seq_in_index' => SORT_ASC])
            ->all($this->db);
        foreach ($keys as $row) {
            $this->dbTables[$row['t']]['key'][] = $row['c'];
        }
    }

    private function addLayout(?FieldLayout $layout, string $owner, int $kind, bool $hasTitle, ?string $titleFormat = null): void
    {
        if (!$layout?->id) {
            // Never saved: nothing uses it.
            return;
        }
        $id = (int)$layout->id;
        $this->owners[$owner] = $id;
        if (isset($this->layouts[$id])) {
            return;
        }
        $fields = [];
        foreach ($layout->getCustomFieldElements() as $element) {
            $field = $element->getField();
            $fields[$field->handle] = ['uid' => $element->uid, 'handle' => $field->handle, 'field' => $field];
        }
        $this->layouts[$id] = [
            'id' => $id,
            'owner' => $owner,
            'kind' => $kind,
            'class' => (string)$layout->type,
            'hasTitle' => $hasTitle,
            'titleFormat' => $titleFormat,
            'fields' => $fields,
        ];
    }

    // Keep
    // =========================================================================

    private function resolveKeep(): void
    {
        $keep = $this->config->data['keep'] ?? [];

        foreach ($keep['elements'] ?? [] as $selector) {
            $preset = $this->config->isPresetOnly("keep.elements.{$selector}");
            $target = $this->select((string)$selector, 'keep.elements', $preset);
            if ($target === null) {
                continue;
            }
            foreach ($target['layouts'] as $id) {
                $this->keptLayouts[$id] = true;
            }
            foreach ($target['sections'] as $id) {
                $this->keptSections[$id] = true;
            }
            foreach ($target['volumes'] as $id) {
                $this->keptVolumes[$id] = true;
            }
            foreach ($target['ids'] as $id) {
                $this->keptIds[$id] = true;
            }
            foreach ($target['types'] as $class) {
                $this->keptTypes[$class] = true;
            }
        }

        $this->resolveKeptUsers($keep['users'] ?? []);
        foreach (array_keys($this->keptUsers) as $id) {
            $this->keptIds[$id] = true;
        }
        $this->expandKeptIds();
        if ($this->keptUsers) {
            $this->keptEmails = array_values(array_filter((new Query())
                ->select(['email'])
                ->from(Table::USERS)
                ->where(['id' => array_keys($this->keptUsers)])
                ->column($this->db)));
        }
        $this->keptEmails = array_merge($this->keptEmails, $this->config->data['scan']['allowEmails'] ?? []);
        $this->scanDomains = array_merge($keep['users']['emailDomains'] ?? [], $this->config->data['scan']['allowDomains'] ?? []);
        $this->scanFail = $this->config->data['scan']['fail'] ?? false;

        foreach ($keep['tables'] ?? [] as $name) {
            $found = $this->tables((string)$name);
            foreach ($found as $full) {
                $this->keptTables[$full] = true;
            }
            if (!$found && !$this->config->isPresetOnly("keep.tables.{$name}")) {
                $this->errors[] = "keep.tables: tabel {$name} bestaat niet";
            }
        }
    }

    private function resolveKeptUsers(array $criteria): void
    {
        $domains = array_map(fn($d) => strtolower(ltrim(trim((string)$d), '@')), $criteria['emailDomains'] ?? []);
        $emails = array_map(fn($e) => strtolower(trim((string)$e)), $criteria['emails'] ?? []);
        $usernames = array_map(fn($u) => strtolower(trim((string)$u)), $criteria['usernames'] ?? []);
        $ids = array_map('intval', $criteria['ids'] ?? []);
        $hits = [];

        $all = isset($this->keptLayouts[(int)$this->userLayoutId]) && $this->userLayoutId !== null;
        // Only the candidates: a site with a hundred thousand members keeps a handful.
        // The test below is the one that counts; this only narrows.
        $candidates = ['or'];
        foreach ($domains as $domain) {
            $candidates[] = ['like', 'email', "@{$domain}"];
        }
        if ($emails) {
            $candidates[] = ['email' => $emails];
        }
        if ($usernames) {
            $candidates[] = ['username' => $usernames];
        }
        if ($ids) {
            $candidates[] = ['id' => $ids];
        }
        if ($this->keptIds) {
            $candidates[] = ['id' => array_keys($this->keptIds)];
        }
        $query = (new Query())->select(['id', 'email', 'username'])->from(Table::USERS);
        if (!$all) {
            $query->where(count($candidates) > 1 ? $candidates : '0 = 1');
        }
        foreach ($query->all($this->db) as $row) {
            $id = (int)$row['id'];
            $email = strtolower(trim((string)$row['email']));
            $username = strtolower(trim((string)$row['username']));
            $why = [];
            foreach ($domains as $domain) {
                if ($email !== '' && str_ends_with($email, "@{$domain}")) {
                    $why[] = "emailDomains: {$domain}";
                }
            }
            if ($email !== '' && in_array($email, $emails, true)) {
                $why[] = "emails: {$email}";
            }
            if ($username !== '' && in_array($username, $usernames, true)) {
                $why[] = "usernames: {$username}";
            }
            if (in_array($id, $ids, true)) {
                $why[] = "ids: {$id}";
            }
            if ($why || $all || isset($this->keptIds[$id])) {
                $this->keptUsers[$id] = true;
            }
            foreach ($why as $reason) {
                $hits[$reason] = true;
            }
        }

        foreach ($criteria['groups'] ?? [] as $handle) {
            $group = Craft::$app->getUserGroups()->getGroupByHandle((string)$handle);
            if (!$group) {
                $this->errors[] = "keep.users.groups: gebruikersgroep {$handle} bestaat niet";
                continue;
            }
            $members = (new Query())->select(['userId'])->from(Table::USERGROUPS_USERS)->where(['groupId' => $group->id])->column($this->db);
            foreach ($members as $id) {
                $this->keptUsers[(int)$id] = true;
            }
            if (!$members) {
                $this->warnings[] = "keep.users.groups: niemand in {$handle}";
            }
        }

        foreach ($domains as $domain) {
            if (!isset($hits["emailDomains: {$domain}"])) {
                $this->warnings[] = "keep.users.emailDomains: niemand op @{$domain}";
            }
        }
        foreach (['emails' => $emails, 'usernames' => $usernames, 'ids' => $ids] as $key => $values) {
            foreach ($values as $value) {
                if (!isset($hits["{$key}: {$value}"])) {
                    $this->warnings[] = "keep.users.{$key}: geen gebruiker {$value}";
                }
            }
        }
    }

    /** Elements kept by id take their drafts, revisions, nested elements and addresses with them. */
    private function expandKeptIds(): void
    {
        $new = array_keys($this->keptIds);
        while ($new) {
            $found = array_merge(
                (new Query())->select(['id'])->from(Table::ELEMENTS)->where(['canonicalId' => $new])->column($this->db),
                (new Query())->select(['elementId'])->from(Table::ELEMENTS_OWNERS)->where(['ownerId' => $new])->column($this->db),
                (new Query())->select(['id'])->from(Table::ADDRESSES)->where(['primaryOwnerId' => $new])->column($this->db),
            );
            $new = [];
            foreach ($found as $id) {
                if (!isset($this->keptIds[(int)$id])) {
                    $this->keptIds[(int)$id] = true;
                    $new[] = (int)$id;
                }
            }
        }
    }

    /**
     * What a selector stands for: `section:handle`, `entryType:handle`,
     * `volume:handle`, `globalSet:handle`, `categoryGroup:handle`,
     * `tagGroup:handle`, `contentBlock:handle`, `user`, `address`,
     * `layout:id`, `elementType:class`, or one element: `entry:id`,
     * `asset:id`, `user:id`, `element:id`.
     *
     * @return array{layouts: list<int>, sections: list<int>, volumes: list<int>, ids: list<int>, types: list<string>}|null
     */
    private function select(string $selector, string $where, bool $preset = false): ?array
    {
        [$type, $name] = array_pad(explode(':', $selector, 2), 2, null);
        $target = ['layouts' => [], 'sections' => [], 'volumes' => [], 'ids' => [], 'types' => []];
        $missing = null;
        if ($type === 'section') {
            $id = array_search($name, $this->sections, true);
            if ($id === false) {
                $missing = 'die sectie bestaat niet';
            } else {
                $target['sections'][] = $id;
            }
        } elseif ($type === 'volume') {
            $id = array_search($name, $this->volumes, true);
            if ($id === false) {
                $missing = 'dat volume bestaat niet';
            } else {
                $target['volumes'][] = $id;
                if ($this->volumeLayouts[$id] ?? 0) {
                    $target['layouts'][] = $this->volumeLayouts[$id];
                }
            }
        } elseif (in_array($type, ['entryType', 'globalSet', 'categoryGroup', 'tagGroup', 'contentBlock'], true)
            || (in_array($type, ['user', 'address'], true) && $name === null)) {
            if (isset($this->owners[$selector])) {
                $target['layouts'][] = $this->owners[$selector];
            } else {
                $missing = 'die bestaat niet, of heeft hier geen veldindeling';
            }
        } elseif (in_array($type, ['entry', 'asset', 'user', 'element', 'address'], true)) {
            $class = ['entry' => Entry::class, 'asset' => Asset::class, 'user' => User::class, 'address' => Address::class][$type] ?? null;
            if (!ctype_digit((string)$name)) {
                $missing = 'verwacht een id';
            } elseif (!(new Query())->from(Table::ELEMENTS)->where(['id' => (int)$name] + ($class ? ['type' => $class] : []))->exists($this->db)) {
                $missing = 'dat element bestaat niet';
            } else {
                $target['ids'][] = (int)$name;
            }
        } elseif ($type === 'layout') {
            if (isset($this->layouts[(int)$name])) {
                $target['layouts'][] = (int)$name;
            } else {
                $missing = 'die veldindeling bestaat niet';
            }
        } elseif ($type === 'elementType' && $name !== null && $name !== '') {
            // A plugin's element classes, from the elements and from the field layouts;
            // `prefix\*` for all of a namespace. Craft's own have their own selectors.
            $classes = array_unique(array_merge($this->elementClasses, array_column($this->layouts, 'class')));
            $matched = array_values(array_filter($classes, fn(string $class) => !isset(self::KINDS[$class])
                && (str_ends_with($name, '*') ? str_starts_with($class, substr($name, 0, -1)) : $class === $name)));
            if (!$matched) {
                $missing = 'geen elementen of veldindelingen van dat type';
            } else {
                $target['types'] = $matched;
                foreach ($this->layouts as $id => $layout) {
                    if (in_array($layout['class'], $matched, true)) {
                        $target['layouts'][] = $id;
                    }
                }
            }
        } else {
            $missing = 'onbekende selector (section:, entryType:, volume:, globalSet:, categoryGroup:, tagGroup:, contentBlock:, user, address, layout:, elementType:, entry:id, asset:id, user:id, element:id)';
        }
        if ($missing !== null) {
            if (!$preset) {
                $this->errors[] = "{$where}: {$selector}: {$missing}";
            }

            return null;
        }

        return $target;
    }

    // Rules
    // =========================================================================

    private function resolveRules(): void
    {
        $handles = [];
        foreach ($this->layouts as $layout) {
            foreach ($layout['fields'] as $handle => $field) {
                $handles[$handle] = true;
            }
        }
        foreach ($this->config->data['fields'] ?? [] as $handle => $spec) {
            $strategy = $this->strategy($spec, "fields.{$handle}");
            if (!isset($handles[$handle])) {
                $this->errors[] = "fields.{$handle}: geen veldindeling heeft een veld {$handle}";
            } elseif ($strategy !== null) {
                $this->fieldRules[$handle] = $strategy;
            }
        }
        foreach ($this->config->data['fieldTypes'] ?? [] as $class => $spec) {
            $strategy = $this->strategy($spec, "fieldTypes.{$class}");
            if ($strategy !== null) {
                $this->typeRules[$class] = $strategy;
            }
        }

        foreach ($this->config->data['elements'] ?? [] as $selector => $rule) {
            $where = "elements.{$selector}";
            $preset = $this->config->isPresetOnly($where);
            $target = $this->select((string)$selector, 'elements', $preset);
            if ($target === null) {
                continue;
            }
            if ($target['ids']) {
                $this->errors[] = "{$where}: losse elementen kunnen alleen in keep.elements";
                continue;
            }
            $layouts = $target['layouts'];
            foreach ($target['sections'] as $sectionId) {
                array_push($layouts, ...($this->sectionLayouts[$sectionId] ?? []));
            }
            $parsed = $this->parseRule($rule, $where, $layouts, (bool)$target['volumes']);
            foreach ($target['types'] as $class) {
                // A plugin's elements can be titled with anything: say what happens to it.
                if (!array_key_exists('title', $rule)) {
                    $this->unclassified("{$where}: de titel heeft geen bestemming ('keep' mag ook)");
                }
                $this->elementTypeRules[$class] = $parsed;
            }
            foreach ($target['sections'] as $sectionId) {
                $this->sectionRules[$sectionId] = $parsed;
            }
            foreach ($target['volumes'] as $volumeId) {
                $this->volumeRuleSpecs[$volumeId] = $parsed;
            }
            foreach ($target['layouts'] as $layoutId) {
                $this->layoutRules[$layoutId] = $parsed;
            }
        }
    }

    /**
     * @param list<int> $layouts the layouts the rule is for: a field it names must be in one of them
     */
    private function parseRule(array $rule, string $where, array $layouts, bool $volume): array
    {
        $parsed = ['where' => $where, 'title' => null, 'fields' => [], 'star' => null, 'seed' => $rule['seed'] ?? null, 'filename' => null, 'alt' => null];
        if (array_key_exists('title', $rule)) {
            $parsed['title'] = $this->strategy($rule['title'], "{$where}.title");
        }
        foreach (['filename', 'alt'] as $part) {
            if (array_key_exists($part, $rule)) {
                if (!$volume) {
                    $this->errors[] = "{$where}.{$part}: alleen voor een volume";
                    continue;
                }
                $parsed[$part] = $this->strategy($rule[$part], "{$where}.{$part}");
            }
        }
        foreach ($rule['fields'] ?? [] as $handle => $spec) {
            $strategy = $this->strategy($spec, "{$where}.fields.{$handle}");
            if ($handle === '*') {
                $parsed['star'] = $strategy;
                continue;
            }
            $exists = false;
            foreach ($layouts as $layoutId) {
                $exists = $exists || isset($this->layouts[$layoutId]['fields'][$handle]);
            }
            if (!$exists) {
                $this->errors[] = "{$where}.fields.{$handle}: dat veld zit niet in de veldindeling";
            } elseif ($strategy !== null) {
                $parsed['fields'][$handle] = $strategy;
            }
        }

        return $parsed;
    }

    private function strategy(mixed $spec, string $where): ?Strategy
    {
        try {
            return Strategy::parse($spec, $where);
        } catch (InvalidConfigException $e) {
            $this->errors[] = $e->getMessage();

            return null;
        }
    }

    // Contexts
    // =========================================================================

    private function resolveContexts(): void
    {
        $pairs = [];
        foreach ($this->sectionLayouts as $sectionId => $layoutIds) {
            foreach ($layoutIds as $layoutId) {
                $pairs["{$layoutId}:{$sectionId}"] = [$layoutId, $sectionId];
            }
        }
        foreach (array_keys($this->nestedLayouts) as $layoutId) {
            $pairs["{$layoutId}:0"] = [$layoutId, 0];
        }
        foreach ($this->layouts as $layoutId => $layout) {
            if ($layout['kind'] !== self::ENTRY) {
                $pairs["{$layoutId}:0"] = [$layoutId, 0];
            }
        }

        // What the entries outside the trash actually use, whatever the
        // schema says; the trash is emptied anyway, drafts of trashed entries
        // included.
        $used = (new Query())
            ->select(['sectionId' => 'e.sectionId', 'layoutId' => 'el.fieldLayoutId', 'n' => 'COUNT(*)'])
            ->from(['e' => Table::ENTRIES])
            ->innerJoin(['el' => Table::ELEMENTS], '[[el.id]] = [[e.id]]')
            ->leftJoin(['c' => Table::ELEMENTS], '[[c.id]] = [[el.canonicalId]]')
            ->where(['el.dateDeleted' => null])
            ->andWhere(['or', ['c.id' => null], ['c.dateDeleted' => null]])
            ->groupBy(['e.sectionId', 'el.fieldLayoutId'])
            ->all($this->db);
        $inData = [];
        foreach ($used as $row) {
            $layoutId = (int)$row['layoutId'];
            $sectionId = (int)$row['sectionId'];
            if (!isset($this->layouts[$layoutId])) {
                $this->errors[] = "{$row['n']} entries buiten de prullenbak hebben een veldindeling ({$layoutId}) die niet meer bestaat";
                continue;
            }
            $pairs["{$layoutId}:{$sectionId}"] = [$layoutId, $sectionId];
            $inData["{$layoutId}:{$sectionId}"] = true;
        }

        foreach ($pairs as $key => [$layoutId, $sectionId]) {
            if (isset($this->layouts[$layoutId])) {
                $this->contexts[$key] = $this->buildContext($layoutId, $sectionId) + ['inData' => isset($inData[$key])];
                if ($this->contexts[$key]['changes']) {
                    $this->changing[$layoutId] = true;
                }
            }
        }
    }

    private function buildContext(int $layoutId, int $sectionId): array
    {
        $layout = $this->layouts[$layoutId];
        $label = $layout['owner'] . ($sectionId ? ' in section:' . ($this->sections[$sectionId] ?? "#{$sectionId}") : ($layout['kind'] === self::ENTRY ? ' (genest)' : ''));
        $context = [
            'label' => $label,
            'kind' => $layout['kind'],
            'layoutId' => $layoutId,
            'kept' => isset($this->keptLayouts[$layoutId]) || ($sectionId && isset($this->keptSections[$sectionId])),
            'fields' => [],
            'keys' => [],
            'handles' => [],
            'title' => null,
            'titleFormat' => null,
            'searchable' => [],
            'changes' => false,
            'seedField' => null,
            'seedUid' => null,
        ];
        foreach ($layout['fields'] as $handle => $field) {
            $context['keys'][$field['uid']] = true;
            $context['handles'][$handle] = $field['uid'];
        }
        if ($context['kept']) {
            return $context;
        }

        $sectionRule = $sectionId ? ($this->sectionRules[$sectionId] ?? null) : null;
        $layoutRule = $this->layoutRules[$layoutId] ?? null;

        foreach ($layout['fields'] as $handle => $field) {
            $strategy = $this->fieldStrategy($field, $sectionRule, $layoutRule, $label);
            if (!$strategy->isKeep()) {
                if ($strategy->kind === Strategy::PLACEHOLDER) {
                    $this->errors[] = "{$strategy->where}: placeholder is alleen voor de bestandsnaam en titel van assets";
                    continue;
                }
                $context['fields'][$field['uid']] = ['strategy' => $strategy, 'handle' => $handle, 'field' => $field['field']];
            }
            if ($field['field']->searchable) {
                $context['searchable'][$field['field']->id][] = [$field['field'], $field['uid']];
            }
        }

        // Asset titles go with the volume's rule (resolveVolumes()), a plugin
        // element's with its element type's (elementTypeRules).
        if ($layout['kind'] !== self::ASSET && $layout['kind'] !== self::OTHER) {
            $this->resolveTitle($context, $layout, $sectionRule, $layoutRule);
        }

        $seed = $sectionRule['seed'] ?? $layoutRule['seed'] ?? null;
        if ($seed !== null) {
            $field = $layout['fields'][$seed] ?? null;
            if (!$field || !$field['field'] instanceof BaseRelationField) {
                $this->errors[] = ($sectionRule['where'] ?? $layoutRule['where']) . ".seed: {$seed} is geen relatieveld van {$label}";
            } else {
                $context['seedField'] = (int)$field['field']->id;
                $context['seedUid'] = $field['uid'];
            }
        }

        $context['changes'] = $context['fields'] || $context['title'] !== null || $context['titleFormat'] !== null;

        // A uri gets the new slug where the old one was in it; one made of
        // something else that changes would keep the real value.
        if ($context['changes']) {
            $changed = array_column($context['fields'], 'handle');
            if ($context['title'] !== null || $context['titleFormat'] !== null) {
                $changed[] = 'title';
            }
            foreach (array_unique($this->sectionUriFormats[$sectionId] ?? []) as $format) {
                preg_match_all('/\{([^}]*)\}/', $format, $expressions);
                preg_match_all('/\w+/', implode(' ', $expressions[1]), $words);
                $used = array_intersect($changed, $words[0]);
                if ($used) {
                    $this->unclassified("{$label}: de uri komt uit '{$format}', met " . implode(', ', $used) . "; het script zet alleen de slug in uri's om");
                }
            }
        }

        return $context;
    }

    private function fieldStrategy(array $field, ?array $sectionRule, ?array $layoutRule, string $label): Strategy
    {
        $handle = $field['handle'];
        $a = $sectionRule['fields'][$handle] ?? null;
        $b = $layoutRule['fields'][$handle] ?? null;
        if ($a !== null && $b !== null && $a !== $b) {
            $this->errors[] = "{$label}: veld {$handle} heeft een regel in {$sectionRule['where']} én in {$layoutRule['where']}; kies er één";
        }
        $strategy = $a ?? $b ?? $sectionRule['star'] ?? $layoutRule['star'] ?? $this->fieldRules[$handle] ?? $this->typeRule($field['field']);
        if ($strategy !== null) {
            return $strategy;
        }
        if (!self::isA($field['field'], self::SAFE_TYPES)) {
            $this->unclassified("{$label}: veld {$handle} (" . $field['field']::displayName() . ') heeft geen bestemming');
        }

        return Strategy::keep();
    }

    private function resolveTitle(array &$context, array $layout, ?array $sectionRule, ?array $layoutRule): void
    {
        $title = $sectionRule['title'] ?? $layoutRule['title'] ?? null;
        $label = $context['label'];
        if ($title !== null) {
            if (!$layout['hasTitle'] && $layout['titleFormat'] === null) {
                $this->errors[] = "{$title->where}: {$label} heeft geen titel";
            } elseif ($title->kind === Strategy::PLACEHOLDER) {
                $this->errors[] = "{$title->where}: placeholder is alleen voor assets";
            } elseif (!$title->isKeep()) {
                $context['title'] = $title;
            }

            return;
        }
        if ($layout['titleFormat'] !== null) {
            // An entry type that makes its titles: worked out again if it
            // uses a field that changes, as long as it only names fields.
            $format = $layout['titleFormat'];
            $simple = (bool)preg_match('/^(?:[^{}]|\{\w+\})*$/', $format);
            preg_match_all('/\{(\w+)\}/', $format, $tokens);
            $changed = array_filter($context['fields'], fn($field) => in_array($field['handle'], $tokens[1], true));
            if (!$simple && $context['fields']) {
                $this->errors[] = "{$label}: de titel komt uit '{$format}', en dat kan ik niet doorrekenen; geef een title-regel";
            } elseif ($simple && $changed) {
                $context['titleFormat'] = $format;
            }

            return;
        }
        if ($layout['hasTitle']) {
            $this->unclassified("{$label}: de titel heeft geen bestemming");
        }
    }

    private function typeRule(FieldInterface $field): ?Strategy
    {
        foreach ($this->typeRules as $class => $strategy) {
            if ($field instanceof $class) {
                return $strategy;
            }
        }

        return null;
    }

    // Volumes
    // =========================================================================

    private function resolveVolumes(): void
    {
        // Assets without a volume (temporary uploads): nothing to keep.
        $this->volumeRules[0] = [
            'filename' => Strategy::parse(['placeholder' => 'upload'], 'assets zonder volume'),
            'title' => Strategy::parse(['placeholder' => 'upload'], 'assets zonder volume'),
            'alt' => Strategy::parse('null', 'assets zonder volume'),
            'stem' => 'upload',
        ];
        foreach ($this->volumes as $volumeId => $handle) {
            if (isset($this->keptVolumes[$volumeId]) || isset($this->keptLayouts[$this->volumeLayouts[$volumeId] ?? 0])) {
                $this->keptVolumes[$volumeId] = true;
                continue;
            }
            $spec = $this->volumeRuleSpecs[$volumeId] ?? null;
            $rule = [];
            foreach (['filename', 'title', 'alt'] as $part) {
                $strategy = $spec[$part] ?? null;
                if ($strategy === null) {
                    $this->unclassified("volume:{$handle}: {$part} heeft geen bestemming");
                    $strategy = Strategy::keep();
                }
                $allowed = match ($part) {
                    'filename' => [Strategy::KEEP, Strategy::PLACEHOLDER],
                    'alt' => [Strategy::KEEP, Strategy::NULL],
                    'title' => [Strategy::KEEP, Strategy::PLACEHOLDER, Strategy::FAKE, Strategy::FORMAT, Strategy::VALUE],
                };
                if (!in_array($strategy->kind, $allowed, true)) {
                    $this->errors[] = "{$strategy->where}: {$part} van een asset kan " . implode(', ', $allowed) . ' zijn';
                    $strategy = Strategy::keep();
                }
                $rule[$part] = $strategy;
            }
            $stem = $rule['filename']->kind === Strategy::PLACEHOLDER ? $rule['filename']->value
                : ($rule['title']->kind === Strategy::PLACEHOLDER ? $rule['title']->value : $handle);
            $this->volumeRules[$volumeId] = $rule + ['stem' => $stem];
        }
    }

    // Tables
    // =========================================================================

    private function resolveTables(): void
    {
        foreach ($this->config->data['tables'] ?? [] as $name => $rule) {
            $where = "tables.{$name}";
            $preset = $this->config->isPresetOnly($where);
            if (in_array($name, self::BUILT_IN, true)) {
                $this->errors[] = "{$where}: die tabel doet het script zelf";
                continue;
            }
            $found = $this->tables((string)$name);
            if (!$found && !$preset) {
                $this->errors[] = "{$where}: " . (str_contains((string)$name, '*') ? 'geen tabel past daarop' : 'die tabel bestaat niet');
            }
            foreach ($found as $full) {
                // A handler on the same table runs after this rule, on what it made of the row.
                if ($rule === 'empty') {
                    $this->tables[$full] = ['empty' => true];
                } else {
                    $this->tables[$full] = $this->tableRule($full, $rule, $where, $preset);
                }
            }
        }

        foreach (array_keys($this->dbTables) as $full) {
            $name = $this->shortName($full);
            if (!in_array($name, self::BUILT_IN, true) && !isset($this->tables[$full]) && !isset($this->keptTables[$full]) && !isset($this->handled[$full])) {
                $this->unclassified("tabel {$name} heeft geen bestemming" . $this->presetHint('table', $name));
            }
        }
    }

    private function tableRule(string $full, array $rule, string $where, bool $preset): array
    {
        $types = $this->dbTables[$full]['columns'];
        $nullable = $this->dbTables[$full]['nullable'];
        $columns = [];
        foreach ($rule['columns'] ?? [] as $column => $spec) {
            if (!isset($types[$column])) {
                if (!$preset) {
                    $this->errors[] = "{$where}.columns.{$column}: die kolom bestaat niet in " . $this->shortName($full);
                }
                continue;
            }
            $strategy = $this->strategy($spec, "{$where}.columns.{$column}");
            if ($strategy?->kind === Strategy::PLACEHOLDER) {
                $this->errors[] = "{$where}.columns.{$column}: placeholder is alleen voor assets";
            } elseif ($strategy?->kind === Strategy::NULL && !$nullable[$column]) {
                $this->errors[] = "{$where}.columns.{$column}: die kolom mag niet leeg zijn (NOT NULL); kies bijvoorbeeld ['value' => '']";
            } elseif ($strategy !== null && !$strategy->isKeep()) {
                $columns[$column] = $strategy;
            }
        }
        $keepRows = $rule['keepRows'] ?? [];
        if (is_array($keepRows)) {
            foreach ($keepRows as $column => $ref) {
                if (!isset($types[$column])) {
                    $this->errors[] = "{$where}.keepRows.{$column}: die kolom bestaat niet";
                } elseif (!is_array($ref) && $ref !== 'keptUsers' && $ref !== 'keptElements') {
                    $this->errors[] = "{$where}.keepRows.{$column}: 'keptUsers', 'keptElements' of een lijst waarden";
                }
            }
        }
        if (in_array($this->shortName($full), self::ELEMENT_TABLES, true) && ($keepRows || isset($rule['dropRows']))) {
            $this->errors[] = "{$where}: alleen columns; wie blijft, staat in keep";
        }

        return [
            'empty' => false,
            'columns' => $columns,
            'keepRows' => $keepRows,
            'dropRows' => $rule['dropRows'] ?? false,
            'key' => $this->dbTables[$full]['key'],
            'types' => $types,
            'nullable' => $nullable,
        ];
    }

    /**
     * The tables a name in the config stands for, by their full names: the
     * table itself, or every table a name with `*` matches (`fmc_*`).
     *
     * @return list<string>
     */
    private function tables(string $name): array
    {
        if (!str_contains($name, '*')) {
            $full = $this->tableName($name);

            return isset($this->dbTables[$full]) ? [$full] : [];
        }

        return array_values(array_filter(array_keys($this->dbTables), fn(string $full) => fnmatch($name, $this->shortName($full))));
    }

    private function tableName(string $name): string
    {
        return isset($this->dbTables[$this->prefix . $name]) || !isset($this->dbTables[$name]) ? $this->prefix . $name : $name;
    }

    private function shortName(string $full): string
    {
        return $this->prefix !== '' && str_starts_with($full, $this->prefix) ? substr($full, strlen($this->prefix)) : $full;
    }

    /** Which preset that is not on would have a destination for this table or element type: a hint for check. */
    private function presetHint(string $what, string $name): string
    {
        static $presets = null;
        if ($presets === null) {
            $presets = [];
            foreach (glob(Config::PRESETS . '/*.php') ?: [] as $file) {
                $presets[basename($file, '.php')] = (static fn() => require $file)();
            }
        }
        foreach ($presets as $preset => $data) {
            if (in_array($preset, $this->config->data['presets'] ?? [], true)) {
                continue;
            }
            $names = $what === 'table'
                ? array_merge(array_keys($data['tables'] ?? []), $data['keep']['tables'] ?? [])
                : array_map(fn($selector) => (string)preg_replace('/^elementType:/', '', (string)$selector), array_merge(array_keys($data['elements'] ?? []), $data['keep']['elements'] ?? []));
            foreach ($names as $pattern) {
                if (fnmatch((string)$pattern, $name, FNM_NOESCAPE)) {
                    return " (preset '{$preset}' kent die)";
                }
            }
        }

        return '';
    }

    // Data
    // =========================================================================

    private function resolveRelationSeeds(): void
    {
        $fieldIds = [];
        foreach ($this->contexts as $context) {
            if ($context['seedField'] !== null) {
                $fieldIds[$context['seedField']] = true;
            }
        }
        if (!$fieldIds) {
            return;
        }
        $rows = (new Query())
            ->select(['sourceId', 'targetId' => 'MIN([[targetId]])'])
            ->from(Table::RELATIONS)
            ->where(['fieldId' => array_keys($fieldIds)])
            ->groupBy(['sourceId'])
            ->all($this->db);
        foreach ($rows as $row) {
            $this->relationSeeds[(int)$row['sourceId']] = (int)$row['targetId'];
        }
    }

    private function checkElementTypes(): void
    {
        foreach ($this->elementClasses as $class) {
            if (!isset(self::KINDS[$class]) && !isset($this->keptTypes[$class]) && !isset($this->elementTypeRules[$class])) {
                $this->unclassified("elementtype {$class} heeft geen bestemming: keep.elements of elements['elementType:{$class}']" . $this->presetHint('element', $class));
            }
        }
    }

    // Handlers
    // =========================================================================

    private function resolveHandlers(): void
    {
        foreach ($this->config->data['handlers'] ?? [] as $class) {
            if (!is_string($class) || !class_exists($class) || !is_subclass_of($class, Handler::class)) {
                $this->errors[] = "handlers: {$class} is geen " . Handler::class;
                continue;
            }
            /** @var Handler $handler */
            $handler = new $class();
            $tables = [];
            foreach ($handler->tables() as $name => $columns) {
                $full = $this->tableName($name);
                if (!isset($this->dbTables[$full])) {
                    continue;
                }
                $missing = array_diff($columns, array_keys($this->dbTables[$full]['columns']));
                if ($missing) {
                    $this->errors[] = "{$class}: tabel {$name} heeft geen kolom " . implode(', ', $missing);
                    continue;
                }
                $tables[$full] = $columns;
            }
            if (!$tables) {
                // The plugin is not there: nothing to do.
                continue;
            }
            foreach ($tables as $full => $columns) {
                $this->handled[$full][] = $handler;
                $this->handledColumns[$full] = array_values(array_unique(array_merge($this->handledColumns[$full] ?? [], $columns)));
            }
            $handler->prepare($this, $this->db);
        }
    }

    private function unclassified(string $message): void
    {
        if ($this->strict) {
            $this->errors[] = $message;
        } else {
            $this->warnings[] = "{$message}, en blijft zoals het is";
        }
    }

    private static function isA(object $object, array $classes): bool
    {
        foreach ($classes as $class) {
            if (is_a($object, $class)) {
                return true;
            }
        }

        return false;
    }
}
