<?php

declare(strict_types=1);

namespace App\Platform\Admin\Application\Command;

use App\Platform\User\Domain\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[AsCommand(
    name: 'stayos:superadmin:create',
    description: 'Crée un compte SuperAdmin plateforme (table public.users, ROLE_SUPER_ADMIN).',
)]
final class CreateSuperAdminCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface       $entityManager,
        private readonly UserPasswordHasherInterface  $passwordHasher,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('email', InputArgument::REQUIRED, 'Email du SuperAdmin')
            ->addArgument('name', InputArgument::REQUIRED, 'Nom complet')
            ->addOption(
                'password',
                null,
                InputOption::VALUE_REQUIRED,
                'Mot de passe ; généré automatiquement (16 caractères) si non fourni',
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $email = strtolower(trim((string) $input->getArgument('email')));
        $name  = trim((string) $input->getArgument('name'));

        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $io->error('Email invalide.');
            return Command::FAILURE;
        }

        if ($name === '') {
            $io->error('Le nom est obligatoire.');
            return Command::FAILURE;
        }

        $existing = $this->entityManager->getRepository(User::class)
            ->findOneBy(['email' => $email]);

        if ($existing !== null) {
            $io->error(sprintf('Un compte existe déjà pour %s.', $email));
            return Command::FAILURE;
        }

        $password = (string) ($input->getOption('password') ?? '');
        $generated = false;
        if ($password === '') {
            $password  = $this->generatePassword();
            $generated = true;
        }

        $user = new User();
        $user->setEmail($email);
        $user->setName($name);
        $user->setRoles(['ROLE_SUPER_ADMIN']);
        $user->setActive(true);
        $user->setPassword($this->passwordHasher->hashPassword($user, $password));

        $this->entityManager->persist($user);
        $this->entityManager->flush();

        $io->success('SuperAdmin créé.');
        $io->table(
            ['Champ', 'Valeur'],
            [
                ['Email',    $email],
                ['Nom',      $name],
                ['Password', $password . ($generated ? '  (généré, à stocker maintenant)' : '')],
            ],
        );
        $io->warning('Ce mot de passe ne sera plus affiché. Stockez-le dans un gestionnaire de secrets.');

        return Command::SUCCESS;
    }

    /**
     * Génère un mot de passe de 16 caractères mêlant majuscules,
     * minuscules, chiffres et caractères spéciaux.
     */
    private function generatePassword(): string
    {
        $lower   = 'abcdefghijklmnopqrstuvwxyz';
        $upper   = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $digits  = '0123456789';
        $special = '!@#$%^&*-_=+';

        $chars = $lower . $upper . $digits . $special;

        // Garantir au moins 1 de chaque catégorie
        $pwd = [
            $lower[random_int(0, strlen($lower) - 1)],
            $upper[random_int(0, strlen($upper) - 1)],
            $digits[random_int(0, strlen($digits) - 1)],
            $special[random_int(0, strlen($special) - 1)],
        ];

        for ($i = 4; $i < 16; $i++) {
            $pwd[] = $chars[random_int(0, strlen($chars) - 1)];
        }

        shuffle($pwd);

        return implode('', $pwd);
    }
}
