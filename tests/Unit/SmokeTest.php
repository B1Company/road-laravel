<?php

declare(strict_types=1);

it('boots the test environment', function () {
    expect(true)->toBeTrue();
});

it('has the package namespace autoloaded', function () {
    expect(class_exists(\B1Road\Laravel\Tests\TestCase::class))->toBeTrue();
});
