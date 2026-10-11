<?php
declare(strict_types=1);
namespace App;

use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

final class Kernel extends BaseKernel
{
    use MicroKernelTrait;

    public function getCacheDir(): string { return $_SERVER['APP_DATA_DIR'].'/cache/'.$this->environment; }
    public function getLogDir(): string { return $_SERVER['APP_DATA_DIR'].'/logs'; }
}
