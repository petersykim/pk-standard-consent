<?php

declare(strict_types=1);

namespace PK\StandardConsent;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

enum Category: string
{
    case Necessary   = 'necessary';
    case Preferences = 'preferences';
    case Analytics   = 'analytics';
    case Marketing   = 'marketing';

    public function isGateable(): bool
    {
        return self::Necessary !== $this;
    }
}
