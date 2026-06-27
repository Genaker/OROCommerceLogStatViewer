<?php

declare(strict_types=1);

namespace Genaker\Bundle\ComiVoyager;

use Symfony\Component\HttpKernel\Bundle\Bundle;

class GenakerComiVoyagerBundle extends Bundle
{
    public function getPath(): string
    {
        return __DIR__;
    }
}
