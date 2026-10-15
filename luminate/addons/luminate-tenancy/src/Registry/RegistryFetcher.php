<?php

declare(strict_types=1);

namespace Luminate\Tenancy\Registry;

/** Fetches the authoritative registry listing from Luminate (the reconcile pull). Transport only; no trust decisions. */
interface RegistryFetcher
{
    /**
     * @return array{status:int,body:string,timestamp:?string,signature:?string}
     *
     * @throws \RuntimeException on a transport failure (DNS, TLS, timeout)
     */
    public function fetch(): array;
}
