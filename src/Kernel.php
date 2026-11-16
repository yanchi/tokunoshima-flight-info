<?php

namespace App;

use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

class Kernel extends BaseKernel
{
    use MicroKernelTrait;

    /**
     * A test kernel booted without debug (the error page tests) never checks
     * its cache for freshness, so it gets a directory of its own instead of
     * sharing var/cache/test with the debug kernel.
     */
    public function getCacheDir(): string
    {
        if ('test' === $this->environment && !$this->debug) {
            return $this->getProjectDir().'/var/cache/test_nodebug';
        }

        return parent::getCacheDir();
    }
}
