<?php

namespace App;

use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

class Kernel extends BaseKernel
{
    use MicroKernelTrait {
        getCacheDir as private microKernelCacheDir;
    }

    /**
     * A test kernel booted without debug (the error page tests) never checks
     * its cache for freshness, so it gets a directory of its own instead of
     * sharing var/cache/test with the debug kernel. The share dir
     * (var/share/test) stays shared. APP_CACHE_DIR still applies through
     * MicroKernelTrait.
     */
    public function getCacheDir(): string
    {
        $dir = $this->microKernelCacheDir();

        return 'test' === $this->environment && !$this->debug ? $dir.'_nodebug' : $dir;
    }
}
