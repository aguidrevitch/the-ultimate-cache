## The Ultimate Cache

### Install packages

```
ID=$(docker build . -q) && docker run --rm -it -v "$PWD:/var/www/html/" $ID composer install
```

### Run tests

```
ID=$(docker build . -q) && docker run --rm -it -v "$PWD:/var/www/html/" $ID vendor/bin/phpunit
```
