<?php

declare(strict_types=1);

namespace App\Presentation\Controller;

use App\Application\UseCase\GetDailyFlights;
use App\Domain\Flight\Direction;
use App\Domain\Flight\ServiceDate;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;

final class BoardController extends AbstractController
{
    public function __construct(private readonly GetDailyFlights $getDailyFlights)
    {
    }

    #[Route('/', name: 'board_today', methods: ['GET'])]
    public function today(
        #[MapQueryParameter(validationFailedStatusCode: Response::HTTP_BAD_REQUEST)] Direction $direction = Direction::Departure,
    ): Response {
        $board = $this->getDailyFlights->today($direction);

        return $this->render('board/today.html.twig', ['board' => $board]);
    }

    /**
     * Without a date, today (JST). A malformed date or direction is a bad
     * request rather than another day's board.
     */
    #[Route('/flights', name: 'board_flights', methods: ['GET'])]
    public function flights(
        #[MapQueryParameter(validationFailedStatusCode: Response::HTTP_BAD_REQUEST)] ?string $date = null,
        #[MapQueryParameter(validationFailedStatusCode: Response::HTTP_BAD_REQUEST)] Direction $direction = Direction::Departure,
    ): Response {
        if (null === $date) {
            $board = $this->getDailyFlights->today($direction);
        } else {
            try {
                $serviceDate = ServiceDate::fromString($date);
            } catch (\InvalidArgumentException $e) {
                throw new BadRequestHttpException('Invalid date.', $e);
            }
            $board = $this->getDailyFlights->execute($serviceDate, $direction);
        }

        return $this->render('board/flights.html.twig', ['board' => $board]);
    }
}
