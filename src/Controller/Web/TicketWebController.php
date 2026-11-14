<?php

declare(strict_types=1);

namespace App\Controller\Web;

use App\Entity\Ticket;
use App\Entity\User;
use App\Enum\TicketStatus;
use Dompdf\Dompdf;
use Dompdf\Options;
use Doctrine\ORM\EntityManagerInterface;
use Endroid\QrCode\Color\Color;
use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\QrCode;
use Endroid\QrCode\RoundBlockSizeMode;
use Endroid\QrCode\Writer\SvgWriter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\HeaderUtils;
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

    #[Route('/tickets/{id}/download', name: 'web_ticket_download', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function download(int $id): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return new Response('Authentification requise.', Response::HTTP_UNAUTHORIZED);
        }

        $ticket = $this->em->createQueryBuilder()
            ->select('t', 'e', 's', 'z')
            ->from(Ticket::class, 't')
            ->join('t.event', 'e')
            ->join('t.seat', 's')
            ->join('s.zone', 'z')
            ->where('t.id = :id')
            ->andWhere('t.user = :user')
            ->andWhere('t.status = :status')
            ->setParameter('id', $id)
            ->setParameter('user', $user)
            ->setParameter('status', TicketStatus::PAID)
            ->getQuery()
            ->getOneOrNullResult();

        if (!$ticket instanceof Ticket) {
            throw $this->createNotFoundException('Billet introuvable.');
        }

        $event = $ticket->getEvent();
        $seat = $ticket->getSeat();
        $canonicalCode = sprintf('EMC-%d-%d', $event->getId(), $ticket->getId());
        $qrCode = new QrCode(
            data: $canonicalCode,
            encoding: new Encoding('UTF-8'),
            errorCorrectionLevel: ErrorCorrectionLevel::High,
            size: 220,
            margin: 10,
            roundBlockSizeMode: RoundBlockSizeMode::Margin,
            foregroundColor: new Color(12, 10, 11),
            backgroundColor: new Color(255, 255, 255),
        );
        $qrSvg = (new SvgWriter())->write($qrCode)->getString();
        $qrDataUri = 'data:image/svg+xml;base64,'.base64_encode($qrSvg);

        $options = new Options();
        $options->setDefaultFont('DejaVu Sans');
        $options->setIsRemoteEnabled(false);

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($this->renderView('tickets/download.html.twig', [
            'ticket' => $ticket,
            'event' => $event,
            'seat' => $seat,
            'canonicalCode' => $canonicalCode,
            'qrDataUri' => $qrDataUri,
        ]));
        $dompdf->setPaper('A4');
        $dompdf->render();

        return new Response($dompdf->output(), Response::HTTP_OK, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => HeaderUtils::makeDisposition(
                HeaderUtils::DISPOSITION_ATTACHMENT,
                sprintf('billet-%s.pdf', strtolower($canonicalCode)),
            ),
        ]);
    }
}
