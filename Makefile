
RM = rm -rfd
CHMOD = chmod
MKDIR = mkdir -p
VENDOR = vendor
COMPOSER = ./composer.phar
COMPOSER_OPTIONS ?= --no-interaction


.PHONY: all
all: install

.PHONY: install
install: $(VENDOR)

.PHONY: update
update: $(COMPOSER)
	$(COMPOSER) selfupdate
	$(COMPOSER) update

.PHONY: clean
clean:
	$(RM) composer.lock $(COMPOSER) $(VENDOR)

$(VENDOR): $(COMPOSER)
	$(COMPOSER) install $(COMPOSER_OPTIONS)

$(COMPOSER):
	curl -sS https://getcomposer.org/installer | php
	$(CHMOD) u=rwx,go=rx $(COMPOSER)
