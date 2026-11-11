<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Event;
use App\Entity\Seat;
use App\Entity\Ticket;
use App\Enum\EventStatus;
use App\Enum\TicketStatus;
use Doctrine\ORM\EntityManagerInterface;
use Predis\ClientInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class ApiEndpointsTest extends WebTestCase
{
    private function clearRedisHolds(): void
    {
        $container = static::getContainer();
        $redis = $container->get(ClientInterface::class);
        $keys = (array) $redis->keys('hold:event:*');
        if (!empty($keys)) {
            $redis->del($keys);
        }
    }

    protected function tearDown(): void
    {
        $this->clearRedisHolds();
        $container = static::getContainer();
        $em = $container->get(EntityManagerInterface::class);
        $em->createQuery('DELETE FROM App\Entity\Ticket t WHERE t.paidAt >= :since')
            ->setParameter('since', new \DateTimeImmutable('-5 minutes'))
            ->execute();
        parent::tearDown();
    }

    public function testGetEventsList(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/events');

        $this->assertResponseIsSuccessful();
        $this->assertResponseHeaderSame('content-type', 'application/json');

        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertIsArray($data);
        $this->assertGreaterThanOrEqual(1, count($data));

        $firstEvent = $data[0];
        $this->assertArrayHasKey('id', $firstEvent);
        $this->assertArrayHasKey('title', $firstEvent);
        $this->assertArrayHasKey('basePriceCents', $firstEvent);
        $this->assertArrayHasKey('eventType', $firstEvent);
    }

    public function testGetEventSeatsStructureForThreeJs(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/events');
        $this->assertResponseIsSuccessful();
        $events = json_decode($client->getResponse()->getContent(), true);
        $this->assertNotEmpty($events);
        $firstEventId = $events[0]['id'];

        $client->request('GET', sprintf('/api/events/%d/seats', $firstEventId));

        $this->assertResponseIsSuccessful();
        $data = json_decode($client->getResponse()->getContent(), true);

        $this->assertArrayHasKey('event', $data);
        $this->assertArrayHasKey('seats', $data);

        // Exactly 400 seats
        $this->assertCount(400, $data['seats']);

        $seat = $data['seats'][0];
        $this->assertArrayHasKey('id', $seat);
        $this->assertArrayHasKey('row', $seat);
        $this->assertArrayHasKey('number', $seat);
        $this->assertArrayHasKey('zone', $seat);
        $this->assertArrayHasKey('isSeated', $seat);
        $this->assertArrayHasKey('priceCents', $seat);
        $this->assertArrayHasKey('status', $seat);
    }

    public function testQuickLoginAndFullPurchaseJourney(): void
    {
        $client = static::createClient();
        $this->clearRedisHolds();

        // 1. Quick Login as CLIENT
        $client->request(
            'POST',
            '/api/auth/quick-login',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['role' => 'CLIENT'])
        );
        $this->assertResponseIsSuccessful();
        $loginData = json_decode($client->getResponse()->getContent(), true);
        $this->assertSame('CLIENT', $loginData['user']['role']);

        // 2. Fetch events and find an active scheduled event with available seats
        $client->request('GET', '/api/events');
        $this->assertResponseIsSuccessful();
        $events = json_decode($client->getResponse()->getContent(), true);
        $this->assertNotEmpty($events);

        $targetEventId = null;
        $availableSeat = null;

        foreach ($events as $evt) {
            $client->request('GET', sprintf('/api/events/%d/seats', $evt['id']));
            $seatsData = json_decode($client->getResponse()->getContent(), true);
            foreach ($seatsData['seats'] as $s) {
                if ($s['status'] === 'AVAILABLE') {
                    $targetEventId = (int) $evt['id'];
                    $availableSeat = $s;
                    break 2;
                }
            }
        }

        $this->assertNotNull($targetEventId);
        $this->assertNotNull($availableSeat, 'Must find at least one available seat');

        // 3. Hold the seat
        $client->request(
            'POST',
            sprintf('/api/events/%d/hold', $targetEventId),
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['seatIds' => [$availableSeat['id']]])
        );
        $this->assertResponseIsSuccessful();
        $holdData = json_decode($client->getResponse()->getContent(), true);
        $this->assertTrue($holdData['success']);
        $this->assertNotEmpty($holdData['holds']);

        // 4. Check Cart
        $client->request('GET', '/api/cart');
        $this->assertResponseIsSuccessful();
        $cartData = json_decode($client->getResponse()->getContent(), true);
        $this->assertGreaterThanOrEqual(1, $cartData['totalItems']);

        // 5. Checkout
        $client->request(
            'POST',
            '/api/checkout',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode([
                'eventId' => $targetEventId,
                'seatIds' => [$availableSeat['id']],
            ])
        );
        $this->assertResponseStatusCodeSame(201);
        $checkoutData = json_decode($client->getResponse()->getContent(), true);
        $this->assertTrue($checkoutData['success']);
        $this->assertCount(1, $checkoutData['tickets']);
        $this->assertSame('PAID', $checkoutData['tickets'][0]['status']);
    }

    public function testAdminDashboardAccess(): void
    {
        $client = static::createClient();

        // 1. Quick Login as ADMIN
        $client->request(
            'POST',
            '/api/auth/quick-login',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['role' => 'ADMIN'])
        );
        $this->assertResponseIsSuccessful();

        // 2. Request dashboard stats
        $client->request('GET', '/api/admin/dashboard');
        $this->assertResponseIsSuccessful();
        $data = json_decode($client->getResponse()->getContent(), true);

        $this->assertArrayHasKey('totalRevenueCents', $data);
        $this->assertArrayHasKey('totalTicketsSold', $data);
        $this->assertArrayHasKey('totalSeatsInHall', $data);
        $this->assertArrayHasKey('events', $data);
    }

    public function testAdminCanCreateAndListCoupons(): void
    {
        $client = static::createClient();

        // 1. Quick Login as ADMIN
        $client->request(
            'POST',
            '/api/auth/quick-login',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['role' => 'ADMIN'])
        );
        $this->assertResponseIsSuccessful();

        // Get an active event
        $client->request('GET', '/api/events');
        $events = json_decode($client->getResponse()->getContent(), true);
        $eventId = (int) $events[0]['id'];

        $testCode = 'TESTVIP' . mt_rand(1000, 9999);

        // 2. Create coupon
        $client->request(
            'POST',
            '/api/admin/coupons',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode([
                'eventId' => $eventId,
                'code' => $testCode,
                'discountPercent' => 100,
                'maxUses' => 5,
            ])
        );
        $this->assertResponseStatusCodeSame(201);
        $createdData = json_decode($client->getResponse()->getContent(), true);
        $this->assertTrue($createdData['success']);
        $this->assertSame($testCode, $createdData['coupon']['code']);
        $this->assertSame(100, $createdData['coupon']['discountPercent']);

        // 3. Duplicate creation on same event -> must return 409 Conflict
        $client->request(
            'POST',
            '/api/admin/coupons',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode([
                'eventId' => $eventId,
                'code' => $testCode,
                'discountPercent' => 50,
            ])
        );
        $this->assertResponseStatusCodeSame(409);

        // 4. List coupons
        $client->request('GET', '/api/admin/coupons');
        $this->assertResponseIsSuccessful();
        $couponsList = json_decode($client->getResponse()->getContent(), true);
        $codes = array_column($couponsList, 'code');
        $this->assertContains($testCode, $codes);

        // 5. Client role cannot create coupon -> 403 Forbidden
        $client->request(
            'POST',
            '/api/auth/quick-login',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['role' => 'CLIENT'])
        );
        $this->assertResponseIsSuccessful();

        $client->request(
            'POST',
            '/api/admin/coupons',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode([
                'eventId' => $eventId,
                'code' => 'FORBIDDEN',
                'discountPercent' => 20,
            ])
        );
        $this->assertResponseStatusCodeSame(403);
    }

    public function testClientCanViewTheirTickets(): void
    {
        $client = static::createClient();

        // 1. Quick Login as CLIENT
        $client->request(
            'POST',
            '/api/auth/quick-login',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['role' => 'CLIENT'])
        );
        $this->assertResponseIsSuccessful();

        // 2. Fetch my tickets
        $client->request('GET', '/api/tickets/my');
        $this->assertResponseIsSuccessful();
        $tickets = json_decode($client->getResponse()->getContent(), true);
        $this->assertIsArray($tickets);

        if (!empty($tickets)) {
            $t = $tickets[0];
            $this->assertArrayHasKey('id', $t);
            $this->assertArrayHasKey('status', $t);
            $this->assertArrayHasKey('finalPriceCents', $t);
            $this->assertArrayHasKey('qrPayload', $t);
            $this->assertArrayHasKey('event', $t);
            $this->assertArrayHasKey('seat', $t);
            $this->assertMatchesRegularExpression('/^EMC-\d+-\d+$/', $t['qrPayload']);
        }
    }

    public function testAdminCanCreateScheduledEvent(): void
    {
        $client = static::createClient();

        // 1. Quick Login as ADMIN
        $client->request(
            'POST',
            '/api/auth/quick-login',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['role' => 'ADMIN'])
        );
        $this->assertResponseIsSuccessful();

        // 2. Create Event
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $eventType = $em->getRepository(\App\Entity\EventType::class)->findOneBy([]);
        $this->assertNotNull($eventType);
        $eventTypeId = $eventType->getId();

        $client->request(
            'POST',
            '/api/admin/events',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode([
                'title' => 'Festival Jazz & Rhythms',
                'description' => 'Concert de clôture en plein air.',
                'eventTypeId' => $eventTypeId,
                'basePriceCents' => 25000,
                'startsAt' => (new \DateTimeImmutable('+45 days'))->format(\DateTimeInterface::ATOM),
            ])
        );
        $this->assertResponseStatusCodeSame(201);
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertSame('Festival Jazz & Rhythms', $data['event']['title']);
        $this->assertSame('SCHEDULED', $data['event']['status']);

        // 3. Client cannot create event -> 403 Forbidden
        $client->request(
            'POST',
            '/api/auth/quick-login',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['role' => 'CLIENT'])
        );
        $this->assertResponseIsSuccessful();

        $client->request(
            'POST',
            '/api/admin/events',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode([
                'title' => 'Unauthorized Event',
                'eventTypeId' => $eventTypeId,
                'basePriceCents' => 10000,
                'startsAt' => (new \DateTimeImmutable('+10 days'))->format(\DateTimeInterface::ATOM),
            ])
        );
        $this->assertResponseStatusCodeSame(403);
    }

    public function testCashierCanVerifyTicketQrCode(): void
    {
        $client = static::createClient();

        // 1. Quick Login as CASHIER
        $client->request(
            'POST',
            '/api/auth/quick-login',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['role' => 'CASHIER'])
        );
        $this->assertResponseIsSuccessful();

        // 2. Verify a PAID ticket
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $paidTicket = $em->getRepository(Ticket::class)->findOneBy(['status' => TicketStatus::PAID]);

        if ($paidTicket !== null) {
            $qrPayload = sprintf('EMC-%d-%d', $paidTicket->getEvent()->getId(), $paidTicket->getId());
            $client->request(
                'POST',
                '/api/cashier/verify-ticket',
                [],
                [],
                ['CONTENT_TYPE' => 'application/json'],
                json_encode(['qrPayload' => $qrPayload])
            );
            $this->assertResponseIsSuccessful();
            $data = json_decode($client->getResponse()->getContent(), true);
            $this->assertTrue($data['valid']);
            $this->assertSame('PAID', $data['status']);
        }

        // 3. Verify a REFUNDED ticket
        $refundedTicket = $em->getRepository(Ticket::class)->findOneBy(['status' => TicketStatus::REFUNDED]);
        if ($refundedTicket !== null) {
            $qrPayload = sprintf('EMC-%d-%d', $refundedTicket->getEvent()->getId(), $refundedTicket->getId());
            $client->request(
                'POST',
                '/api/cashier/verify-ticket',
                [],
                [],
                ['CONTENT_TYPE' => 'application/json'],
                json_encode(['qrPayload' => $qrPayload])
            );
            $this->assertResponseIsSuccessful();
            $data = json_decode($client->getResponse()->getContent(), true);
            $this->assertFalse($data['valid']);
            $this->assertSame('REFUNDED', $data['status']);
        }

        // 4. Non-existent ticket -> 404
        $client->request(
            'POST',
            '/api/cashier/verify-ticket',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['qrPayload' => 'EMC-999-999999'])
        );
        $this->assertResponseStatusCodeSame(404);
    }
}
