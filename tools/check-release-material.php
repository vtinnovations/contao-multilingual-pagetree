<?php

/*
 * Contao Multilingual Pagetree
 *
 * Package: vtinnovations/contao-multilingual-pagetree
 * Copyright: V&T Innovations Team
 * Licence: proprietary
 * Website: https://www.v-t.one
 */


declare(strict_types=1);

/**
 * Fails a release build whose pinned verification material is missing, unusable
 * or no longer reassembles the approved bytes.
 *
 * The material is a trust anchor, so this check is what stands between a
 * distributable artefact and a build that can verify nothing. It never writes
 * anything and never contacts anything: the fingerprint recorded next to each
 * key is compared against the fragments that build ships, so a fragment that was
 * edited, truncated or reordered fails the build instead of shipping.
 *
 * `--allow-empty` exists only for a deliberately unprovisioned working tree; it
 * must never be used for a published release.
 *
 * Usage: php tools/check-release-material.php [--allow-empty]
 */

use Vtinnovations\ContaoMultilingualPagetree\Support\KeyDirectory;
use Vtinnovations\ContaoMultilingualPagetree\Support\PinnedMaterial;

$root = dirname(__DIR__);

require_once $root.'/src/Support/SignatureScheme.php';
require_once $root.'/src/Support/VerificationKey.php';
require_once $root.'/src/Support/PinnedMaterial.php';
require_once $root.'/src/Support/KeyDirectory.php';

$allowEmpty = in_array('--allow-empty', $argv, true);
$declared = PinnedMaterial::declaredCount();
$directory = KeyDirectory::pinned();
$usable = count($directory->keyIds());
$errors = [];

if (0 === $declared) {
    if (!$allowEmpty) {
        $errors[] = 'No verification material is pinned. A release build must pin the public keys first.';
    }
} elseif ($usable !== $declared) {
    $errors[] = sprintf('%d of %d pinned entries are unusable (wrong length, bad Base64 or unknown scheme).', $declared - $usable, $declared);
}

// Every accepted key must reproduce the fingerprint recorded beside it. This is
// what proves the shipped fragments still assemble the approved public key.
$fingerprints = PinnedMaterial::declaredFingerprints();

foreach (PinnedMaterial::keys() as $key) {
    $recorded = $fingerprints[$key->keyId] ?? null;

    if (null === $recorded) {
        $errors[] = sprintf('Key "%s" has no recorded fingerprint, so its material cannot be proven.', $key->keyId);

        continue;
    }

    if (!hash_equals($recorded, $key->fingerprint())) {
        $errors[] = sprintf('Key "%s" does not reassemble to its recorded fingerprint.', $key->keyId);
    }
}

$source = (string) file_get_contents($root.'/src/Support/PinnedMaterial.php');

foreach (['PRIVATE KEY', 'sodium_crypto_sign_secretkey', 'BEGIN OPENSSH'] as $forbidden) {
    if (str_contains($source, $forbidden)) {
        $errors[] = 'Private key material must never be part of the distributed product: '.$forbidden;
    }
}

if (!extension_loaded('sodium')) {
    $errors[] = 'The build host cannot verify Ed25519 signatures; a release built here could not be tested.';
}

if ([] !== $errors) {
    fwrite(STDERR, implode(PHP_EOL, $errors).PHP_EOL);

    exit(1);
}

fwrite(STDOUT, sprintf(
    "Release material check passed: %d pinned key(s) [%s].\n",
    $usable,
    $usable > 0 ? implode(', ', $directory->keyIds()) : 'none, empty list explicitly allowed',
));
