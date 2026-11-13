<?php

declare(strict_types=1);

namespace App\Controller\Web;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class CheckoutWebController extends AbstractController
{
    #[Route('/checkout', name: 'web_checkout', methods: ['GET'])]
    public function checkout(): Response
    {
        return $this->render('checkout/index.html.twig', [
            'user' => $this->getUser(),
        ]);
    }
}
