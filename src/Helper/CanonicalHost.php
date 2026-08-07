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

namespace Vtinnovations\ContaoMultilingualPagetree\Helper;

/**
 * The single hostname canonicaliser used by every host comparison in this
 * bundle.
 *
 * It normalises **representation only**. Case, exactly one trailing dot, an
 * approved port and an internationalised spelling are collapsed into one
 * canonical ASCII form; nothing else is touched. In particular it never strips
 * `www`, never reduces a host to its registrable domain, never resolves an
 * alias or CNAME and never interprets a wildcard. `example.com`,
 * `www.example.com`, `shop.example.com` and `admin.shop.example.com` therefore
 * remain four distinct identities.
 *
 * Comparison is {@see self::matches()}: a constant-time comparison of two
 * canonical forms, never a suffix, substring or regular-expression match, so
 * `malicious-example.com` can never be accepted for `example.com`.
 */
final class CanonicalHost
{
    /** Longest accepted host, per DNS. */
    private const MAX_LENGTH = 253;

    /**
     * Returns the canonical host, or null when the value cannot be canonicalised
     * unambiguously. Anything ambiguous fails closed rather than being guessed.
     */
    public function normalize(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $host = trim($value);

        if ('' === $host) {
            return null;
        }

        // A URL, credentials, a path or whitespace inside the value means the
        // caller passed something that is not a host. Never try to repair it.
        if (1 === preg_match('~[\s/\\\\@?#]~u', $host)) {
            return null;
        }

        // A literal wildcard is invalid under the current protocol. It is
        // rejected rather than interpreted, so it can never widen a binding.
        if (str_contains($host, '*')) {
            return null;
        }

        $host = $this->removePort($host);

        if (null === $host) {
            return null;
        }

        // Exactly one trailing dot is removed; "example.com.." stays invalid.
        if (str_ends_with($host, '.')) {
            $host = substr($host, 0, -1);
        }

        $host = $this->toAscii($host);

        if (null === $host || '' === $host || strlen($host) > self::MAX_LENGTH) {
            return null;
        }

        // An IP literal never identifies a bound installation. Binding to an
        // address would make the binding portable between hosts.
        if ($this->looksLikeIpAddress($host)) {
            return null;
        }

        return $this->isValidHostname($host) ? $host : null;
    }

    /**
     * Exact-host equality of two untrusted values.
     *
     * Both sides are canonicalised first; if either side cannot be canonicalised
     * the answer is false.
     */
    public function matches(mixed $expected, mixed $actual): bool
    {
        $left = $this->normalize($expected);
        $right = $this->normalize($actual);

        if (null === $left || null === $right) {
            return false;
        }

        return hash_equals($left, $right);
    }

    /**
     * True only when every given value canonicalises to the very same host.
     *
     * Used wherever the protocol requires the trusted installation host, the
     * packet host and the signed host to be exactly equal.
     */
    public function allMatch(mixed ...$values): bool
    {
        if (count($values) < 2) {
            return false;
        }

        $first = $this->normalize(array_shift($values));

        if (null === $first) {
            return false;
        }

        foreach ($values as $value) {
            $candidate = $this->normalize($value);

            if (null === $candidate || !hash_equals($first, $candidate)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Removes a numeric port. A malformed or out-of-range port invalidates the
     * whole value; an IPv6 literal in brackets is rejected together with every
     * other address form.
     */
    private function removePort(string $host): ?string
    {
        if (str_contains($host, '[') || str_contains($host, ']')) {
            return null;
        }

        $colons = substr_count($host, ':');

        if (0 === $colons) {
            return $host;
        }

        if ($colons > 1) {
            return null;
        }

        [$name, $port] = explode(':', $host, 2);

        if (1 !== preg_match('/^\d{1,5}$/', $port)) {
            return null;
        }

        $number = (int) $port;

        if ($number < 1 || $number > 65535) {
            return null;
        }

        return $name;
    }

    /**
     * Lowercases ASCII and converts an internationalised host to Punycode.
     *
     * A non-ASCII host without the intl extension has no unambiguous canonical
     * form here, so it is rejected instead of being compared in a form the
     * signing side may not have used.
     */
    private function toAscii(string $host): ?string
    {
        if (1 === preg_match('/^[\x20-\x7E]*$/', $host)) {
            return strtolower($host);
        }

        if (!function_exists('idn_to_ascii')) {
            return null;
        }

        $ascii = @idn_to_ascii($host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);

        return is_string($ascii) && '' !== $ascii ? strtolower($ascii) : null;
    }

    private function looksLikeIpAddress(string $host): bool
    {
        return false !== filter_var($host, FILTER_VALIDATE_IP)
            || 1 === preg_match('/^\d+(\.\d+)*$/', $host);
    }

    private function isValidHostname(string $host): bool
    {
        foreach (explode('.', $host) as $label) {
            if ('' === $label || strlen($label) > 63) {
                return false;
            }

            if (1 !== preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?$/', $label)) {
                return false;
            }
        }

        return true;
    }
}
