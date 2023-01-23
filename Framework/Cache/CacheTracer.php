<?php declare(strict_types=1);

namespace Shopware\Storefront\Framework\Cache;

use Shopware\Core\Framework\Adapter\Cache\AbstractCacheTracer;
use Shopware\Storefront\Theme\ThemeConfigValueAccessor;

/**
 * @package storefront
 *
 * @extends AbstractCacheTracer<mixed|null>
 */
class CacheTracer extends AbstractCacheTracer
{
    /**
     * @internal
     *
     * @param AbstractCacheTracer<mixed|null> $decorated
     */
    public function __construct(private readonly AbstractCacheTracer $decorated, private readonly ThemeConfigValueAccessor $themeConfigAccessor)
    {
    }

    public function getDecorated(): AbstractCacheTracer
    {
        return $this->decorated;
    }

    public function trace(string $key, \Closure $param)
    {
        return $this->themeConfigAccessor->trace($key, fn () => $this->getDecorated()->trace($key, $param));
    }

    public function get(string $key): array
    {
        return array_unique(array_merge(
            $this->themeConfigAccessor->getTrace($key),
            $this->getDecorated()->get($key)
        ));
    }
}
