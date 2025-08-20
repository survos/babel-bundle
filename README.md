# Survos BabelBundle

A tiny translation base with:

- Mapped-superclass entities: **StrBase**, **StrTranslationBase**
- `#[Translatable]` attribute
- Doctrine subscriber to populate translated text on `postLoad`
- No doctrine-extensions dependency

## How to use

1. **Install** (monorepo): add bundle to `config/bundles.php`:

```php
return [
    // ...
    Survos\BabelBundle\SurvosBabelBundle::class => ['all' => true],
];
```

```bash
# Create concrete translation entities & repositories in your app
set -euo pipefail

mkdir -p src/Entity src/Repository

# Str entity (extends BabelBundle mapped superclass)
cat > src/Entity/Str.php <<'PHP'
<?php
declare(strict_types=1);

namespace App\Entity;

use App\Repository\StrRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: StrRepository::class)]
#[ORM\Table(name: 'str')]
class Str extends \Survos\BabelBundle\Entity\Base\StrBase
{
}
PHP

# Str repository
cat > src/Repository/StrRepository.php <<'PHP'
<?php
declare(strict_types=1);

namespace App\Repository;

use App\Entity\Str;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

final class StrRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Str::class);
    }
}
PHP

# StrTranslation entity (extends BabelBundle mapped superclass)
cat > src/Entity/StrTranslation.php <<'PHP'
<?php
declare(strict_types=1);

namespace App\Entity;

use App\Repository\StrTranslationRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: StrTranslationRepository::class)]
#[ORM\Table(name: 'str_translation')]
class StrTranslation extends \Survos\BabelBundle\Entity\Base\StrTranslationBase
{
}
PHP

# StrTranslation repository
cat > src/Repository/StrTranslationRepository.php <<'PHP'
<?php
declare(strict_types=1);

namespace App\Repository;

use App\Entity\StrTranslation;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

final class StrTranslationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, StrTranslation::class);
    }
}
PHP
```

```bash
# Rebuild autoload + create tables
composer dump-autoload -o
bin/console make:migration
bin/console doctrine:migrations:migrate -n
echo "✅ App\\Entity\\Str + StrTranslation created; migrations executed."

```

New way:

$tr->status = 'untranslated';

This should be a constant or an ENUM.  We're going to need them later when we batch translate.

Regarding #5, the ability to get the translation from the entity without injecting a service, can't this also be made into an optional trait?  I don't want to copy that into every entity.

Can you give me a bash script that starts with symfony new --webapp and ends with bin/console batch:browse Post en

Something like

symfony new --web-app babel-test
echo "DATABASE_URL=(a sqlite database in var/data.db)" > .env.local
composer require survos/babel-bundle:dev-main
composer require survos/code-bundle --dev
bin/console make:entity Post -n
cat > src/Entity/Post.php (a trivial post class with a title and body, both translated with the attrbitue
bin/console code:trans:trait
bin/console d:sc:update --force
mkdir src/Command
cat > src/Command/LoadPosts.php (a trivial command to load some English-language posts)
cat > config/packages/translation.yaml

framework:
default_locale: en
enabled_locales: ['en', 'de', 'es']
translator:
default_path: '%kernel.project_dir%/translations'
fallbacks:
- en

bin/console batch:browse Post

This is the workflow I'd like to work toward.  We will add a script that translates the untranslated strings later.


