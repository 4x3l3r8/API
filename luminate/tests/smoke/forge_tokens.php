<?php
// Run INSIDE the api container (php /path/forge_tokens.php). Test helper for token_binding.py; prints one JSON object.
//   unbound:   signed with the REAL Passport key, valid in shape, but carries NO tenant claim
//   attacker:  carries tenant=<argv[1]> but is signed with an attacker's own key
function b64(string $s): string { return rtrim(strtr(base64_encode($s), '+/', '-_'), '='); }
function mint(array $claims, $pkey): string {
    $in = b64(json_encode(['typ' => 'JWT', 'alg' => 'RS256'])) . '.' . b64(json_encode($claims, JSON_UNESCAPED_SLASHES));
    openssl_sign($in, $sig, $pkey, OPENSSL_ALGO_SHA256);
    return $in . '.' . b64($sig);
}
$base = ['aud' => '1', 'jti' => bin2hex(random_bytes(16)), 'iat' => time(), 'nbf' => time(), 'exp' => time() + 3600, 'sub' => '1', 'scopes' => []];
$real = openssl_pkey_get_private(base64_decode((string) getenv('JWT_PRIVATE_KEY_BASE64')));
$attacker = openssl_pkey_new(['private_key_bits' => 2048]);
$tenant = $argv[1] ?? 't-alpha';
echo json_encode([
    'unbound' => mint($base, $real),
    'attacker' => mint($base + ['tenant' => $tenant], $attacker),
]);
