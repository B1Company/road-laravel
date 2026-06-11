<?php

declare(strict_types=1);

namespace B1Road\Laravel\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Nette\PhpGenerator\PhpFile;
use Spatie\LaravelData\Data;

/**
 * Generate spatie/laravel-data DTOs from the Road OpenAPI contract.
 *
 * The contract hub (`apps/sdks/contract/openapi.json`, emitted from the API's
 * public Swagger doc) is the single cross-language source of truth. This
 * command turns its `components.schemas` into `src/DTO/Generated/*.php`, so the
 * Laravel DTOs never drift from the wire. `--check` regenerates in memory and
 * fails on any difference — the Laravel-side mirror of the API's contract guard.
 *
 * Until the hub is emitted, the hand-written DTOs in `src/DTO/` remain the
 * source; `--check` no-ops green when no spec is present.
 */
final class GenerateDtosCommand extends Command
{
    protected $signature = 'road:generate-dtos
        {--spec= : Path to an OpenAPI 3 JSON spec (defaults to ../contract/openapi.json)}
        {--from-url= : Fetch the spec from a running API, e.g. http://localhost:3002/api/alpha/docs-json}
        {--output= : Output directory (defaults to src/DTO/Generated)}
        {--check : Verify the committed DTOs match the spec; exit non-zero on drift}';

    protected $description = 'Generate (or verify) spatie/laravel-data DTOs from the Road OpenAPI contract.';

    private const NAMESPACE = 'B1Road\\Laravel\\DTO\\Generated';

    public function handle(): int
    {
        $spec = $this->loadSpec();
        if ($spec === null) {
            if ($this->option('check')) {
                $this->info('road:generate-dtos: no OpenAPI spec found — skipping drift check (the contract hub has not landed yet).');

                return self::SUCCESS;
            }
            $this->error('No OpenAPI spec found. Pass --spec=<path> or --from-url=<url>, or emit the hub (pnpm openapi:emit).');

            return self::FAILURE;
        }

        /** @var array<string,mixed> $schemas */
        $schemas = $spec['components']['schemas'] ?? [];
        if ($schemas === []) {
            $this->warn('The OpenAPI spec has no components.schemas — nothing to generate.');

            return self::SUCCESS;
        }

        $generated = [];
        foreach ($schemas as $name => $schema) {
            if (is_string($name) && is_array($schema) && ($schema['type'] ?? 'object') === 'object') {
                $generated[$this->className($name).'.php'] = $this->renderClass($this->className($name), $schema);
            }
        }
        ksort($generated);

        return $this->option('check')
            ? $this->check($generated)
            : $this->write($generated);
    }

    /** @return array<string,mixed>|null */
    private function loadSpec(): ?array
    {
        $url = $this->option('from-url');
        if (is_string($url) && $url !== '') {
            $response = Http::timeout(15)->get($url);
            if (! $response->successful()) {
                $this->error(sprintf('Failed to fetch spec from %s (HTTP %d).', $url, $response->status()));

                return null;
            }
            $json = $response->json();

            return is_array($json) ? $json : null;
        }

        $path = is_string($this->option('spec')) && $this->option('spec') !== ''
            ? (string) $this->option('spec')
            : $this->defaultSpecPath();

        if (! is_file($path)) {
            return null;
        }

        $decoded = json_decode((string) file_get_contents($path), associative: true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param  array<string,string>  $generated  filename => contents
     */
    private function write(array $generated): int
    {
        $dir = $this->outputDir();
        if (! is_dir($dir)) {
            mkdir($dir, 0o755, recursive: true);
        }

        foreach ($generated as $file => $contents) {
            file_put_contents($dir.'/'.$file, $contents);
        }

        $this->info(sprintf('Generated %d DTO(s) into %s.', count($generated), $dir));

        return self::SUCCESS;
    }

    /**
     * @param  array<string,string>  $generated  filename => contents
     */
    private function check(array $generated): int
    {
        $dir = $this->outputDir();
        $drift = [];

        foreach ($generated as $file => $contents) {
            $path = $dir.'/'.$file;
            if (! is_file($path) || file_get_contents($path) !== $contents) {
                $drift[] = $file;
            }
        }

        // Committed files the spec no longer produces.
        foreach (glob($dir.'/*.php') ?: [] as $existing) {
            if (! isset($generated[basename($existing)])) {
                $drift[] = basename($existing).' (stale — not in spec)';
            }
        }

        if ($drift !== []) {
            $this->error('Generated DTOs are out of date with the contract. Run `php artisan road:generate-dtos`. Drift:');
            foreach ($drift as $file) {
                $this->line('  - '.$file);
            }

            return self::FAILURE;
        }

        $this->info('Generated DTOs are in sync with the contract.');

        return self::SUCCESS;
    }

    /** @param  array<string,mixed>  $schema */
    private function renderClass(string $class, array $schema): string
    {
        $file = new PhpFile;
        $file->setStrictTypes();
        $file->addComment('@generated by `php artisan road:generate-dtos` — DO NOT EDIT.');

        $namespace = $file->addNamespace(self::NAMESPACE);
        $namespace->addUse(Data::class);

        $type = $namespace->addClass($class);
        $type->setFinal()->setExtends(Data::class);

        $properties = is_array($schema['properties'] ?? null) ? $schema['properties'] : [];
        /** @var list<string> $required */
        $required = array_values(array_filter((array) ($schema['required'] ?? []), 'is_string'));

        $constructor = $type->addMethod('__construct');

        // Required first, then optional — PHP forbids a required param after an
        // optional one.
        $ordered = $this->orderProperties($properties, $required);
        foreach ($ordered as $propName => $propSchema) {
            [$phpType, $nullable] = $this->mapType(is_array($propSchema) ? $propSchema : []);
            $isRequired = in_array($propName, $required, true);

            $param = $constructor->addPromotedParameter($propName)->setPublic();
            $param->setType($phpType);
            if ($nullable || ! $isRequired) {
                $param->setNullable(true);
                if (! $isRequired) {
                    $param->setDefaultValue(null);
                }
            }
        }

        return (string) $file;
    }

    /**
     * @param  array<string,mixed>  $properties
     * @param  list<string>  $required
     * @return array<string,mixed>
     */
    private function orderProperties(array $properties, array $required): array
    {
        $req = [];
        $opt = [];
        foreach ($properties as $name => $schema) {
            if (in_array($name, $required, true)) {
                $req[$name] = $schema;
            } else {
                $opt[$name] = $schema;
            }
        }

        return $req + $opt;
    }

    /**
     * Map a JSON-Schema property to a [phpType, nullable] pair.
     *
     * @param  array<string,mixed>  $schema
     * @return array{0: string, 1: bool}
     */
    private function mapType(array $schema): array
    {
        $nullable = ($schema['nullable'] ?? false) === true;

        if (isset($schema['$ref']) && is_string($schema['$ref'])) {
            return [self::NAMESPACE.'\\'.$this->className(basename($schema['$ref'])), $nullable];
        }

        $type = $schema['type'] ?? null;
        $php = match ($type) {
            'string' => 'string',
            'integer' => 'int',
            'number' => 'float',
            'boolean' => 'bool',
            'array', 'object' => 'array',
            default => 'mixed',
        };

        return [$php, $nullable];
    }

    private function className(string $schemaName): string
    {
        $clean = preg_replace('/[^A-Za-z0-9]/', ' ', $schemaName) ?? $schemaName;

        return str_replace(' ', '', ucwords($clean));
    }

    private function outputDir(): string
    {
        $output = $this->option('output');

        return is_string($output) && $output !== ''
            ? $output
            : dirname(__DIR__, 2).'/src/DTO/Generated';
    }

    private function defaultSpecPath(): string
    {
        return dirname(__DIR__, 2).'/../contract/openapi.json';
    }
}
