<?php

declare(strict_types=1);

interface CliIspTestInterface
{
    public function realWork(): void;
    public function unusedMethod(): void;
}

class CliIspTestViolation implements CliIspTestInterface
{
    public function realWork(): void
    {
        echo "Real work\n";
    }

    public function unusedMethod(): void
    {
        // Empty stub — ISP violation
    }
}
