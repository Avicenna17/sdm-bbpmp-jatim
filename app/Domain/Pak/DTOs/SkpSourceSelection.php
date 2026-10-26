<?php

namespace App\Domain\Pak\DTOs;

final readonly class SkpSourceSelection
{
    /**
     * @param  list<SkpSelectedSource>  $selections
     * @param  list<array{issue_code: string, months: list<string>}>  $issues
     */
    public function __construct(public array $selections, public array $issues) {}
}
