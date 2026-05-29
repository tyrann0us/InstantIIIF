<?php

declare(strict_types=1);

namespace MediaWiki\Extension\InstantIIIF\Infrastructure\MediaWiki;

use Config;
use HTMLForm;
use MediaWiki\Html\Html;
use MediaWiki\MainConfigNames;
use MediaWiki\Title\Title;
use RepoGroup;
use SpecialPage;

/**
 * Diagnostic Special page that previews what InstantIIIF extracts from
 * a given IIIF manifest URL.
 *
 * Intended for admins adding a new provider, debugging missing metadata
 * (a blank credit, wrong license link, missing landing page), or
 * sanity-checking that a manifest is reachable from the wiki. The page
 * deliberately reuses the production IIIFFile + HookHandler code path — what
 * you see here is what the parser, MMV, and the VE search will see.
 *
 * Read-only and side-effect-free aside from the manifest fetch (which
 * goes through the same WAN cache as normal lookups).
 */
class SpecialInstantIIIFInspect extends SpecialPage
{
    /** Synthetic title used to spin up an IIIFFile for inspection. */
    private const INSPECT_DBKEY = 'InstantIIIFInspect';

    /** Provider IDs that have provider-specific metadata fallbacks. */
    private const KNOWN_PROVIDER_IDS = [
        'deutsche-fotothek',
        'slub-dresden',
        'digitale-sammlungen',
    ];

    public function __construct(
        private RepoGroup $repoGroup,
        private Config $config,
        private MetadataExtractor $metadataExtractor
    ) {

        parent::__construct('InstantIIIFInspect', 'instantiiif-inspect');
    }

    // phpcs:ignore Syde.Classes.DisallowGetterSetter.GetterFound, Syde.Functions.ReturnTypeDeclaration.NoReturnType -- SpecialPage::getDescription override
    public function getDescription(): \Message
    {
        return $this->msg('instantiiif-inspect-title');
    }

    // phpcs:ignore Syde.Classes.DisallowGetterSetter.GetterFound -- SpecialPage::getGroupName override
    protected function getGroupName(): string
    {
        return 'wiki';
    }

    /**
     * @param string|null $par
     */
    // phpcs:ignore Syde.Functions.ArgumentTypeDeclaration.NoArgumentType -- SpecialPage::execute signature
    public function execute($par): void
    {
        $this->setHeaders();
        $this->checkPermissions();

        $out = $this->getOutput();
        $out->addWikiMsg('instantiiif-inspect-intro');

        $form = $this->buildForm();
        $form->show();

        $url = trim((string) $this->getRequest()->getVal('wpManifestUrl', ''));
        if ($url !== '' && $this->isPlausibleHttpUrl($url)) {
            $provider = (string) $this->getRequest()->getVal('wpProviderId', '');
            $this->renderInspection($url, $provider);
        }
    }

    private function buildForm(): HTMLForm
    {
        $providerOptions = ['(none / generic)' => ''];
        foreach (self::KNOWN_PROVIDER_IDS as $id) {
            $providerOptions[$id] = $id;
        }

        $form = HTMLForm::factory('ooui', [
            'ManifestUrl' => [
                'type' => 'url',
                'label-message' => 'instantiiif-inspect-url',
                'help-message' => 'instantiiif-inspect-url-help',
                'required' => true,
                'size' => 80,
            ],
            'ProviderId' => [
                'type' => 'select',
                'label-message' => 'instantiiif-inspect-provider',
                'help-message' => 'instantiiif-inspect-provider-help',
                'options' => $providerOptions,
                'default' => '',
            ],
        ], $this->getContext());

        $form->setMethod('get');
        $form->setSubmitTextMsg('instantiiif-inspect-submit');
        $form->setWrapperLegendMsg('instantiiif-inspect-title');
        // No-op callback — the inspection is driven directly from the
        // GET parameters in execute() so admins can bookmark/share the
        // results URL.
        $form->setSubmitCallback(static fn () => true);

        return $form;
    }

    private function renderInspection(string $manifestUrl, string $providerId): void
    {
        $out = $this->getOutput();

        $file = $this->buildInspectorFile($manifestUrl, $providerId);
        $resolved = $file->getResolvedManifest();
        if ($resolved === null) {
            $out->addHTML(Html::errorBox(
                $this->msg(
                    'instantiiif-inspect-fetch-failed',
                    $manifestUrl
                )->parse()
            ));
            return;
        }

        $meta = $this->metadataExtractor->extract($file, $this->getContext());

        $out->addHTML(Html::element('h2', [], $this->msg(
            'instantiiif-inspect-results-title'
        )->text()));

        $out->addHTML($this->renderSummaryTable($file, $resolved, $meta));
        $out->addHTML($this->renderCanvasTable($file));
    }

    private function buildInspectorFile(string $manifestUrl, string $providerId): IIIFFile
    {
        // Reuse the wiki's local-repo backend so FileRepo's required
        // 'backend' field is satisfied. The IIIF file is virtual and
        // never reads from the backend — we only need it for the
        // parent ::__construct() assertion.
        $localBackend = $this->repoGroup
            ->getLocalRepo()
            ->getBackend();

        $repo = new Repo([
            'name' => 'iiif-inspector',
            'class' => Repo::class,
            'backend' => $localBackend,
            'directory' => (string) $this->config->get(MainConfigNames::UploadDirectory),
            'hashLevels' => 0,
            'iiifSources' => [
                [
                    'id' => $providerId !== '' ? $providerId : 'inspector',
                    // No idPattern — catch-all so our synthetic title
                    // resolves. The URL is used verbatim because it
                    // doesn't contain $1.
                    'manifestPattern' => $manifestUrl,
                ],
            ],
        ]);

        $title = Title::makeTitleSafe(NS_FILE, self::INSPECT_DBKEY);
        if ($title === null) {
            throw new \RuntimeException('Failed to build inspector title');
        }
        return new IIIFFile($repo, $title);
    }

    /**
     * @param array<string, mixed> $resolved
     * @param array<string, array{value: string, source: string}> $meta
     */
    private function renderSummaryTable(
        IIIFFile $file,
        array $resolved,
        array $meta
    ): string {

        $rows = [
            'instantiiif-inspect-row-manifest-url' => $resolved['manifestUrl'] ?? '',
            'instantiiif-inspect-row-provider-id' => $resolved['provider'] ?? '',
            'instantiiif-inspect-row-page-count' => (string) $file->pageCount(),
            'instantiiif-inspect-row-label' => $this->metaValue($meta, 'ObjectName'),
            'instantiiif-inspect-row-attribution' => $this->metaValue($meta, 'Attribution'),
            'instantiiif-inspect-row-credit' => $this->metaValue($meta, 'Credit'),
            'instantiiif-inspect-row-license-url' => $this->metaValue($meta, 'LicenseUrl'),
            'instantiiif-inspect-row-license-short' => $this->metaValue($meta, 'LicenseShortName'),
            'instantiiif-inspect-row-provider-url' => $file->getProviderUrl(),
        ];

        $html = Html::openElement('table', ['class' => 'wikitable']);
        foreach ($rows as $msgKey => $value) {
            $html .= Html::openElement('tr');
            $html .= Html::element(
                'th',
                ['style' => 'text-align: left; vertical-align: top; white-space: nowrap;'],
                $this->msg($msgKey)->text()
            );
            $html .= Html::rawElement(
                'td',
                ['style' => 'word-break: break-all;'],
                $this->formatValue($value)
            );
            $html .= Html::closeElement('tr');
        }
        $html .= Html::closeElement('table');
        return $html;
    }

    private function renderCanvasTable(IIIFFile $file): string
    {
        $count = $file->pageCount();
        if ($count <= 0) {
            return '';
        }

        $html = Html::element('h3', [], $this->msg(
            'instantiiif-inspect-canvases-title',
            $count
        )->text());

        $html .= Html::openElement('table', ['class' => 'wikitable sortable']);
        $html .= Html::openElement('tr');
        $html .= Html::element('th', [], $this->msg('instantiiif-inspect-col-page')->text());
        $html .= Html::element('th', [], $this->msg('instantiiif-inspect-col-dimensions')->text());
        $html .= Html::element('th', [], $this->msg('instantiiif-inspect-col-service-id')->text());
        $html .= Html::closeElement('tr');

        // Cap at a sensible upper bound so a 5000-page manifest doesn't
        // turn the page into a memory hog. Admins debugging deeper than
        // 100 pages can re-target with a different file URL.
        $limit = min($count, 100);
        for ($page = 1; $page <= $limit; $page++) {
            [$width, $height] = $file->getCanvasDimensions($page);
            $serviceId = $file->getServiceIdForPage($page) ?? '';

            $html .= Html::openElement('tr');
            $html .= Html::element('td', [], (string) $page);
            $html .= Html::element(
                'td',
                [],
                $width && $height ? sprintf('%d × %d', $width, $height) : '—'
            );
            $html .= Html::element(
                'td',
                ['style' => 'word-break: break-all; font-family: monospace;'],
                $serviceId !== '' ? $serviceId : '—'
            );
            $html .= Html::closeElement('tr');
        }
        $html .= Html::closeElement('table');

        if ($limit < $count) {
            $html .= Html::element('p', ['class' => 'mw-message-box-notice'], $this->msg(
                'instantiiif-inspect-canvas-truncated',
                $limit,
                $count
            )->text());
        }

        return $html;
    }

    /**
     * @param array<string, array{value: string, source: string}> $meta
     */
    private function metaValue(array $meta, string $key): string
    {
        $entry = $meta[$key] ?? null;
        if (!is_array($entry)) {
            return '';
        }
        $value = $entry['value'] ?? '';
        // MetadataExtractor stashes IIIFFile::NO_TIMESTAMP_SENTINEL on the
        // DateTime field to suppress MMV's "Uploaded" line. Surface that
        // as "(suppressed)" rather than rendering the raw sentinel.
        if ($value === IIIFFile::NO_TIMESTAMP_SENTINEL) {
            return '(suppressed)';
        }
        return (string) $value;
    }

    /**
     * Linkify URL-shaped values; show everything else as plain text.
     * HTML snippets in `Credit` are escaped so the inspector never
     * renders untrusted markup.
     */
    private function formatValue(string $value): string
    {
        if ($value === '') {
            return Html::element('em', [], $this->msg('instantiiif-inspect-empty')->text());
        }
        if (preg_match('~^https?://~', $value)) {
            return Html::element('a', ['href' => $value, 'rel' => 'noopener'], $value);
        }
        return Html::element('code', [], $value);
    }

    private function isPlausibleHttpUrl(string $url): bool
    {
        return (bool) preg_match('~^https?://[^\s]+$~i', $url);
    }

    /** @return list<string> */
    public static function knownProviderIds(): array
    {
        return self::KNOWN_PROVIDER_IDS;
    }
}
