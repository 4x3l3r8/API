<?php

use Illuminate\Support\Facades\Route;
use Luminate\Tenancy\Http\Controllers\RegistryController;

// No tenant context, no Passport guard, no session: these routes authenticate themselves (RegistryAuth). The path prefix is also
// the one the context middleware lets through without X-Tenant-Context (VerifyTenantContext::isRegistryRoute).
Route::get('api/luminate/registry/tenants', [RegistryController::class, 'index']);
Route::get('api/luminate/registry/tenants/{tid}', [RegistryController::class, 'show']);
Route::put('api/luminate/registry/tenants/{tid}', [RegistryController::class, 'upsert']);
