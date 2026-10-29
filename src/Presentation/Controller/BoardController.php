<?php

declare(strict_types=1);

namespace App\Presentation\Controller;

use App\Application\UseCase\GetDailyFlights;
use App\Domain\Flight\Direction;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\Routing\Attribute\Route;

final class BoardController extends AbstractController
{
    public function __construct(private readonly GetDailyFlights $getDailyFlights)
    {
    }

    #[Route('/', name: 'board_today', methods: ['GET'])]
    public function today(#[MapQueryParameter] Direction $direction = Direction::Departure): Response
    {
        $board = $this->getDailyFlights->today($direction);

        return $this->render('board/today.html.twig', ['board' => $board]);
    }
}
