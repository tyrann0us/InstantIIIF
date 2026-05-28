<?php

/**
 * Stub definitions for MediaWiki namespaced classes.
 * Each namespace block is wrapped in class_exists guards.
 *
 * Used by the standalone PHPUnit test bootstrap.
 */

declare(strict_types=1);

// ─── MediaWiki\Title ──────────────────────────────────────────────

namespace MediaWiki\Title {
    if (!class_exists(Title::class)) {
        class Title
        {
            private string $dbKey;
            private int $namespace;
            private string $nsText;

            public function __construct(string $dbKey = '', int $namespace = 0, string $nsText = 'File')
            {
                $this->dbKey = $dbKey;
                $this->namespace = $namespace;
                $this->nsText = $nsText;
            }

            public static function newFromText(string $text, int $defaultNamespace = 0): ?self
            {
                return new self($text, $defaultNamespace);
            }

            public static function makeTitleSafe(int $ns, string $title): ?self
            {
                if ($title === '') {
                    return null;
                }
                $nsText = match ($ns) {
                    6 => 'File',
                    default => '',
                };
                return new self($title, $ns, $nsText);
            }

            public function getDBkey(): string
            {
                return $this->dbKey;
            }

            public function getNsText(): string
            {
                return $this->nsText;
            }

            public function getNamespace(): int
            {
                return $this->namespace;
            }

            /** @param string|string[] $query */
            public function getFullURL($query = '', $query2 = false, $proto = PROTO_RELATIVE): string
            {
                $base = 'https://wiki.example.org/wiki/' . $this->nsText . ':' . $this->dbKey;
                $q = is_array($query) ? http_build_query($query) : (string) $query;
                return $q === '' ? $base : $base . '?' . $q;
            }

            /** @param string|string[] $query */
            public function getLocalURL($query = '', $query2 = false): string
            {
                $base = '/wiki/' . $this->nsText . ':' . $this->dbKey;
                $q = is_array($query) ? http_build_query($query) : (string) $query;
                return $q === '' ? $base : $base . '?' . $q;
            }

            /** @param string|string[] $query */
            public function getUrl($query = ''): string
            {
                return $this->getLocalURL($query);
            }
        }
    }
}

// ─── MediaWiki (root) ─────────────────────────────────────────────

namespace MediaWiki {
    if (!class_exists(MediaWikiServices::class)) {
        class MediaWikiServices
        {
            private static ?self $instance = null;

            /** @var \RepoGroup|null */
            public static $mockRepoGroup = null;

            /** @var Utils\UrlUtils|null */
            public static ?Utils\UrlUtils $mockUrlUtils = null;

            /** @var \GlobalVarConfig|null */
            public static ?\GlobalVarConfig $mockMainConfig = null;

            /** @var Http\HttpRequestFactory|null */
            public static ?Http\HttpRequestFactory $mockHttpRequestFactory = null;

            public static function getInstance(): self
            {
                if (self::$instance === null) {
                    self::$instance = new self();
                }
                return self::$instance;
            }

            public static function reset(): void
            {
                self::$instance = null;
                self::$mockRepoGroup = null;
                self::$mockUrlUtils = null;
                self::$mockMainConfig = null;
                self::$mockHttpRequestFactory = null;
            }

            public function getRepoGroup(): \RepoGroup
            {
                if (self::$mockRepoGroup !== null) {
                    return self::$mockRepoGroup;
                }
                return new \RepoGroup();
            }

            public function getMainConfig(): \GlobalVarConfig
            {
                if (self::$mockMainConfig !== null) {
                    return self::$mockMainConfig;
                }
                return new \GlobalVarConfig();
            }

            public function getMainWANObjectCache(): \WANObjectCache
            {
                return new \WANObjectCache();
            }

            public function getHttpRequestFactory(): Http\HttpRequestFactory
            {
                if (self::$mockHttpRequestFactory !== null) {
                    return self::$mockHttpRequestFactory;
                }
                return new Http\HttpRequestFactory();
            }

            public function getUrlUtils(): Utils\UrlUtils
            {
                if (self::$mockUrlUtils !== null) {
                    return self::$mockUrlUtils;
                }
                return new Utils\UrlUtils();
            }

            /** Override-able stub so tests can set the wiki's content language. */
            public static string $mockContentLanguageCode = 'en';

            public function getContentLanguage(): \Language
            {
                return new \Language(self::$mockContentLanguageCode);
            }
        }
    }

    if (!class_exists(MainConfigNames::class)) {
        class MainConfigNames
        {
            public const ScriptPath = 'ScriptPath';
            public const Script = 'Script';
            public const UploadDirectory = 'UploadDirectory';
        }
    }
}

// ─── Hook interfaces ──────────────────────────────────────────────

namespace MediaWiki\Output\Hook {
    if (!interface_exists(BeforePageDisplayHook::class)) {
        interface BeforePageDisplayHook
        {
            public function onBeforePageDisplay($out, $skin): void;
        }
    }
}

namespace MediaWiki\Hook {
    if (!interface_exists(ThumbnailBeforeProduceHTMLHook::class)) {
        interface ThumbnailBeforeProduceHTMLHook
        {
            public function onThumbnailBeforeProduceHTML($thumbnail, &$attribs, &$linkAttribs);
        }
    }

    if (!interface_exists(GetExtendedMetadataHook::class)) {
        interface GetExtendedMetadataHook
        {
            public function onGetExtendedMetadata(&$combinedMeta, $file, $context, $single, &$maxCacheTime);
        }
    }
}

namespace MediaWiki\Page\Hook {
    if (!interface_exists(ImagePageFileHistoryLineHook::class)) {
        interface ImagePageFileHistoryLineHook
        {
            public function onImagePageFileHistoryLine($imageHistoryList, $file, &$line, &$css);
        }
    }

    if (!interface_exists(ImagePageShowTOCHook::class)) {
        interface ImagePageShowTOCHook
        {
            public function onImagePageShowTOC($page, &$toc);
        }
    }
}

// ─── MediaWiki\Html ───────────────────────────────────────────────

namespace MediaWiki\Html {
    if (!class_exists(Html::class)) {
        class Html
        {
            /** @param array<string, mixed> $attribs */
            public static function element(string $element, array $attribs = [], string $contents = ''): string
            {
                return '<' . $element . self::attrs($attribs) . '>' . htmlspecialchars($contents, ENT_QUOTES) . '</' . $element . '>';
            }

            /** @param array<string, mixed> $attribs */
            public static function rawElement(string $element, array $attribs = [], string $contents = ''): string
            {
                return '<' . $element . self::attrs($attribs) . '>' . $contents . '</' . $element . '>';
            }

            /** @param array<string, mixed> $attribs */
            public static function openElement(string $element, array $attribs = []): string
            {
                return '<' . $element . self::attrs($attribs) . '>';
            }

            public static function closeElement(string $element): string
            {
                return '</' . $element . '>';
            }

            public static function errorBox(string $html, string $heading = '', string $className = ''): string
            {
                return '<div class="errorbox ' . $className . '">' . ($heading !== '' ? '<h2>' . htmlspecialchars($heading, ENT_QUOTES) . '</h2>' : '') . $html . '</div>';
            }

            /** @param array<string, mixed> $attribs */
            private static function attrs(array $attribs): string
            {
                $out = '';
                foreach ($attribs as $k => $v) {
                    $out .= ' ' . $k . '="' . htmlspecialchars((string) $v, ENT_QUOTES) . '"';
                }
                return $out;
            }
        }
    }
}

// ─── MediaWiki\Utils ──────────────────────────────────────────────

namespace MediaWiki\Utils {
    if (!class_exists(UrlUtils::class)) {
        class UrlUtils
        {
            public function expand(string $url, ?int $defaultProto = null): ?string
            {
                if (preg_match('~^https?://~', $url)) {
                    return $url;
                }
                return 'https://wiki.example.org' . $url;
            }
        }
    }
}

// ─── MediaWiki\Http ───────────────────────────────────────────────

namespace MediaWiki\Http {
    if (!class_exists(HttpRequestFactory::class)) {
        class HttpRequestFactory
        {
            public function create(string $url, array $options = [], ?string $caller = null): \MWHttpRequest
            {
                return new \MWHttpRequest();
            }
        }
    }
}

// ─── MediaWiki\Context ────────────────────────────────────────────

namespace MediaWiki\Context {

    use MediaWiki\Title\Title;

    if (!interface_exists(IContextSource::class)) {
        interface IContextSource
        {
            public function getLanguage(): \Language;

            public function msg(string $key, mixed ...$params): \Message;
        }
    }

    if (!class_exists(RequestContext::class)) {
        class RequestContext implements IContextSource
        {
            private static ?self $instance = null;
            private ?Title $title = null;
            private \WebRequest $request;
            private \Language $language;

            public function __construct()
            {
                $this->request = new \WebRequest();
                $this->language = new \Language('en');
            }

            public function getLanguage(): \Language
            {
                return $this->language;
            }

            public function msg(string $key, mixed ...$params): \Message
            {
                return new \Message($key, $params);
            }

            public function setLanguage(\Language $language): void
            {
                $this->language = $language;
            }

            public static function getMain(): self
            {
                if (self::$instance === null) {
                    self::$instance = new self();
                }
                return self::$instance;
            }

            public static function reset(): void
            {
                self::$instance = null;
            }

            public function setTitle(?Title $title): void
            {
                $this->title = $title;
            }

            public function getTitle(): ?Title
            {
                return $this->title;
            }

            public function setRequest(\WebRequest $request): void
            {
                $this->request = $request;
            }

            public function getRequest(): \WebRequest
            {
                return $this->request;
            }
        }
    }
}

// ─── MediaWiki\Page ───────────────────────────────────────────────

namespace MediaWiki\Page {
    if (!class_exists(ImageHistoryList::class)) {
        class ImageHistoryList
        {
            private \OutputPage $out;

            public function __construct(?\OutputPage $out = null)
            {
                $this->out = $out ?? new \OutputPage();
            }

            public function getOutput(): \OutputPage
            {
                return $this->out;
            }
        }
    }

    if (!class_exists(ImagePage::class)) {
        class ImagePage
        {
            private \File $file;

            public function __construct(\File $file)
            {
                $this->file = $file;
            }

            public function getDisplayedFile(): \File
            {
                return $this->file;
            }
        }
    }
}
