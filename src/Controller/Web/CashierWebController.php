<?php

declare(strict_types=1);

namespace App\Controller\Web;

use App\Entity\Event;
use App\Enum\EventStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class CashierWebController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[Route('/cashier', name: 'web_cashier', methods: ['GET'])]
    public function index(): Response
    {
        $isCashierOrAdmin = $this->isGranted('ROLE_CASHIER') || $this->isGranted('ROLE_ADMIN');

        $events = $this->em->getRepository(Event::class)->findBy(
            ['status' => EventStatus::SCHEDULED],
            ['startsAt' => 'ASC']
        );

        return $this->render('cashier/index.html.twig', [
            'isAuthorized' => $isCashierOrAdmin,
            'events' => $events,
        ]);
    }
}
