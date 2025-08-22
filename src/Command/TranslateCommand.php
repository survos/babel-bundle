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

#[AsCommand('babel:translate', 'Translate missing texts for a target locale (works for code-mode or property-mode carriers).')]
final class TranslateCommand
{
    public function __construct(
        private readonly ManagerRegistry $registry,
        private readonly StringStorageRouter $router,
    ) {}

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('locale', 'Target locale (e.g., es, fr, de)')] string $locale,
        #[Option('class', 'Entity FQCN to scan')] string $class,
        #[Option('em', 'EntityManager name (e.g., default or pixie)')] ?string $em = null,
        #[Option('only-missing', 'Only translate fields where target locale is empty')] bool $onlyMissing = true,
        #[Option('limit', 'Max carriers to process (0 = all)')] int $limit = 0,
    ): int {
        $emObj = $this->registry->getManager($em);
        $repo  = $emObj->getRepository($class);

        if (method_exists($repo, 'createQueryBuilder')) {
            $iterable = $repo->createQueryBuilder('e')->getQuery()->toIterable();
        } else {
            $iterable = $repo->findAll();
        }

        $done = 0;
        foreach ($iterable as $carrier) {
            $done += $this->router->for($carrier)->translate($carrier, $locale, $onlyMissing, $em);
            if ($limit && $done >= $limit) break;
        }

        $emObj->flush();

        $io->success(sprintf(
            'Translated %d field%s to %s in %s.',
            $done,
            $done === 1 ? '' : 's',
            $locale,
            $class
        ));

        return Command::SUCCESS;
    }
}
