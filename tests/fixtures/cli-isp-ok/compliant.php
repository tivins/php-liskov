<?php

declare(strict_types=1);

interface CliIspCompliantInterface
{
    public function doA(): string;
    public function doB(): int;
}

class CliIspCompliantClass implements CliIspCompliantInterface
{
    public function doA(): string
    {
        return 'hello';
    }

    public function doB(): int
    {
        return 42;
    }
}
