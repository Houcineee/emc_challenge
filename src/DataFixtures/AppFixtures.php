<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\Configuration;
use App\Entity\Coupon;
use App\Entity\Event;
use App\Entity\EventType;
use App\Entity\Seat;
use App\Entity\Ticket;
use App\Entity\User;
use App\Entity\Zone;
use App\Enum\EventStatus;
use App\Enum\TicketStatus;
use App\Enum\UserRole;
use App\Enum\ZoneName;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class AppFixtures extends Fixture
{
    public function __construct(
        private readonly UserPasswordHasherInterface $hasher,
    ) {
    }

    public function load(ObjectManager $manager): void
    {
        $users = $this->loadUsers($manager);
        $eventTypes = $this->loadEventTypes($manager);
        $zones = $this->loadZones($manager);
        $this->loadConfigurations($manager, $zones, $eventTypes);
        $seatsByZone = $this->loadSeats($manager, $zones);
        $events = $this->loadEvents($manager, $eventTypes);
        $coupons = $this->loadCoupons($manager, $events);
        $this->loadTickets($manager, $events, $seatsByZone, $users, $coupons);

        $manager->flush();
    }

    /**
     * @return array<string, User>
     */
    private function loadUsers(ObjectManager $manager): array
    {
        $usersData = [
            ['email' => 'admin@theatre.ma', 'role' => UserRole::ADMIN, 'pass' => 'Admin123!'],
            ['email' => 'guichet@theatre.ma', 'role' => UserRole::CASHIER, 'pass' => 'Guichet123!'],
            ['email' => 'alice@client.ma', 'role' => UserRole::CLIENT, 'pass' => 'Client123!'],
            ['email' => 'bob@client.ma', 'role' => UserRole::CLIENT, 'pass' => 'Client123!'],
            ['email' => 'charlie@client.ma', 'role' => UserRole::CLIENT, 'pass' => 'Client123!'],
            ['email' => 'salma@client.ma', 'role' => UserRole::CLIENT, 'pass' => 'Client123!'],
        ];

        $users = [];
        $now = new \DateTimeImmutable();

        foreach ($usersData as $data) {
            $user = new User();
            $user->setEmail($data['email']);
            $user->setRole($data['role']);
            $user->setPassword($this->hasher->hashPassword($user, $data['pass']));
            $user->setCreatedAt($now);

            $manager->persist($user);
            $users[$data['email']] = $user;
        }

        return $users;
    }

    /**
     * @return array<string, EventType>
     */
    private function loadEventTypes(ObjectManager $manager): array
    {
        $typeNames = ['CONCERT', 'THEATRE', 'CONFERENCE'];
        $eventTypes = [];

        foreach ($typeNames as $name) {
            $eventType = new EventType();
            $eventType->setName($name);
            $manager->persist($eventType);
            $eventTypes[$name] = $eventType;
        }

        return $eventTypes;
    }

    /**
     * @return array<string, Zone>
     */
    private function loadZones(ObjectManager $manager): array
    {
        $zonesData = [
            ['name' => ZoneName::FRONT_STAGE, 'capacity' => 80, 'multiplier' => 20],
            ['name' => ZoneName::ORCHESTRA, 'capacity' => 160, 'multiplier' => 50],
            ['name' => ZoneName::LEFT_BOX, 'capacity' => 30, 'multiplier' => 100],
            ['name' => ZoneName::RIGHT_BOX, 'capacity' => 30, 'multiplier' => 100],
            ['name' => ZoneName::BALCONY, 'capacity' => 100, 'multiplier' => 0],
        ];

        $zones = [];

        foreach ($zonesData as $data) {
            $zone = new Zone();
            $zone->setName($data['name']);
            $zone->setCapacity($data['capacity']);
            $zone->setPriceMultiplierPercent($data['multiplier']);

            $manager->persist($zone);
            $zones[$data['name']->value] = $zone;
        }

        return $zones;
    }

    /**
     * @param array<string, Zone> $zones
     * @param array<string, EventType> $eventTypes
     */
    private function loadConfigurations(ObjectManager $manager, array $zones, array $eventTypes): void
    {
        foreach ($eventTypes as $typeName => $eventType) {
            foreach ($zones as $zoneName => $zone) {
                $config = new Configuration();
                $config->setZone($zone);
                $config->setEventType($eventType);

                // Concert Front Stage is standing (fosse debout); all others are seated
                $isSeated = !($typeName === 'CONCERT' && $zoneName === ZoneName::FRONT_STAGE->value);
                $config->setIsSeated($isSeated);

                $manager->persist($config);
            }
        }
    }

    /**
     * @param array<string, Zone> $zones
     * @return array<string, array<string, array<int, Seat>>>
     */
    private function loadSeats(ObjectManager $manager, array $zones): array
    {
        $seatsByZone = [];

        // 1. FRONT_STAGE: 80 seats, row 'F', numbers 1 to 80
        $frontStage = $zones[ZoneName::FRONT_STAGE->value];
        for ($num = 1; $num <= 80; ++$num) {
            $seat = new Seat();
            $seat->setZone($frontStage);
            $seat->setRowLabel('F');
            $seat->setSeatNumber($num);
            $manager->persist($seat);
            $seatsByZone[ZoneName::FRONT_STAGE->value]['F'][$num] = $seat;
        }

        // 2. ORCHESTRA: 160 seats, 8 rows ('A' to 'H'), 20 seats each (1 to 20)
        $orchestra = $zones[ZoneName::ORCHESTRA->value];
        $orchestraRows = ['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H'];
        foreach ($orchestraRows as $row) {
            for ($num = 1; $num <= 20; ++$num) {
                $seat = new Seat();
                $seat->setZone($orchestra);
                $seat->setRowLabel($row);
                $seat->setSeatNumber($num);
                $manager->persist($seat);
                $seatsByZone[ZoneName::ORCHESTRA->value][$row][$num] = $seat;
            }
        }

        // 3. LEFT_BOX: 30 seats, 3 rows ('L1', 'L2', 'L3'), 10 seats each (1 to 10)
        $leftBox = $zones[ZoneName::LEFT_BOX->value];
        $leftBoxRows = ['L1', 'L2', 'L3'];
        foreach ($leftBoxRows as $row) {
            for ($num = 1; $num <= 10; ++$num) {
                $seat = new Seat();
                $seat->setZone($leftBox);
                $seat->setRowLabel($row);
                $seat->setSeatNumber($num);
                $manager->persist($seat);
                $seatsByZone[ZoneName::LEFT_BOX->value][$row][$num] = $seat;
            }
        }

        // 4. RIGHT_BOX: 30 seats, 3 rows ('R1', 'R2', 'R3'), 10 seats each (1 to 10)
        $rightBox = $zones[ZoneName::RIGHT_BOX->value];
        $rightBoxRows = ['R1', 'R2', 'R3'];
        foreach ($rightBoxRows as $row) {
            for ($num = 1; $num <= 10; ++$num) {
                $seat = new Seat();
                $seat->setZone($rightBox);
                $seat->setRowLabel($row);
                $seat->setSeatNumber($num);
                $manager->persist($seat);
                $seatsByZone[ZoneName::RIGHT_BOX->value][$row][$num] = $seat;
            }
        }

        // 5. BALCONY: 100 seats, 5 rows ('BA', 'BB', 'BC', 'BD', 'BE'), 20 seats each (1 to 20)
        $balcony = $zones[ZoneName::BALCONY->value];
        $balconyRows = ['BA', 'BB', 'BC', 'BD', 'BE'];
        foreach ($balconyRows as $row) {
            for ($num = 1; $num <= 20; ++$num) {
                $seat = new Seat();
                $seat->setZone($balcony);
                $seat->setRowLabel($row);
                $seat->setSeatNumber($num);
                $manager->persist($seat);
                $seatsByZone[ZoneName::BALCONY->value][$row][$num] = $seat;
            }
        }

        return $seatsByZone;
    }

    /**
     * @param array<string, EventType> $eventTypes
     * @return array<string, Event>
     */
    private function loadEvents(ObjectManager $manager, array $eventTypes): array
    {
        $events = [];

        // Event 1: CONCERT
        $event1 = new Event();
        $event1->setEventType($eventTypes['CONCERT']);
        $event1->setTitle('Soirée Gnawa Fusion');
        $event1->setDescription('Concert exceptionnel mêlant transe traditionnelle gnawa et rythmes jazz-funk contemporains avec les grands maâlems de Rabat et Essaouira.');
        $event1->setBasePriceCents(15000);
        $event1->setStartsAt(new \DateTimeImmutable('+14 days 20:30'));
        $event1->setStatus(EventStatus::SCHEDULED);
        $event1->setCreatedAt(new \DateTimeImmutable('-10 days'));
        $manager->persist($event1);
        $events['gnawa'] = $event1;

        // Event 2: THEATRE
        $event2 = new Event();
        $event2->setEventType($eventTypes['THEATRE']);
        $event2->setTitle('Le Malade Imaginaire');
        $event2->setDescription('Comédie-ballet en trois actes de Molière, mise en scène moderne saluée par la critique, interprétée par la troupe nationale.');
        $event2->setBasePriceCents(20000);
        $event2->setStartsAt(new \DateTimeImmutable('+28 days 19:30'));
        $event2->setStatus(EventStatus::SCHEDULED);
        $event2->setCreatedAt(new \DateTimeImmutable('-5 days'));
        $manager->persist($event2);
        $events['theatre'] = $event2;

        // Event 3: CONFERENCE
        $event3 = new Event();
        $event3->setEventType($eventTypes['CONFERENCE']);
        $event3->setTitle('Tech Day : IA & Architectures Distribuées');
        $event3->setDescription('Cycle de conférences d’ingénierie logicielle avancée sur les systèmes distribués haute performance et l’ingénierie d’agents IA.');
        $event3->setBasePriceCents(10000);
        $event3->setStartsAt(new \DateTimeImmutable('+40 days 09:00'));
        $event3->setStatus(EventStatus::SCHEDULED);
        $event3->setCreatedAt(new \DateTimeImmutable('-2 days'));
        $manager->persist($event3);
        $events['tech_day'] = $event3;

        // Event 4: CONCERT (CANCELLED)
        $event4 = new Event();
        $event4->setEventType($eventTypes['CONCERT']);
        $event4->setTitle('Concert Acoustique Andalou (Annulé)');
        $event4->setDescription('Récital de luth et chants arabo-andalous traditionnels. Représentation annulée pour raisons de santé de l’artiste.');
        $event4->setBasePriceCents(18000);
        $event4->setStartsAt(new \DateTimeImmutable('+7 days 20:00'));
        $event4->setStatus(EventStatus::CANCELLED);
        $event4->setCreatedAt(new \DateTimeImmutable('-15 days'));
        $manager->persist($event4);
        $events['andalou_cancelled'] = $event4;

        return $events;
    }

    /**
     * @param array<string, Event> $events
     * @return array<string, Coupon>
     */
    private function loadCoupons(ObjectManager $manager, array $events): array
    {
        $coupons = [];

        // Coupons for Event 1
        $event1Coupons = [
            ['code' => 'ETUDIANT20', 'discount' => 20, 'max' => 50, 'from' => '-10 days', 'until' => '+14 days'],
            ['code' => 'VIP100', 'discount' => 100, 'max' => 10, 'from' => '-10 days', 'until' => '+14 days'],
            ['code' => 'PROMO10', 'discount' => 10, 'max' => 100, 'from' => '-10 days', 'until' => '+14 days'],
        ];

        foreach ($event1Coupons as $c) {
            $coupon = new Coupon();
            $coupon->setEvent($events['gnawa']);
            $coupon->setCode($c['code']);
            $coupon->setDiscountPercent($c['discount']);
            $coupon->setMaxUses($c['max']);
            $coupon->setValidFrom(new \DateTimeImmutable($c['from']));
            $coupon->setValidUntil(new \DateTimeImmutable($c['until']));
            $manager->persist($coupon);
            $coupons[$c['code']] = $coupon;
        }

        // Coupon for Event 3
        $techCoupon = new Coupon();
        $techCoupon->setEvent($events['tech_day']);
        $techCoupon->setCode('STUDENT50');
        $techCoupon->setDiscountPercent(50);
        $techCoupon->setMaxUses(40);
        $techCoupon->setValidFrom(new \DateTimeImmutable('-5 days'));
        $techCoupon->setValidUntil(new \DateTimeImmutable('+40 days'));
        $manager->persist($techCoupon);
        $coupons['STUDENT50'] = $techCoupon;

        return $coupons;
    }

    /**
     * @param array<string, Event> $events
     * @param array<string, array<string, array<int, Seat>>> $seatsByZone
     * @param array<string, User> $users
     * @param array<string, Coupon> $coupons
     */
    private function loadTickets(
        ObjectManager $manager,
        array $events,
        array $seatsByZone,
        array $users,
        array $coupons,
    ): void {
        $event1 = $events['gnawa'];
        $event4 = $events['andalou_cancelled'];
        $paidTime = new \DateTimeImmutable('-2 days');

        // Event 1 Sales (~18 tickets across zones)
        $event1Sales = [
            // Alice (Orchestra + Balcony with ETUDIANT20)
            ['user' => 'alice@client.ma', 'zone' => ZoneName::ORCHESTRA->value, 'row' => 'A', 'num' => 1, 'coupon' => null],
            ['user' => 'alice@client.ma', 'zone' => ZoneName::ORCHESTRA->value, 'row' => 'A', 'num' => 2, 'coupon' => null],
            ['user' => 'alice@client.ma', 'zone' => ZoneName::BALCONY->value, 'row' => 'BA', 'num' => 1, 'coupon' => 'ETUDIANT20'],
            ['user' => 'alice@client.ma', 'zone' => ZoneName::BALCONY->value, 'row' => 'BA', 'num' => 2, 'coupon' => 'ETUDIANT20'],

            // Bob (Front Stage with PROMO10 + Orchestra)
            ['user' => 'bob@client.ma', 'zone' => ZoneName::FRONT_STAGE->value, 'row' => 'F', 'num' => 1, 'coupon' => 'PROMO10'],
            ['user' => 'bob@client.ma', 'zone' => ZoneName::FRONT_STAGE->value, 'row' => 'F', 'num' => 2, 'coupon' => 'PROMO10'],
            ['user' => 'bob@client.ma', 'zone' => ZoneName::FRONT_STAGE->value, 'row' => 'F', 'num' => 3, 'coupon' => null],
            ['user' => 'bob@client.ma', 'zone' => ZoneName::ORCHESTRA->value, 'row' => 'B', 'num' => 5, 'coupon' => null],

            // Charlie (Left Box with VIP100 + Right Box)
            ['user' => 'charlie@client.ma', 'zone' => ZoneName::LEFT_BOX->value, 'row' => 'L1', 'num' => 1, 'coupon' => 'VIP100'],
            ['user' => 'charlie@client.ma', 'zone' => ZoneName::LEFT_BOX->value, 'row' => 'L1', 'num' => 2, 'coupon' => 'VIP100'],
            ['user' => 'charlie@client.ma', 'zone' => ZoneName::RIGHT_BOX->value, 'row' => 'R1', 'num' => 1, 'coupon' => null],

            // Salma (Orchestra with ETUDIANT20 + Balcony)
            ['user' => 'salma@client.ma', 'zone' => ZoneName::ORCHESTRA->value, 'row' => 'C', 'num' => 10, 'coupon' => 'ETUDIANT20'],
            ['user' => 'salma@client.ma', 'zone' => ZoneName::ORCHESTRA->value, 'row' => 'C', 'num' => 11, 'coupon' => 'ETUDIANT20'],
            ['user' => 'salma@client.ma', 'zone' => ZoneName::BALCONY->value, 'row' => 'BB', 'num' => 5, 'coupon' => null],

            // Cashier (Guichet sales)
            ['user' => 'guichet@theatre.ma', 'zone' => ZoneName::FRONT_STAGE->value, 'row' => 'F', 'num' => 10, 'coupon' => null],
            ['user' => 'guichet@theatre.ma', 'zone' => ZoneName::FRONT_STAGE->value, 'row' => 'F', 'num' => 11, 'coupon' => null],
            ['user' => 'guichet@theatre.ma', 'zone' => ZoneName::ORCHESTRA->value, 'row' => 'D', 'num' => 1, 'coupon' => null],
            ['user' => 'guichet@theatre.ma', 'zone' => ZoneName::BALCONY->value, 'row' => 'BC', 'num' => 1, 'coupon' => null],
        ];

        foreach ($event1Sales as $sale) {
            $seat = $seatsByZone[$sale['zone']][$sale['row']][$sale['num']];
            $coupon = $sale['coupon'] !== null ? $coupons[$sale['coupon']] : null;
            $finalPrice = $this->calculatePrice($event1->getBasePriceCents(), $seat->getZone(), $coupon);

            $ticket = new Ticket();
            $ticket->setEvent($event1);
            $ticket->setSeat($seat);
            $ticket->setUser($users[$sale['user']]);
            $ticket->setCoupon($coupon);
            $ticket->setFinalPriceCents($finalPrice);
            $ticket->setStatus(TicketStatus::PAID);
            $ticket->setPaidAt($paidTime);

            $manager->persist($ticket);
        }

        // Event 4 Sales (Cancelled event - 5 REFUNDED tickets to demonstrate Question 5 workflow)
        $event4Sales = [
            ['user' => 'alice@client.ma', 'zone' => ZoneName::ORCHESTRA->value, 'row' => 'A', 'num' => 5, 'paidAt' => '-12 days'],
            ['user' => 'alice@client.ma', 'zone' => ZoneName::ORCHESTRA->value, 'row' => 'A', 'num' => 6, 'paidAt' => '-12 days'],
            ['user' => 'bob@client.ma', 'zone' => ZoneName::BALCONY->value, 'row' => 'BA', 'num' => 10, 'paidAt' => '-10 days'],
            ['user' => 'charlie@client.ma', 'zone' => ZoneName::LEFT_BOX->value, 'row' => 'L2', 'num' => 1, 'paidAt' => '-8 days'],
            ['user' => 'guichet@theatre.ma', 'zone' => ZoneName::FRONT_STAGE->value, 'row' => 'F', 'num' => 20, 'paidAt' => '-7 days'],
        ];

        foreach ($event4Sales as $sale) {
            $seat = $seatsByZone[$sale['zone']][$sale['row']][$sale['num']];
            $finalPrice = $this->calculatePrice($event4->getBasePriceCents(), $seat->getZone(), null);

            $ticket = new Ticket();
            $ticket->setEvent($event4);
            $ticket->setSeat($seat);
            $ticket->setUser($users[$sale['user']]);
            $ticket->setCoupon(null);
            $ticket->setFinalPriceCents($finalPrice);
            $ticket->setStatus(TicketStatus::REFUNDED);
            $ticket->setPaidAt(new \DateTimeImmutable($sale['paidAt']));

            $manager->persist($ticket);
        }
    }

    private function calculatePrice(int $basePriceCents, Zone $zone, ?Coupon $coupon = null): int
    {
        $multiplier = $zone->getPriceMultiplierPercent();
        $zonePrice = (int) round($basePriceCents * (100 + $multiplier) / 100);

        if ($coupon !== null) {
            $zonePrice = (int) round($zonePrice * (100 - $coupon->getDiscountPercent()) / 100);
        }

        return max(0, $zonePrice);
    }
}
