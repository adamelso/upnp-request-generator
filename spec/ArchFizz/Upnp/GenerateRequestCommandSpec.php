<?php

namespace spec\ArchFizz\Upnp;

use PhpSpec\ObjectBehavior;
use Prophecy\Argument;

class GenerateRequestCommandSpec extends ObjectBehavior
{
    function it_is_initializable()
    {
        $this->shouldHaveType('ArchFizz\Upnp\GenerateRequestCommand');
    }

    function it_is_a_Symfony_comamnd()
    {
        $this->shouldHaveType('Symfony\Component\Console\Command\Command');
    }
}


