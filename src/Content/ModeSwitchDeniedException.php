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

namespace Vtinnovations\ContaoMultilingualPagetree\Content;

/**
 * A content mode change was rejected.
 *
 * Contao shows the message next to the field and keeps the stored value, so a
 * cancelled or unauthorised switch leaves the configuration unchanged.
 */
final class ModeSwitchDeniedException extends \RuntimeException
{
    public const REASON_DENIED = 'denied';
    public const REASON_TOKEN = 'invalid_token';
    public const REASON_UNCONFIRMED = 'unconfirmed';

    private function __construct(string $message, public readonly string $reason)
    {
        parent::__construct($message);
    }

    public static function denied(string $message): self
    {
        return new self($message, self::REASON_DENIED);
    }

    public static function invalidToken(string $message): self
    {
        return new self($message, self::REASON_TOKEN);
    }

    public static function unconfirmed(string $message): self
    {
        return new self($message, self::REASON_UNCONFIRMED);
    }
}
