<?php
declare(strict_types=1);

namespace Survos\BabelBundle\Command;

use Doctrine\Persistence\ManagerRegistry;
use Survos\BabelBundle\Service\StringStorageRouter;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand('babel:populate:missing', 'Create missing Str/StrTranslation entries for code-mode carriers (property-mode no-op).')]
final class PopulateMissingCommand
{
    public function __construct(
        private readonly ManagerRegistry $registry,
        private readonly StringStorageRouter $router,
    ) {}

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('Entity FQCN to scan')] string $class,
        #[Option('em', 'EntityManager name (e.g., default or pixie)')] ?string $em = null,
        #[Option('limit', 'Max carriers to process (0 = all)')] int $limit = 0,
        #[Option('dry-run', 'List what would be populated without writing')] bool $dryRun = false,
    ): int {
        $emObj = $this->registry->getManager($em);
        $repo  = $emObj->getRepository($class);

        // Prefer streaming via query builder if available
        if (method_exists($repo, 'createQueryBuilder')) {
            $iterable = $repo->createQueryBuilder('e')->getQuery()->toIterable();
        } else {
            // ObjectRepository::findAll always exists
            $iterable = $repo->findAll();
        }

        $count = 0;
        foreach ($iterable as $carrier) {
            if ($dryRun) {
                $io->writeln('Would populate: ' . get_debug_type($carrier));
            } else {
                $this->router->for($carrier)->populate($carrier, $em);
            }
            $count++;
            if ($limit && $count >= $limit) break;
        }

        if (!$dryRun) {
            $emObj->flush();
        }

        $io->success(sprintf(
            '%s %d carrier%s.',
            $dryRun ? 'Would populate' : 'Populated',
            $count,
            $count === 1 ? '' : 's'
        ));

        return Command::SUCCESS;
    }
}
