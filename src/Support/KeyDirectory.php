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

namespace Vtinnovations\ContaoMultilingualPagetree\Support;

/**
 * The pinned set of public verification keys, indexed by key id.
 *
 * An empty directory is a valid state and means "this build cannot verify
 * anything"; every signed workflow then fails closed. That is deliberate: a
 * build without provisioned keys must grant nothing rather than accept material
 * it cannot check.
 *
 * Keys are never read from configuration, the database or a request. An
 * installation must not be able to choose whom it trusts.
 */
final class KeyDirectory
{
    /** @var array<string, VerificationKey> */
    private readonly array $keys;

    /**
     * @param iterable<VerificationKey> $keys
     */
    public function __construct(iterable $keys = [])
    {
        $indexed = [];

        foreach ($keys as $key) {
            $indexed[$key->keyId] = $key;
        }

        $this->keys = $indexed;
    }

    /** The directory shipped with this build. */
    public static function pinned(): self
    {
        return new self(PinnedMaterial::keys());
    }

    public function find(string $keyId, int $now): ?VerificationKey
    {
        $key = $this->keys[$keyId] ?? null;

        if (null === $key || !$key->isUsableAt($now)) {
            return null;
        }

        return $key;
    }

    public function isEmpty(): bool
    {
        return [] === $this->keys;
    }

    /** @return list<string> */
    public function keyIds(): array
    {
        return array_keys($this->keys);
    }

    /** How many keys this build actually accepted. Safe to log. */
    public function count(): int
    {
        return count($this->keys);
    }

    /**
     * Every key usable right now, in declaration order.
     *
     * The licence document does not name the key that signed it, so a caller
     * verifying a document tries each of these in turn.
     *
     * @return list<VerificationKey>
     */
    public function usableAt(int $now): array
    {
        $usable = [];

        foreach ($this->keys as $key) {
            if ($key->isUsableAt($now)) {
                $usable[] = $key;
            }
        }

        return $usable;
    }
}
