hello:
	echo "Hello World"

start:
	symfony server:start --no-tls

clean:
	php bin/console cache:clear
