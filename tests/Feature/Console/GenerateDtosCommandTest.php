<?php

declare(strict_types=1);

use B1Road\Laravel\DTO\Generated\WidgetFixture;
use Illuminate\Support\Facades\File;

/** Write a small OpenAPI 3 spec fixture and return its path. */
function writeSpecFixture(): string
{
    $spec = [
        'openapi' => '3.0.0',
        'components' => [
            'schemas' => [
                'WidgetFixture' => [
                    'type' => 'object',
                    'required' => ['id', 'count'],
                    'properties' => [
                        'id' => ['type' => 'string'],
                        'count' => ['type' => 'integer'],
                        'ratio' => ['type' => 'number', 'nullable' => true],
                        'active' => ['type' => 'boolean'],
                        'tags' => ['type' => 'array', 'items' => ['type' => 'string']],
                        'owner' => ['$ref' => '#/components/schemas/OwnerFixture'],
                    ],
                ],
                'OwnerFixture' => [
                    'type' => 'object',
                    'required' => ['name'],
                    'properties' => ['name' => ['type' => 'string']],
                ],
            ],
        ],
    ];

    $path = sys_get_temp_dir().'/road-spec-'.uniqid().'.json';
    file_put_contents($path, (string) json_encode($spec));

    return $path;
}

afterEach(function () {
    File::deleteDirectory(sys_get_temp_dir().'/road-dtos-out', preserve: false);
});

it('generates valid, hydrate-able Data classes from an OpenAPI spec', function () {
    $spec = writeSpecFixture();
    $out = sys_get_temp_dir().'/road-dtos-out';

    $this->artisan('road:generate-dtos', ['--spec' => $spec, '--output' => $out])->assertExitCode(0);

    $contents = (string) file_get_contents($out.'/WidgetFixture.php');
    expect($contents)->toContain('declare(strict_types=1)');
    expect($contents)->toContain('final class WidgetFixture extends Data');
    expect($contents)->toContain('public string $id');
    expect($contents)->toContain('public int $count');
    expect($contents)->toContain('?float $ratio');

    // The generated classes are real PHP that hydrates the wire shape.
    require $out.'/OwnerFixture.php';
    require $out.'/WidgetFixture.php';
    $widget = WidgetFixture::from([
        'id' => 'w1', 'count' => 3, 'ratio' => null, 'active' => true,
        'tags' => ['a', 'b'], 'owner' => ['name' => 'Eduardo'],
    ]);

    expect($widget->id)->toBe('w1');
    expect($widget->count)->toBe(3);
    expect($widget->owner->name)->toBe('Eduardo');
});

it('passes --check when the committed DTOs match the spec', function () {
    $spec = writeSpecFixture();
    $out = sys_get_temp_dir().'/road-dtos-out';

    $this->artisan('road:generate-dtos', ['--spec' => $spec, '--output' => $out])->assertExitCode(0);
    $this->artisan('road:generate-dtos', ['--spec' => $spec, '--output' => $out, '--check' => true])->assertExitCode(0);
});

it('fails --check when a generated DTO has drifted', function () {
    $spec = writeSpecFixture();
    $out = sys_get_temp_dir().'/road-dtos-out';

    $this->artisan('road:generate-dtos', ['--spec' => $spec, '--output' => $out])->assertExitCode(0);
    file_put_contents($out.'/WidgetFixture.php', "<?php // tampered\n");

    $this->artisan('road:generate-dtos', ['--spec' => $spec, '--output' => $out, '--check' => true])->assertExitCode(1);
});

it('no-ops green on --check when the contract hub has not landed', function () {
    $this->artisan('road:generate-dtos', ['--spec' => '/nonexistent/openapi.json', '--check' => true])->assertExitCode(0);
});
