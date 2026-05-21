<?php

/**
 * Stub definitions for MediaWiki global (non-namespaced) classes.
 * Used by the standalone PHPUnit test bootstrap.
 */

declare(strict_types=1);

if (!class_exists(FileRepo::class)) {
    class FileRepo
    {
        /** @var array<string, mixed> */
        protected $info;

        public function __construct(array $info = [])
        {
            $this->info = $info;
        }

        public function getName(): string
        {
            return $this->info['name'] ?? '';
        }
    }
}

if (!class_exists(MediaTransformOutput::class)) {
    class MediaTransformOutput
    {
    }
}

if (!class_exists(MediaHandler::class)) {
    abstract class MediaHandler
    {
        public function getParamMap(): array
        {
            return [];
        }

        public function validateParam($name, $value): bool
        {
            return false;
        }

        public function makeParamString($params): string|false
        {
            return false;
        }

        public function parseParamString($str): array|false
        {
            return false;
        }

        public function getSizeAndMetadata($state, $path): array
        {
            return [];
        }

        public function mustRender($file): bool
        {
            return false;
        }

        public function isExpensiveToThumbnail($file): bool
        {
            return false;
        }

        abstract public function doTransform($image, $dstPath, $dstUrl, $params, $flags = 0);
    }
}

if (!class_exists(ImageHandler::class)) {
    abstract class ImageHandler extends MediaHandler
    {
        protected function getScriptParams($params): array
        {
            return [];
        }
    }
}

if (!class_exists(File::class)) {
    class File
    {
        /** @var FileRepo */
        public $repo;

        /** @var MediaHandler|false */
        protected $handler;

        /** @var \MediaWiki\Title\Title|null */
        protected ?\MediaWiki\Title\Title $title = null;

        public function __construct(?\MediaWiki\Title\Title $title = null, ?FileRepo $repo = null, $time = false)
        {
            if ($title !== null) {
                $this->title = $title;
            }
            if ($repo !== null) {
                $this->repo = $repo;
            }
        }

        public function exists(): bool
        {
            return true;
        }

        public function getSize(): int
        {
            return 0;
        }

        public function getMimeType(): string
        {
            return 'image/jpeg';
        }

        public function getMediaType(): string
        {
            return MEDIATYPE_BITMAP;
        }

        public function isMultipage(): bool
        {
            return false;
        }

        public function pageCount(): int
        {
            return 1;
        }

        /** @return MediaHandler|false */
        public function getHandler()
        {
            return $this->handler;
        }

        public function getWidth($page = 1): int
        {
            return 0;
        }

        public function getHeight($page = 1): int
        {
            return 0;
        }

        public function getUrl(): string
        {
            return '';
        }

        public function getFullUrl(): string
        {
            return '';
        }

        public function getDescriptionUrl(): string
        {
            return '';
        }

        public function getTitle(): ?\MediaWiki\Title\Title
        {
            return $this->title;
        }

        public function transform($params, $flags = 0)
        {
            return null;
        }
    }
}

if (!class_exists(ThumbnailImage::class)) {
    class ThumbnailImage extends MediaTransformOutput
    {
        private File $file;
        private string $url;
        /** @var array<string, mixed> */
        private array $parameters;

        public function __construct(File $file, string $url, $path = false, array $parameters = [])
        {
            $this->file = $file;
            $this->url = $url;
            $this->parameters = $parameters;
        }

        public function getFile(): File
        {
            return $this->file;
        }

        public function getWidth(): int
        {
            return (int) ($this->parameters['width'] ?? 0);
        }

        public function getHeight(): int
        {
            return (int) ($this->parameters['height'] ?? 0);
        }

        public function getUrl(): string
        {
            return $this->url;
        }

        /** @return array<string, mixed> */
        public function getParameters(): array
        {
            return $this->parameters;
        }
    }
}

if (!class_exists(MediaTransformError::class)) {
    class MediaTransformError extends MediaTransformOutput
    {
        public function __construct(string $msg = '', int $width = 0, int $height = 0, string $path = '')
        {
        }
    }
}

if (!class_exists(MWNamespace::class)) {
    class MWNamespace
    {
        public static function getCanonicalName(int $index): string|false
        {
            return match ($index) {
                NS_FILE => 'File',
                default => false,
            };
        }
    }
}

if (!class_exists(OutputPage::class)) {
    class OutputPage
    {
        /** @var string[] */
        public array $modules = [];
        /** @var string[] */
        public array $inlineStyles = [];
        /** @var array<string, mixed> */
        public array $jsConfigVars = [];
        private ?\MediaWiki\Title\Title $title = null;

        public function setTitle(\MediaWiki\Title\Title $title): void
        {
            $this->title = $title;
        }

        public function addModules($modules): void
        {
            $this->modules = array_merge($this->modules, (array) $modules);
        }

        public function addInlineStyle(string $style): void
        {
            $this->inlineStyles[] = $style;
        }

        public function getTitle(): ?\MediaWiki\Title\Title
        {
            return $this->title;
        }

        public function addJsConfigVars(string $name, mixed $value): void
        {
            $this->jsConfigVars[$name] = $value;
        }
    }
}

if (!class_exists(Skin::class)) {
    class Skin
    {
    }
}

if (!class_exists(StatusValue::class)) {
    class StatusValue
    {
        public function __construct(private bool $ok = true)
        {
        }

        public function isOK(): bool
        {
            return $this->ok;
        }
    }
}

if (!class_exists(GlobalVarConfig::class)) {
    class GlobalVarConfig
    {
        public function get(string $name): mixed
        {
            return match ($name) {
                'InstantIIIFDefaultTimeout' => 5,
                default => null,
            };
        }
    }
}

if (!class_exists(WANObjectCache::class)) {
    class WANObjectCache
    {
        public function makeKey(string ...$components): string
        {
            return implode(':', $components);
        }

        public function getWithSetCallback(string $key, int $ttl, callable $callback, array $opts = []): mixed
        {
            return $callback();
        }
    }
}

if (!class_exists(MWHttpRequest::class)) {
    class MWHttpRequest
    {
        private string $content = '';
        private bool $ok = true;

        public function execute(): StatusValue
        {
            return new StatusValue($this->ok);
        }

        public function getContent(): string
        {
            return $this->content;
        }
    }
}

if (!class_exists(RepoGroup::class)) {
    class RepoGroup
    {
        public function findFile($title, array $options = [])
        {
            return false;
        }
    }
}
