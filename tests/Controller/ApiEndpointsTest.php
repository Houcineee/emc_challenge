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
}
