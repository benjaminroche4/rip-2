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

.PHONY: tailwind

tailwind:
	@mkdir -p ~/tmp
	@chmod 700 ~/tmp
	@export TMPDIR=~/tmp && export TEMP=~/tmp && export TMP=~/tmp && \
	php bin/console tailwind:build --minify
	php bin/console asset-map:compile
	php bin/console cache:clear
	echo "→ Tailwind CSS build complete. Do not forget : chmod 711 . (Source)"
