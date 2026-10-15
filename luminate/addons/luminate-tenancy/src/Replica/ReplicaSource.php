<?php

declare(strict_types=1);

namespace Luminate\Tenancy\Replica;

/** Where the local registry replica's entries come from. Throws when they cannot be read (callers fail closed). */
interface ReplicaSource
{
    /** @return array<string,array{status:string,placement:array<string,string>}> */
    public function entries(): array;
}
