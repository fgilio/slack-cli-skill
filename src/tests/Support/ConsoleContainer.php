<?php

namespace Tests\Support;

use Illuminate\Container\Container;

/**
 * The container a command needs to run outside a booted application.
 *
 * Illuminate's console prompt configuration asks the application whether
 * it is running tests, which a bare container cannot answer.
 */
final class ConsoleContainer extends Container
{
    public function runningUnitTests(): bool
    {
        return true;
    }
}
