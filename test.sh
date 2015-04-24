#!/bin/sh
set -e
set -o xtrace # Echo out the command before running it
find . -name "*.php" -not -path "./vendor/*"| xargs -n 1 php -l
#PHP_CodeSniffer, to verbose and naming seems to conflict with woocommerce, but worth running sometimes
#find . -name "*.php" -not -path "./vendor/*"| xargs -n 1 phpcs

set +e
vendor/phpmd/phpmd/src/bin/phpmd classes text cleancode,unusedcode,naming
vendor/phpmd/phpmd/src/bin/phpmd mcash-woocommerce-gateway.php text cleancode,unusedcode,naming
set -e

php --version

for f in `find . -name "*test.php" -not -path "./vendor/*"`
do
    phpunit --verbose $f
done
