<?php

declare(strict_types=1);

namespace MediaWiki\Extension\InstantIIIF\Tests\Integration;

use MediaWiki\Extension\InstantIIIF\Infrastructure\MediaWiki\Repo;
use MediaWiki\Registration\ExtensionRegistry;
use MediaWikiIntegrationTestCase;
use PHPUnit\Framework\Attributes\CoversNothing;

/**
 * Smoke test verifying that the InstantIIIF extension is registered and
 * autoloads inside a real MediaWiki environment.
 *
 * Other Integration tests can rely on this passing as a precondition.
 */
#[CoversNothing]
class SmokeTest extends MediaWikiIntegrationTestCase
{
    public function testExtensionRegistered(): void
    {
        self::assertTrue(
            ExtensionRegistry::getInstance()->isLoaded('InstantIIIF'),
            'InstantIIIF extension is not loaded — check LocalSettings.php'
        );
    }

    public function testRepoClassAutoloads(): void
    {
        self::assertTrue(
            class_exists(Repo::class),
            sprintf('%s did not autoload', Repo::class)
        );
    }
}
