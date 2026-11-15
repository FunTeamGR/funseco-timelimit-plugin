<?php

namespace Funseco\TimeLimit\Tests;

use Funseco\Admin\Tests\Support\AbilitySnapshot;
use Funseco\Core\Testing\TestCase as CoreTestCase;
use Funseco\Admin\AdminServiceProvider;
use Funseco\TimeLimit\TimeLimitServiceProvider;

abstract class TestCase extends CoreTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        AbilitySnapshot::apply();
    }

    /** Dependencies first, then this plugin (isolated runs load only these). */
    protected function plugins(): array
    {
        return [
            AdminServiceProvider::class,
            TimeLimitServiceProvider::class,
        ];
    }
}
