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

namespace Vtinnovations\ContaoMultilingualPagetree\Metadata;

use Symfony\Component\HttpFoundation\RequestStack;
use Vtinnovations\ContaoMultilingualPagetree\Helper\CanonicalHost;
use Vtinnovations\ContaoMultilingualPagetree\Storage\PackageStoreInterface;

/**
 * Answers "which host is this installation?" from trusted data only.
 *
 * Inside an HTTP request the answer comes from Symfony's `Request::getHost()`,
 * which applies the framework's trusted-host and trusted-proxy configuration: a
 * user-supplied `Host`, `Forwarded` or `X-Forwarded-Host` value is only honoured
 * when the operator has explicitly declared the proxy as trusted. Outside a
 * request - console commands, queue workers, schedulers - there is no
 * trustworthy host in the environment, so the previously proven host recorded
 * during activation is used instead of inventing one from configuration.
 *
 * @see \Vtinnovations\ContaoMultilingualPagetree\Storage\PackageStoreInterface::verifiedHost()
 */
final class InstallationIdentity
{
    public function __construct(
        private readonly CanonicalHost $hosts,
        private readonly PackageStoreInterface $store,
        private readonly ?RequestStack $requestStack = null,
        private readonly ?RootScope $rootContext = null,
    ) {
    }

    /**
     * The canonical host of the current HTTP request, or null when there is no
     * request or the host cannot be canonicalised.
     */
    public function fromRequest(): ?string
    {
        $request = $this->requestStack?->getCurrentRequest();

        if (null === $request) {
            return null;
        }

        try {
            return $this->hosts->normalize($request->getHost());
        } catch (\Throwable) {
            // A rejected host (untrusted host pattern) is not a usable identity.
            return null;
        }
    }

    /**
     * The identity to validate against: the trusted request host when one
     * exists, otherwise the host proven during the last activation.
     */
    public function current(): ?string
    {
        // A selected site root is authoritative. In particular, a backend
        // request host must never replace the root page's configured domain.
        return $this->rootContext?->domain() ?? $this->fromRequest() ?? $this->store->verifiedHost();
    }

    /** Whether the current answer comes from a live, trusted HTTP request. */
    public function isRequestBound(): bool
    {
        return null !== $this->rootContext?->domain() || null !== $this->fromRequest();
    }
}
