# Latest version of ubuntu
FROM ubuntu:latest

# Default git repository
ENV GIT_REPOSITORY https://github.com/TheFox/flickr-cli.git

# Innstall git, php + other tools, the the app and remove the unneded apps
RUN apt-get update \
    && apt-get install --no-install-recommends -y php-bcmath php-curl php-simplexml composer git unzip \
    && git clone $GIT_REPOSITORY \
    && cd flickr-cli \
    && composer install --no-dev \
    && apt-get -y purge composer git unzip \
    && apt-get clean

VOLUME /mnt

WORKDIR /mnt

ENTRYPOINT ["php", "/flickr-cli/application.php"]
