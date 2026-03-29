<?php

namespace ArnaudMoncondhuy\SynapseMcp;

use Symfony\Component\HttpKernel\Bundle\Bundle;

class SynapseMcpBundle extends Bundle
{
    public function getPath(): string
    {
        return \dirname(__DIR__);
    }
}
