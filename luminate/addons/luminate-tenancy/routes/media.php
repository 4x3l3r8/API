<?php

use Illuminate\Support\Facades\Route;
use Luminate\Tenancy\Http\Controllers\MediaController;

// Protected media delivery (contract D.6 fallback). Ordinary bearer auth; the tenant comes from the verified X-Tenant-Context (global middleware).
// GET also answers HEAD.
Route::middleware(['auth:api'])
    ->get('api/courses/{course_id}/topics/{topic_id}/media', [MediaController::class, 'show'])
    ->whereNumber('course_id')
    ->whereNumber('topic_id')
    ->name('luminate.media');
