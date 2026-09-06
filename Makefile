hello:
	echo "Hello World"

# TLS local (CA Symfony installée) : indispensable depuis que le HSTS prod
# peut être mémorisé par le navigateur pour localhost/127.0.0.1.
start:
	symfony server:start

clean:
	php bin/console cache:clear

# Secours prod (o2switch) : l'app se croit en dev (erreur DebugBundle) parce
# que l'env résolu est faux. Re-fige l'env prod puis reconstruit le cache en
# forçant APP_ENV pour que la console tourne même avec un env cassé.
# Prérequis : le .env.local prod est en place à la racine du projet.
fix-env:
	@test -f .env.local || (echo "✗ .env.local manquant : copie d'abord ton env prod à la racine" && exit 1)
	echo "→ Purge du dump d'env périmé" && rm -f .env.local.php
	echo "→ Re-dump de l'env (prod)" && php composer.phar dump-env prod
	echo "→ Purge complète du cache (dev + prod)" && rm -rf var/cache/*
	echo "→ Rebuild du cache en prod" && APP_ENV=prod APP_DEBUG=0 php bin/console cache:clear
	echo "→ Warmup prod" && APP_ENV=prod APP_DEBUG=0 php bin/console cache:warmup
	echo "✓ Env prod re-figé (.env.local.php), cache reconstruit"

warm:
	php bin/console cache:warmup --env=prod

