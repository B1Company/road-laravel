<?php

declare(strict_types=1);
use B1Road\Laravel\Tests\TestCase;

it('boots the test environment', function () {
    expect(true)->toBeTrue();
});

it('has the package namespace autoloaded', function () {
    expect(class_exists(TestCase::class))->toBeTrue();
});
