<?php

declare(strict_types=1);

namespace MediaWiki\Extension\InstantIIIF;

use FileRepo;
use MediaWiki\Title\Title;

class Repo extends FileRepo
{
    /** @var array<int, array<string, mixed>> */
    private array $iiifSources = [];

    /**
     * @param array<string, mixed> $info
     */
    public function __construct(array $info)
    {
        // FileBackendGroup requires 'directory' for auto-backend creation;
        // IIIF has no local storage, so we default to the upload dir.
        if (!isset($info['directory'])) {
            $info['directory'] = $GLOBALS['wgUploadDirectory'] ?? '';
        }
        parent::__construct($info);
        $this->iiifSources = $info['iiifSources'] ?? [];
    }

    /**
     * @inheritDoc
     * @param Title|string $title
     * @param string|false $time
     */
    // phpcs:ignore Syde.Functions.ArgumentTypeDeclaration.NoArgumentType -- MediaWiki FileRepo override
    public function newFile($title, $time = false): IIIFFile
    {
        if (!$title instanceof Title) {
            $title = Title::newFromText((string) $title);
        }
        if ($title === null) {
            throw new \InvalidArgumentException('Invalid title provided');
        }
        return new IIIFFile($this, $title, $time);
    }

    /**
     * Get provider configuration from the repository.
     *
     * @return array<int, array<string, mixed>>
     */
    public function iiifSources(): array
    {
        return $this->iiifSources;
    }
}
