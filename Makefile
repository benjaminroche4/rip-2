hello:
	echo "Hello World"

start:
	symfony server:start --no-tls

clean:
	php bin/console cache:clear

warm:
	php bin/console cache:warmup --env=prod

deploy:
	echo "→ Pull Git (main)" && git pull --ff-only origin main
	echo "→ Install dependencies (prod)" && php composer.phar install --no-dev --optimize-autoloader
	echo "→ Running database migrations" && php bin/console doctrine:migrations:migrate --env=prod --no-interaction
	echo "→ Compile AssetMapper" && php bin/console asset-map:compile --env=prod
	echo "→ Clear cache (prod)" && php bin/console cache:clear --env=prod
	echo "✓ Cache warmup (prod, compiles all Twig templates ahead of first request)" && php bin/console cache:warmup --env=prod
	echo "✓ Déploiement terminé"

version:
	echo "→ Symfony Version"
	php bin/console --version
	echo "→ Symfony CLI Version"
	symfony -v
	echo "→ PHP Version"
	php -v

lint:
	php bin/console lint:twig templates/
	php bin/console lint:yaml config/ --parse-tags
	php bin/console lint:container
	vendor/bin/phpstan analyse --memory-limit=1G
	vendor/bin/php-cs-fixer fix --dry-run --diff

cs-fix:
	vendor/bin/php-cs-fixer fix

test:
	php bin/phpunit

test-prod:
	@echo "→ [1/6] Lint Twig"
	@php bin/console lint:twig templates/
	@echo "→ [2/6] Lint YAML"
	@php bin/console lint:yaml config/ --parse-tags
	@echo "→ [3/6] Lint container (DI types)"
	@php bin/console lint:container
	@echo "→ [4/6] PHPStan (niveau 5, baseline appliqué)"
	@vendor/bin/phpstan analyse --memory-limit=1G
	@echo "→ [5/6] PHP-CS-Fixer (dry-run, formatage Symfony + PHP82)"
	@vendor/bin/php-cs-fixer fix --dry-run --diff
	@echo "→ [6/6] PHPUnit"
	@php bin/phpunit
	@echo "✓ Pre-prod OK : tu peux deployer avec 'make deploy'"

test-db-init:
	echo "→ Create rip_test database (no-op if it already exists)"
	php bin/console doctrine:database:create --env=test --if-not-exists
	echo "→ Apply all migrations to rip_test"
	php bin/console doctrine:migrations:migrate --env=test --no-interaction
	echo "✓ Test DB ready. Run 'make test' to execute the suite."

test-db-reset:
	echo "→ Drop rip_test"
	php bin/console doctrine:database:drop --env=test --force --if-exists
	$(MAKE) test-db-init

tailwind:
	@mkdir -p ~/tmp
	@chmod 700 ~/tmp
	@export TMPDIR=~/tmp && export TEMP=~/tmp && export TMP=~/tmp && \
	php bin/console tailwind:build --minify
	php bin/console asset-map:compile
	php bin/console cache:clear
	php bin/console cache:warmup --env=prod
	echo "→ Tailwind CSS build complete. Do not forget : chmod 711 . (Source)"
