<?php

declare(strict_types=1);

namespace App\Controller\Web;

use App\Entity\Ticket;
use App\Entity\User;
use App\Enum\TicketStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class TicketWebController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[Route('/tickets/my', name: 'web_tickets_my', methods: ['GET'])]
    public function myTickets(): Response
    {
        $user = $this->getUser();

        if (!$user instanceof User) {
            return $this->render('tickets/my.html.twig', [
                'tickets' => [],
                'isAuthenticated' => false,
            ]);
        }

        $tickets = $this->em->createQueryBuilder()
            ->select('t', 'e', 's', 'z', 'c')
            ->from(Ticket::class, 't')
            ->join('t.event', 'e')
            ->join('t.seat', 's')
            ->join('s.zone', 'z')
            ->leftJoin('t.coupon', 'c')
            ->where('t.user = :user')
            ->andWhere('t.status = :status')
            ->setParameter('user', $user)
            ->setParameter('status', TicketStatus::PAID)
            ->orderBy('t.paidAt', 'DESC')
            ->getQuery()
            ->getResult();

        return $this->render('tickets/my.html.twig', [
            'tickets' => $tickets,
            'isAuthenticated' => true,
        ]);
    }
}
