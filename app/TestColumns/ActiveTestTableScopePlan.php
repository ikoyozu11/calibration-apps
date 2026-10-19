<?php

namespace App\TestColumns;

readonly class ActiveTestTableScopePlan
{
    /**
     * @param  list<ActiveTestTableScopeCandidate>  $candidates
     */
    public function __construct(
        public int $instrumentTypeId,
        public array $candidates,
    ) {}

    public function hasReadyTemplates(): bool
    {
        return $this->candidates !== [];
    }

    public function isEmpty(): bool
    {
        return $this->candidates === [];
    }
}
