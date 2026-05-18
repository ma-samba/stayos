<?php

namespace App\DataFixtures;

use App\Hotel\Guest\Domain\Entity\Guest;
use App\Platform\Tenant\Domain\Entity\Tenant;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\DBAL\Connection;
use Doctrine\Persistence\ObjectManager;

class GuestFixtures extends Fixture implements DependentFixtureInterface
{
    public const GUEST_AMADOU_DIALLO = 'guest-amadou-diallo';
    public const GUEST_FATOU_NDIAYE  = 'guest-fatou-ndiaye';
    public const GUEST_PIERRE_MARTIN = 'guest-pierre-martin';

    public function __construct(
        private readonly Connection $connection,
    ) {}

    public function getDependencies(): array
    {
        return [HotelDataFixtures::class];
    }

    public function load(ObjectManager $manager): void
    {
        /** @var Tenant $savana */
        $savana = $this->getReference(TenantFixtures::SAVANA, Tenant::class);

        $schemaName = $savana->getSchemaName();
        $tz = new \DateTimeZone('Africa/Dakar');

        try {
            $this->connection->executeStatement(
                sprintf('SET search_path TO %s, public', $schemaName)
            );

            $guestData = [
                [
                    'ref'           => self::GUEST_AMADOU_DIALLO,
                    'firstName'     => 'Amadou',
                    'lastName'      => 'Diallo',
                    'email'         => 'amadou.diallo@gmail.com',
                    'phone'         => '+221 77 123 45 67',
                    'nationality'   => 'SN',
                    'documentType'  => 'ID_CARD',
                    'documentNumber'=> 'CN1234567',
                    'dateOfBirth'   => '1985-03-15',
                    'city'          => 'Dakar',
                    'country'       => 'SN',
                    'totalStays'    => 4,
                    'preferences'   => ['floor' => 'high', 'smoking' => false],
                ],
                [
                    'ref'           => self::GUEST_FATOU_NDIAYE,
                    'firstName'     => 'Fatou',
                    'lastName'      => 'Ndiaye',
                    'email'         => 'fatou.ndiaye@outlook.com',
                    'phone'         => '+221 78 987 65 43',
                    'nationality'   => 'SN',
                    'documentType'  => 'ID_CARD',
                    'documentNumber'=> 'CN7654321',
                    'dateOfBirth'   => '1990-07-22',
                    'city'          => 'Thiès',
                    'country'       => 'SN',
                    'totalStays'    => 2,
                    'preferences'   => [],
                ],
                [
                    'ref'           => self::GUEST_PIERRE_MARTIN,
                    'firstName'     => 'Pierre',
                    'lastName'      => 'Martin',
                    'email'         => 'pierre.martin@free.fr',
                    'phone'         => '+33 6 12 34 56 78',
                    'nationality'   => 'FR',
                    'documentType'  => 'PASSPORT',
                    'documentNumber'=> 'XY9876543',
                    'dateOfBirth'   => '1978-11-03',
                    'city'          => 'Paris',
                    'country'       => 'FR',
                    'totalStays'    => 2,
                    'preferences'   => ['pillow' => 'firm'],
                ],
                [
                    'ref'           => 'guest-mamadou-sow',
                    'firstName'     => 'Mamadou',
                    'lastName'      => 'Sow',
                    'email'         => null,
                    'phone'         => '+221 76 555 11 22',
                    'nationality'   => 'SN',
                    'documentType'  => 'PASSPORT',
                    'documentNumber'=> 'SN001122',
                    'dateOfBirth'   => '1975-01-10',
                    'city'          => 'Saint-Louis',
                    'country'       => 'SN',
                    'totalStays'    => 1,
                    'preferences'   => [],
                ],
                [
                    'ref'           => 'guest-aissatou-ba',
                    'firstName'     => 'Aissatou',
                    'lastName'      => 'Ba',
                    'email'         => 'aissatou.ba@yahoo.com',
                    'phone'         => '+1 347 555 0199',
                    'nationality'   => 'SN',
                    'documentType'  => 'PASSPORT',
                    'documentNumber'=> 'BA0099887',
                    'dateOfBirth'   => '1988-05-18',
                    'city'          => 'New York',
                    'country'       => 'US',
                    'totalStays'    => 1,
                    'preferences'   => ['floor' => 'high'],
                ],
            ];

            foreach ($guestData as $data) {
                $guest = new Guest();
                $guest->setFirstName($data['firstName']);
                $guest->setLastName($data['lastName']);
                $guest->setEmail($data['email']);
                $guest->setPhone($data['phone']);
                $guest->setNationality($data['nationality']);
                $guest->setDocumentType($data['documentType']);
                $guest->setDocumentNumber($data['documentNumber']);
                $guest->setDateOfBirth(new \DateTimeImmutable($data['dateOfBirth'], $tz));
                $guest->setCity($data['city']);
                $guest->setCountry($data['country']);
                $guest->setTotalStays($data['totalStays']);
                $guest->setPreferences($data['preferences'] ?: null);

                $manager->persist($guest);
                $this->addReference($data['ref'], $guest);
            }

            $manager->flush();

        } finally {
            $this->connection->executeStatement('SET search_path TO public');
        }
    }
}
