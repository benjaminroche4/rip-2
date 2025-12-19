hello:
	echo "Hello World"

start:
	symfony server:start --no-tls

clean:
	php bin/console cache:clear

deploy:
	set -e
	echo "→ Pull Git (main)"
	git pull --ff-only origin main
	echo "→ Compile AssetMapper"
	php bin/console asset-map:compile --env=prod
	echo "→ Clear cache (prod)"
	php bin/console cache:clear --env=prod
	echo "✓ Déploiement terminé"

version:
	echo "→ Symfony Version"
	php bin/console --version
	echo "→ Symfony CLI Version"
	symfony -v
	echo "→ PHP Version"
	php -v
