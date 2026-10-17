<?php

declare(strict_types=1);

namespace DocsMcp\Domain\Drive;

/**
 * MIME types that the access policy and ports recognise.
 */
final class DriveMimeType
{
    public const string DOCUMENT = 'application/vnd.google-apps.document';

    public const string FOLDER = 'application/vnd.google-apps.folder';

    public const string SHORTCUT = 'application/vnd.google-apps.shortcut';

    private function __construct() {}
}
