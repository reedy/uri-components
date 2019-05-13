<?php

/**
 * League.Uri (http://uri.thephpleague.com/components)
 *
 * @package    League\Uri
 * @subpackage League\Uri\Components
 * @author     Ignace Nyamagana Butera <nyamsprod@gmail.com>
 * @license    https://github.com/thephpleague/uri-components/blob/master/LICENSE (MIT License)
 * @version    2.0.0
 * @link       https://github.com/thephpleague/uri-components
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace League\Uri\Component;

use Iterator;
use League\Uri\Contract\PathInterface;
use League\Uri\Contract\SegmentedPathInterface;
use League\Uri\Contract\UriComponentInterface;
use League\Uri\Exception\OffsetOutOfBounds;
use League\Uri\Exception\PathTypeNotFound;
use League\Uri\Exception\SyntaxError;
use TypeError;
use function array_count_values;
use function array_filter;
use function array_keys;
use function array_pop;
use function array_unshift;
use function count;
use function dirname;
use function end;
use function explode;
use function implode;
use function is_scalar;
use function ltrim;
use function method_exists;
use function rtrim;
use function sprintf;
use function str_replace;
use function strpos;
use function strrpos;
use function substr;
use const ARRAY_FILTER_USE_KEY;
use const FILTER_VALIDATE_INT;
use const PATHINFO_EXTENSION;

final class HierarchicalPath extends Component implements SegmentedPathInterface
{
    /**
     * @var PathInterface
     */
    private $path;

    /**
     * @var string[]
     */
    private $segments;

    /**
     * Returns a new instance from an iterable structure.
     *
     * @param int $type one of the constant IS_ABSOLUTE or IS_RELATIVE
     *
     * @throws PathTypeNotFound If the type is not recognized
     * @throws TypeError        If the segments are malformed
     */
    public static function createFromSegments(iterable $segments, int $type = self::IS_RELATIVE): self
    {
        static $type_list = [self::IS_ABSOLUTE => 1, self::IS_RELATIVE => 1];

        if (!isset($type_list[$type])) {
            throw new PathTypeNotFound(sprintf('"%s" is an invalid or unsupported %s type', $type, self::class));
        }

        $pathSegments = [];
        foreach ($segments as $value) {
            if (!is_scalar($value) && !method_exists($value, '__toString')) {
                throw new TypeError('The submitted segments are invalid');
            }
            $pathSegments[] = (string) $value;
        }

        $path = implode(self::SEPARATOR, $pathSegments);
        if (static::IS_ABSOLUTE !== $type) {
            return new self(ltrim($path, self::SEPARATOR));
        }

        if (self::SEPARATOR !== ($path[0] ?? '')) {
            return new self(self::SEPARATOR.$path);
        }

        return new self($path);
    }

    /**
     * {@inheritDoc}
     */
    public static function __set_state(array $properties): self
    {
        return new self($properties['path']);
    }

    /**
     * New instance.
     * 
     * @param mixed|string $path
     */
    public function __construct($path = '')
    {
        if (!$path instanceof PathInterface) {
            $path = new Path($path);
        }

        $this->path = $path;
        $segments = (string) $this->decodeComponent($path->__toString());
        if ($this->path->isAbsolute()) {
            $segments = substr($segments, 1);
        }

        $this->segments = explode(self::SEPARATOR, $segments);
    }

    /**
     * {@inheritDoc}
     */
    public function count(): int
    {
        return count($this->segments);
    }

    /**
     * {@inheritDoc}
     */
    public function getIterator(): Iterator
    {
        foreach ($this->segments as $segment) {
            yield $segment;
        }
    }

    /**
     * {@inheritDoc}
     */
    public function isAbsolute(): bool
    {
        return $this->path->isAbsolute();
    }

    /**
     * {@inheritDoc}
     */
    public function getContent(): ?string
    {
        return $this->path->getContent();
    }

    /**
     * {@inheritDoc}
     */
    public function getDirname(): string
    {
        $path = (string) $this->decodeComponent($this->path->__toString());

        return str_replace(
            ['\\', "\0"],
            [self::SEPARATOR, '\\'],
            dirname(str_replace('\\', "\0", $path))
        );
    }

    /**
     * {@inheritDoc}
     */
    public function getBasename(): string
    {
        $data = $this->segments;

        return (string) array_pop($data);
    }

    /**
     * {@inheritDoc}
     */
    public function getExtension(): string
    {
        [$basename, ] = explode(';', $this->getBasename(), 2);

        return pathinfo($basename, PATHINFO_EXTENSION);
    }

    /**
     * {@inheritDoc}
     */
    public function get(int $offset): ?string
    {
        if ($offset < 0) {
            $offset += count($this->segments);
        }

        return $this->segments[$offset] ?? null;
    }

    /**
     * {@inheritDoc}
     */
    public function keys(string $segment): array
    {
        return array_keys($this->segments, $segment, true);
    }

    /**
     * {@inheritDoc}
     */
    public function withoutDotSegments(): PathInterface
    {
        $path = $this->path->withoutDotSegments();
        if ($path !== $this->path) {
            return new self($path);
        }

        return $this;
    }

    /**
     * {@inheritDoc}
     */
    public function withLeadingSlash(): PathInterface
    {
        $path = $this->path->withLeadingSlash();
        if ($path !== $this->path) {
            return new self($path);
        }

        return $this;
    }

    /**
     * {@inheritDoc}
     */
    public function withoutLeadingSlash(): PathInterface
    {
        $path = $this->path->withoutLeadingSlash();
        if ($path !== $this->path) {
            return new self($path);
        }

        return $this;
    }

    /**
     * {@inheritDoc}
     */
    public function withContent($content): UriComponentInterface
    {
        $content = self::filterComponent($content);
        if ($content === $this->path->getContent()) {
            return $this;
        }

        return new self($content);
    }

    /**
     * @inheritDoc
     *
     * @param mixed|string $segment
     */
    public function append($segment): SegmentedPathInterface
    {
        $segment = self::filterComponent($segment);
        if (null === $segment) {
            throw new TypeError('The appended path can not be null');
        }

        return new self(
            rtrim($this->path->__toString(), self::SEPARATOR)
            .self::SEPARATOR
            .ltrim($segment, self::SEPARATOR)
        );
    }

    /**
     * @inheritDoc
     *
     * @param mixed|string $segment
     */
    public function prepend($segment): SegmentedPathInterface
    {
        $segment = self::filterComponent($segment);
        if (null === $segment) {
            throw new TypeError('The prepended path can not be null');
        }

        return new self(
            rtrim($segment, self::SEPARATOR)
            .self::SEPARATOR
            .ltrim($this->path->__toString(), self::SEPARATOR)
        );
    }

    /**
     * @inheritDoc
     * 
     * @param mixed|string $segment
     */
    public function withSegment(int $key, $segment): SegmentedPathInterface
    {
        $nb_segments = count($this->segments);
        if ($key < - $nb_segments - 1 || $key > $nb_segments) {
            throw new OffsetOutOfBounds(sprintf('the given key `%s` is invalid', $key));
        }

        if (0 > $key) {
            $key += $nb_segments;
        }

        if ($nb_segments === $key) {
            return $this->append($segment);
        }

        if (-1 === $key) {
            return $this->prepend($segment);
        }

        if (!$segment instanceof PathInterface) {
            $segment = new self($segment);
        }

        $segment = $this->decodeComponent((string) $segment);
        if ($segment === $this->segments[$key]) {
            return $this;
        }

        $segments = $this->segments;
        $segments[$key] = $segment;
        if ($this->isAbsolute()) {
            array_unshift($segments, '');
        }

        return new self(implode(self::SEPARATOR, $segments));
    }

    /**
     * {@inheritDoc}
     */
    public function withoutEmptySegments(): SegmentedPathInterface
    {
        return new self(preg_replace(',/+,', self::SEPARATOR, $this->__toString()));
    }

    /**
     * {@inheritDoc}
     */
    public function withoutSegment(int $key, int ...$keys): SegmentedPathInterface
    {
        $keys[] = $key;
        $nb_segments = count($this->segments);
        $options = ['options' => ['min_range' => - $nb_segments, 'max_range' => $nb_segments - 1]];
        $deleted_keys = [];
        foreach ($keys as $value) {
            if (false === ($offset = filter_var($value, FILTER_VALIDATE_INT, $options))) {
                throw new OffsetOutOfBounds(sprintf('the key `%s` is invalid', $value));
            }

            if ($offset < 0) {
                $offset += $nb_segments;
            }
            $deleted_keys[] = $offset;
        }

        $deleted_keys = array_keys(array_count_values($deleted_keys));
        $filter = static function ($key) use ($deleted_keys): bool {
            return !in_array($key, $deleted_keys, true);
        };

        $path = implode(self::SEPARATOR, array_filter($this->segments, $filter, ARRAY_FILTER_USE_KEY));
        if ($this->isAbsolute()) {
            return new self(self::SEPARATOR.$path);
        }

        return new self($path);
    }

    /**
     * @inheritDoc
     * 
     * @param mixed|string $path
     */
    public function withDirname($path): SegmentedPathInterface
    {
        if (!$path instanceof PathInterface) {
            $path = new Path($path);
        }

        if ($path->getContent() === $this->getDirname()) {
            return $this;
        }

        return new self(
            rtrim($path->__toString(), self::SEPARATOR)
            .self::SEPARATOR
            .array_pop($this->segments)
        );
    }

    /**
     * {@inheritDoc}
     */
    public function withBasename($basename): SegmentedPathInterface
    {
        $basename = $this->validateComponent($basename);
        if (null === $basename) {
            throw new SyntaxError('a basename sequence can not be null');
        }

        if (false !== strpos($basename, self::SEPARATOR)) {
            throw new SyntaxError('The basename can not contain the path separator');
        }

        return $this->withSegment(count($this->segments) - 1, $basename);
    }

    /**
     * {@inheritDoc}
     */
    public function withExtension($extension): SegmentedPathInterface
    {
        $extension = $this->validateComponent($extension);
        if (null === $extension) {
            throw new SyntaxError('an extension sequence can not be null');
        }

        if (false !== strpos($extension, self::SEPARATOR)) {
            throw new SyntaxError('an extension sequence can not contain a path delimiter');
        }

        if (0 === strpos($extension, '.')) {
            throw new SyntaxError('an extension sequence can not contain a leading `.` character');
        }

        $basename = end($this->segments);
        [$ext, $param] = explode(';', $basename, 2) + [1 => null];
        if ('' === $ext) {
            return $this;
        }

        return $this->withBasename($this->buildBasename($extension, $ext, $param));
    }

    /**
     * Creates a new basename with a new extension.
     */
    private function buildBasename(string $extension, string $ext, string $param = null): string
    {
        $length = strrpos($ext, '.'.pathinfo($ext, PATHINFO_EXTENSION));
        if (false !== $length) {
            $ext = substr($ext, 0, $length);
        }

        if (null !== $param && '' !== $param) {
            $param = ';'.$param;
        }

        $extension = trim($extension);
        if ('' === $extension) {
            return $ext.$param;
        }

        return $ext.'.'.$extension.$param;
    }
}