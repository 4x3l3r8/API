<?php
\Pooled\Spike\TenantContext::$forced = 'pooled-d.localhost';
$u = new \App\Models\User();
$u->email = 'admin@d.test'; $u->first_name = 'Admin'; $u->last_name = 'D';
$u->password = \Illuminate\Support\Facades\Hash::make('d-secret');
$u->is_active = true; $u->email_verified_at = now();
$u->save();
$u->assignRole('admin');
echo "created id=" . $u->id . " tenant=" . $u->tenant_id . PHP_EOL;
