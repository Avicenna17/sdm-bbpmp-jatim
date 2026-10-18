<?php

namespace App\Domain\Pak\DTOs;

final readonly class EmployeeSnapshotData
{
    public function __construct(public array $values) {}

    public function fingerprint(): string
    {
        return hash('sha256', json_encode($this->values, JSON_THROW_ON_ERROR));
    }
}
