<?php

declare(strict_types=1);

namespace App\Presentation\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class InfoController extends AbstractController
{
    #[Route('/about', name: 'info_about', methods: ['GET'])]
    public function about(): Response
    {
        return $this->render('info/about.html.twig');
    }

    #[Route('/sources', name: 'info_sources', methods: ['GET'])]
    public function sources(): Response
    {
        return $this->render('info/sources.html.twig');
    }
}
