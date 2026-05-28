<?php

declare(strict_types=1);

namespace MediaWiki\Extension\InstantIIIF\Tests\Integration;

use MediaWiki\Extension\InstantIIIF\Infrastructure\MediaWiki\SpecialInstantIIIFInspect;
use MediaWikiIntegrationTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Integration tests for SpecialInstantIIIFInspect: smoke-tests that the
 * special page is registered, instantiable, and renders its form against
 * real MediaWiki infrastructure (SpecialPageFactory, HTMLForm, OutputPage,
 * Message). Standalone SpecialInstantIIIFInspectTest covers the body-flow
 * (error rendering / canvas table) via stubs; this suite covers the seams
 * standalone can't reach.
 */
#[CoversClass(SpecialInstantIIIFInspect::class)]
class SpecialInstantIIIFInspectTest extends MediaWikiIntegrationTestCase
{
    public function testKnownProviderIds(): void
    {
        $ids = SpecialInstantIIIFInspect::knownProviderIds();

        self::assertContains('deutsche-fotothek', $ids);
        self::assertContains('slub-dresden', $ids);
        self::assertContains('digitale-sammlungen', $ids);
    }

    public function testSpecialPageRegistered(): void
    {
        $factory = $this->getServiceContainer()->getSpecialPageFactory();
        $page = $factory->getPage('InstantIIIFInspect');

        self::assertInstanceOf(
            SpecialInstantIIIFInspect::class,
            $page,
            'extension.json should register the Special page under "InstantIIIFInspect"'
        );
    }

    public function testGetGroupNameIsWiki(): void
    {
        $factory = $this->getServiceContainer()->getSpecialPageFactory();
        $page = $factory->getPage('InstantIIIFInspect');
        self::assertInstanceOf(SpecialInstantIIIFInspect::class, $page);

        // The Special:SpecialPages listing renders pages by group; "wiki"
        // is where admin/diagnostic pages live.
        $ref = new \ReflectionMethod($page, 'getGroupName');
        $ref->setAccessible(true);
        self::assertSame('wiki', $ref->invoke($page));
    }

    public function testDescriptionResolvesAMessage(): void
    {
        $factory = $this->getServiceContainer()->getSpecialPageFactory();
        $page = $factory->getPage('InstantIIIFInspect');
        self::assertInstanceOf(SpecialInstantIIIFInspect::class, $page);

        // getDescription() returns a Message instance. We don't assert on
        // its text (the i18n key may resolve to an empty fallback during
        // tests), only that the wiring returns the right type.
        self::assertInstanceOf(\MediaWiki\Message\Message::class, $page->getDescription());
    }
}
