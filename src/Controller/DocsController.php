<?php

namespace App\Controller;

use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class DocsController extends Container
{

    #[Route('/docs')]
    public function main(): Response
    {


        return new Response();
    }
}
