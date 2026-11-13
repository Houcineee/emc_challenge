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
    private \DateTimeImmutable $testStartedAt;

    protected function setUp(): void
    {
        parent::setUp();
        $this->testStartedAt = new \DateTimeImmutable();
    }

    private function loginAs(\Symfony\Bundle\FrameworkBundle\KernelBrowser $client, string $role): void
    {
        $credentials = [
            'ADMIN' => ['email' => 'admin@theatre.ma', 'password' => 'Admin123!'],
            'CASHIER' => ['email' => 'guichet@theatre.ma', 'password' => 'Guichet123!'],
            'CLIENT' => ['email' => 'alice@client.ma', 'password' => 'Client123!'],
        ];

        $this->assertArrayHasKey($role, $credentials);
        $client->request(
            'POST',
            '/api/auth/login',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode($credentials[$role], JSON_THROW_ON_ERROR)
        );
        $this->assertResponseIsSuccessful();
    }

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
            ->setParameter('since', $this->testStartedAt)
            ->execute();
        $em->createQuery('DELETE FROM App\Entity\Coupon c WHERE c.code LIKE :prefix')
            ->setParameter('prefix', 'TESTVIP%')
            ->execute();
        $em->createQuery('DELETE FROM App\Entity\Event e WHERE e.title = :title')
            ->setParameter('title', 'Festival Jazz & Rhythms')
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

    public function testCouponValidationAcceptsLowercaseInput(): void
    {
        $client = static::createClient();
        $event = static::getContainer()
            ->get(EntityManagerInterface::class)
            ->getRepository(Event::class)
            ->findOneBy(['title' => 'Soirée Gnawa Fusion']);

        $this->assertNotNull($event);

        $client->request(
            'POST',
            '/api/coupons/validate',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode([
                'eventId' => $event->getId(),
                'code' => 'etudiant20',
            ], JSON_THROW_ON_ERROR)
        );

        $this->assertResponseIsSuccessful();
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertTrue($data['valid']);
        $this->assertSame('ETUDIANT20', $data['code']);
    }

    public function testQuickLoginAndFullPurchaseJourney(): void
    {
        $client = static::createClient();
        $this->clearRedisHolds();

        // 1. Quick Login as CLIENT
        $this->loginAs($client, 'CLIENT');
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
        $this->loginAs($client, 'ADMIN');

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
        $this->loginAs($client, 'ADMIN');

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
        $this->loginAs($client, 'CLIENT');

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
        $this->loginAs($client, 'CLIENT');

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
        $this->loginAs($client, 'ADMIN');

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
        $this->loginAs($client, 'CLIENT');

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
        $this->loginAs($client, 'CASHIER');

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

    public function testCheckoutWithIdempotencyKeyReturnsSameResultOnDoubleSubmit(): void
    {
        $client = static::createClient();
        $this->clearRedisHolds();

        // 1. Quick Login as CLIENT
        $this->loginAs($client, 'CLIENT');

        // 2. Find available seat on an active event
        $client->request('GET', '/api/events');
        $events = json_decode($client->getResponse()->getContent(), true);
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
        $this->assertNotNull($availableSeat);

        // 3. Hold seat
        $client->request(
            'POST',
            sprintf('/api/events/%d/hold', $targetEventId),
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['seatIds' => [$availableSeat['id']]])
        );
        $this->assertResponseIsSuccessful();

        // 4. First Checkout with Idempotency-Key
        $idempKey = 'idemp-test-' . mt_rand(10000, 99999);
        $client->request(
            'POST',
            '/api/checkout',
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_IDEMPOTENCY_KEY' => $idempKey,
            ],
            json_encode([
                'eventId' => $targetEventId,
                'seatIds' => [$availableSeat['id']],
            ])
        );
        $this->assertResponseStatusCodeSame(201);
        $firstData = json_decode($client->getResponse()->getContent(), true);
        $firstTicketId = $firstData['tickets'][0]['id'];

        // 5. Immediate Second Checkout with exact same Idempotency-Key (simulating double click or network retry)
        $client->request(
            'POST',
            '/api/checkout',
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_IDEMPOTENCY_KEY' => $idempKey,
            ],
            json_encode([
                'eventId' => $targetEventId,
                'seatIds' => [$availableSeat['id']],
            ])
        );
        // Must succeed with 201 Created and return the replay header
        $this->assertResponseStatusCodeSame(201);
        $this->assertTrue($client->getResponse()->headers->has('X-Idempotent-Replay'));
        $secondData = json_decode($client->getResponse()->getContent(), true);
        $secondTicketId = $secondData['tickets'][0]['id'];

        // Must return the exact same ticket without duplicate charge
        $this->assertSame($firstTicketId, $secondTicketId);
    }
}
