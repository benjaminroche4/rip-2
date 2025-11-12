hello:
	echo "Hello World"

start:
	symfony server:start --no-tls

clean:
	php bin/console cache:clear

.ONESHELL:
SHELL := /bin/bash

deploy:
	set -e
	echo "→ Pull Git (main)"
	git pull --ff-only origin main
	echo "→ Compile AssetMapper"
	php bin/console asset-map:compile --env=prod
	echo "→ Clear cache (prod)"
	php bin/console cache:clear --env=prod
	echo "✓ Déploiement terminé"
