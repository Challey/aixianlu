<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

/**
 * Cross-language signing parity check.
 *
 * Verifies that the PHP canonicalizer reproduces the exact bytes and signature
 * produced by probes/src/aixianlu_probe/signing.py, using the shared vector
 * probes/tests/fixtures/signing_vector.json. Run after changing either side:
 *
 *   php apps/proxy-atlas/bin/probe_parity.php
 */

$fixture = dirname(__DIR__, 3) . '/probes/tests/fixtures/signing_vector.json';
if (!is_file($fixture)) {
    fwrite(STDERR, "fixture not found: {$fixture}\n");
    exit(1);
}

$vector = json_decode((string) file_get_contents($fixture), false);
if ($vector === null) {
    fwrite(STDERR, "fixture is not valid JSON\n");
    exit(1);
}

$failures = 0;
$check = function (string $label, string $expected, string $actual) use (&$failures): void {
    if ($expected === $actual) {
        echo "ok   {$label}\n";
        return;
    }
    $failures++;
    echo "FAIL {$label}\n  expected: {$expected}\n  actual:   {$actual}\n";
};

// canonical_bytes() in Python drops the top-level `signature` key before
// serialising; mirror that here so both sides compare the same byte string.
$unsigned = clone $vector->payload;
unset($unsigned->signature);
$canonical = probe_canonical_json($unsigned);
$check('canonical bytes match Python', (string) $vector->canonical, $canonical);

$signature = probe_sign_tree($vector->payload, (string) $vector->token);
$check('signature matches Python', strtolower((string) $vector->signature), $signature);

$check(
    'verify_signature accepts the vector',
    'true',
    probe_verify_signature($vector->payload, (string) $vector->token, (string) $vector->signature) ? 'true' : 'false'
);

$tampered = json_decode((string) json_encode($vector->payload), false);
$tampered->results[0]->latency_ms = 187.0;
$check(
    'verify_signature rejects tampering',
    'true',
    probe_verify_signature($tampered, (string) $vector->token, (string) $vector->signature) ? 'false' : 'true'
);

$roundtrip = json_decode('{"b":1,"a":[2,{"y":"直连","x":null}],"f":1.5}', false);
$check(
    'key sorting / nested list / float formatting',
    '{"a":[2,{"x":null,"y":"直连"}],"b":1,"f":1.500000}',
    probe_canonical_json($roundtrip)
);

echo $failures === 0 ? "\nall parity checks passed\n" : "\n{$failures} check(s) failed\n";
exit($failures === 0 ? 0 : 1);
