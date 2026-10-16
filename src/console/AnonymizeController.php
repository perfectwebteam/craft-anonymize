<?php

namespace perfectwebteam\anonymize\console;

use Craft;
use craft\db\Query;
use craft\elements\Asset;
use craft\helpers\App;
use perfectwebteam\anonymize\Anonymizer;
use perfectwebteam\anonymize\Config;
use perfectwebteam\anonymize\dump\Output;
use perfectwebteam\anonymize\dump\SqlDumpWriter;
use perfectwebteam\anonymize\PiiScanner;
use perfectwebteam\anonymize\Placeholder;
use perfectwebteam\anonymize\Plan;
use RuntimeException;
use Throwable;
use yii\base\InvalidConfigException;
use yii\console\Controller;
use yii\console\ExitCode;

/**
 * An anonymized dump of this installation's database, with only PHP: no
 * mysqldump, no other binary. What config/anonymize.php says is replaced on
 * the way out, so the real values are written nowhere and nothing is written
 * to the database: it only reads, in one snapshot.
 *
 *   php craft anonymize/dump > kopie.sql
 *   php craft anonymize/dump --gzip --output=kopie.sql.gz
 *   ssh server 'cd /pad && php craft anonymize/dump --gzip' > kopie.sql.gz
 *   php craft anonymize/check
 *
 * Exits non-zero when it stopped; on stdout, what it wrote is then incomplete,
 * and a file it does not write at all. A summary goes to stderr.
 *
 * On the copy, after the import:
 *
 *   php craft anonymize/placeholders
 *
 * writes a placeholder for every asset whose file is not there.
 */
class AnonymizeController extends Controller
{
    public $defaultAction = 'dump';

    /** @var string|null where the dump goes (`{path}.part` until it is finished); stdout without it */
    public ?string $output = null;

    /** @var bool gzip the dump as it streams */
    public bool $gzip = false;

    /** @var string|null the config file, if not config/anonymize.php */
    public ?string $config = null;

    /** @var bool placeholders: only count what it would write */
    public bool $dryRun = false;

    public function options($actionID): array
    {
        $options = parent::options($actionID);
        $options[] = 'config';
        if ($actionID === 'dump') {
            $options[] = 'output';
            $options[] = 'gzip';
        }
        if ($actionID === 'placeholders') {
            $options[] = 'dryRun';
        }

        return $options;
    }

    /** Writes the anonymized dump. */
    public function actionDump(): int
    {
        // stdout is the dump: errors, and logs, go elsewhere. devMode sets
        // display_errors to 1, which on the CLI is stdout.
        ini_set('display_errors', 'stderr');
        if (App::isStreamLog()) {
            $this->stderr("CRAFT_STREAM_LOG staat aan, en dan schrijft Craft zijn logs naar stdout, midden in de dump.\n");

            return ExitCode::CONFIG;
        }
        if ($this->output === null && stream_isatty(STDOUT)) {
            $this->stderr("Stuur de dump naar een bestand of een pijp, bijvoorbeeld:\n  php craft anonymize/dump > kopie.sql\n  php craft anonymize/dump --gzip --output=kopie.sql.gz\n");

            return ExitCode::USAGE;
        }

        $start = microtime(true);
        $writer = new SqlDumpWriter(Craft::$app->getDb());
        $plan = $this->plan($writer);
        if ($plan === null) {
            return ExitCode::CONFIG;
        }

        $output = null;
        try {
            $output = $this->output !== null ? Output::file($this->output, $this->gzip) : Output::stdout($this->gzip);
            $writer->tableCollations = $plan->tableCollations;
            // What the copy is, for anonymize/placeholders: a source that has one (a copy of a copy) does not pass it on.
            $marker = $plan->prefix . Plan::MARKER;
            $writer->skip($marker);
            $writer->trailer = self::marker($marker, (string)Craft::$app->getDb()->createCommand('SELECT DATABASE()')->queryScalar());
            $scanner = new PiiScanner($plan->keptEmails, $plan->scanDomains);
            $writer->inspect = $scanner->inspect(...);
            $anonymizer = new Anonymizer($plan);
            $anonymizer->register($writer);
            $absent = $writer->run($output);
            $anonymizer->finish($absent);
            $this->scanReport($scanner, $plan->prefix);
            if ($plan->scanFail && $scanner->hits) {
                throw new RuntimeException(array_sum($scanner->hits) . ' e-mailadres(sen) die er niet horen, en scan.fail staat aan');
            }
            $output->finish();
            $writer->end();
        } catch (Throwable $e) {
            $output?->abort();
            $this->close($writer);
            $this->stderr("Afgebroken: {$e->getMessage()}\n");
            if ($output !== null) {
                $this->stderr($this->output === null
                    ? "Wat er al geschreven is, is onvolledig en mag niet gebruikt worden.\n"
                    : "Er is geen bestand geschreven.\n");
            }

            return ExitCode::UNSPECIFIED_ERROR;
        }

        foreach ($writer->stats as $table => $counts) {
            if ($counts['changed'] || $counts['dropped']) {
                $this->stderr(sprintf("  %-40s %10d rijen, %9d vervangen, %9d weggelaten\n", substr($table, strlen($plan->prefix)), $counts['rows'], $counts['changed'], $counts['dropped']));
            }
        }
        $emptied = array_keys(array_filter($plan->tables, fn($rule) => $rule['empty']));
        if ($emptied) {
            $this->stderr('  Alleen de structuur: ' . implode(', ', array_map(fn($t) => substr($t, strlen($plan->prefix)), $emptied)) . "\n");
        }
        $this->stderr(sprintf("Klaar: %.0f MB dump in %.1f s, hoogstens %.0f MB geheugen.\n",
            $output->bytes / 1048576, microtime(true) - $start, memory_get_peak_usage(true) / 1048576));

        return ExitCode::OK;
    }

    /** Checks the config against this database and says what the dump would do, without dumping. */
    public function actionCheck(): int
    {
        $writer = new SqlDumpWriter(Craft::$app->getDb());
        $plan = $this->plan($writer);
        if ($plan === null) {
            return ExitCode::CONFIG;
        }
        $this->close($writer);

        $this->stdout("Wat geanonimiseerd wordt:\n");
        foreach ($plan->describe() as $line) {
            $this->stdout("  {$line}\n");
        }
        $this->stdout(sprintf(
            "Bewaard: %d gebruikers met hun inloggegevens, %d losse elementen (met concepten, revisies en geneste elementen), %d secties, %d veldindelingen, %d volumes, %d tabellen.\n",
            count($plan->keptUsers), count($plan->keptIds) - count($plan->keptUsers), count($plan->keptSections),
            count($plan->keptLayouts), count($plan->keptVolumes), count($plan->keptTables),
        ));
        $this->stdout("De config is in orde" . ($plan->strict ? ', ook strict' : '') . ".\n");

        return ExitCode::OK;
    }

    /**
     * On a copy made from a dump: writes a placeholder file of the same type
     * for every asset whose file is not there, so documents and images open.
     * It only adds: a file that exists, a real one or an earlier placeholder,
     * stays as it is, and nothing is deleted.
     *
     * Refused unless the environment is one the config allows (never
     * production) on both counts Craft has, and the database holds the marker
     * every dump ends with.
     */
    public function actionPlaceholders(): int
    {
        try {
            $config = Config::load($this->config ?? Craft::getAlias('@config/anonymize.php'), (string)Craft::$app->env);
        } catch (InvalidConfigException $e) {
            $this->stderr("{$e->getMessage()}\n");

            return ExitCode::CONFIG;
        }
        $allowed = $config->placeholderEnvironments();
        // `--env` changes the first, not the second: both have to agree.
        $environment = (string)Craft::$app->env;
        $declared = (string)(defined('CRAFT_ENVIRONMENT') ? CRAFT_ENVIRONMENT : App::env('CRAFT_ENVIRONMENT'));
        if (!in_array($environment, $allowed, true) || $environment !== $declared) {
            $this->stderr("Alleen in " . implode(', ', $allowed) . ": dit is '{$environment}'" . ($environment !== $declared ? ", en CRAFT_ENVIRONMENT zegt '{$declared}'" : '') . ".\n");

            return ExitCode::CONFIG;
        }
        $marker = $this->readMarker();
        if ($marker === null) {
            $this->stderr('Deze database komt niet uit anonymize/dump (er is geen tabel ' . Plan::MARKER . "): placeholders schrijft alleen bij een geanonimiseerde kopie.\n");

            return ExitCode::CONFIG;
        }
        $this->stdout("Kopie van {$marker['source']}, gemaakt op {$marker['dumpedAt']} UTC" . ($this->dryRun ? '; alleen tellen' : '') . ".\n");

        $counts = [];
        $failures = [];
        foreach (Asset::find()->status(null)->each() as $asset) {
            /** @var Asset $asset */
            $handle = '?';
            try {
                $volume = $asset->getVolume();
                $handle = $volume->handle;
                $counts[$handle] ??= ['er al' => 0, 'gemaakt' => 0, 'mislukt' => 0];
                $path = $asset->getPath();
                if ($volume->fileExists($path)) {
                    $counts[$handle]['er al']++;
                    continue;
                }
                if (!$this->dryRun) {
                    $volume->write($path, Placeholder::forExtension((string)$asset->getExtension(), (string)$asset->title, $asset->width, $asset->height));
                }
                $counts[$handle]['gemaakt']++;
            } catch (Throwable $e) {
                $counts[$handle] ??= ['er al' => 0, 'gemaakt' => 0, 'mislukt' => 0];
                $counts[$handle]['mislukt']++;
                if (count($failures) < 5) {
                    $failures[] = "asset {$asset->id}: {$e->getMessage()}";
                }
            }
        }

        foreach ($counts as $handle => $count) {
            $this->stdout(sprintf("  %-30s %8d %s, %8d er al, %5d mislukt\n", $handle, $count['gemaakt'], $this->dryRun ? 'te maken' : 'gemaakt', $count['er al'], $count['mislukt']));
        }
        foreach ($failures as $failure) {
            $this->stderr("  ! {$failure}\n");
        }

        return array_sum(array_column($counts, 'mislukt')) > 0 ? ExitCode::UNSPECIFIED_ERROR : ExitCode::OK;
    }

    /** @return array{dumpedAt: string, source: string}|null the newest row of the marker a dump ends with */
    private function readMarker(): ?array
    {
        $db = Craft::$app->getDb();
        $table = $db->tablePrefix . Plan::MARKER;
        if (!$db->tableExists($table)) {
            return null;
        }
        $row = (new Query())->select(['dumpedAt', 'source'])->from($table)->orderBy(['dumpedAt' => SORT_DESC])->one();

        return $row ?: null;
    }

    /** The SQL a dump ends with: a table that says this is an anonymized copy, of what and since when. */
    private static function marker(string $table, string $source): string
    {
        $name = '`' . str_replace('`', '``', $table) . '`';
        $source = str_replace(['\\', "'"], ['\\\\', "\\'"], $source);

        return "\n--\n-- {$name}: een geanonimiseerde kopie (anonymize/placeholders leest het)\n--\n\n"
            . "DROP TABLE IF EXISTS {$name};\n"
            . "CREATE TABLE {$name} (\n  `dumpedAt` datetime NOT NULL,\n  `source` varchar(255) NOT NULL\n) DEFAULT CHARSET=utf8mb4;\n"
            . "INSERT INTO {$name} (`dumpedAt`, `source`) VALUES ('" . gmdate('Y-m-d H:i:s') . "', '{$source}');\n";
    }

    /** Opens the snapshot and works the config out in it; null after saying what is wrong. */
    private function plan(SqlDumpWriter $writer): ?Plan
    {
        try {
            $config = Config::load($this->config ?? Craft::getAlias('@config/anonymize.php'), (string)Craft::$app->env);
            // The snapshot first: what the rules look up must be what is dumped.
            $writer->begin();
            $plan = Plan::build($config, Craft::$app->getDb());
        } catch (InvalidConfigException $e) {
            $this->close($writer);
            $this->stderr("{$e->getMessage()}\n");

            return null;
        } catch (Throwable $e) {
            $this->close($writer);
            $this->stderr("Afgebroken: {$e->getMessage()}\n");

            return null;
        }

        foreach ($plan->warnings as $warning) {
            $this->stderr("  ~ {$warning}\n");
        }
        if ($plan->errors) {
            foreach ($plan->errors as $error) {
                $this->stderr("  ! {$error}\n");
            }
            $this->stderr(count($plan->errors) . ' probleem/problemen in ' . ($this->config ?? 'config/anonymize.php') . "; er is niets geschreven.\n");
            $this->close($writer);

            return null;
        }

        return $plan;
    }

    /** What the PII scanner found, masked: where to look, not whose it is. */
    private function scanReport(PiiScanner $scanner, string $prefix): void
    {
        if (!$scanner->hits) {
            $this->stderr("PII-scan: geen e-mailadressen die er niet horen.\n");

            return;
        }
        $this->stderr("PII-scan: e-mailadressen die geen regel raakte (gemaskeerd); geef ze een regel, of hun domein een plek in scan.allowDomains:\n");
        arsort($scanner->hits);
        foreach ($scanner->hits as $where => $count) {
            $name = str_starts_with($where, $prefix) ? substr($where, strlen($prefix)) : $where;
            $this->stderr(sprintf("  %-50s %7d  %s\n", $name, $count, implode(', ', $scanner->samples[$where] ?? [])));
        }
    }

    private function close(SqlDumpWriter $writer): void
    {
        try {
            $writer->end();
        } catch (Throwable) {
            // No snapshot open, or the connection is gone: nothing to close.
        }
    }
}
