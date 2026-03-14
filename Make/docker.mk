# =============================================================================
# TARGETS
# =============================================================================

#### Docker

.PHONY: build bash

build: .logo ## Builds the Docker image.
	$(COMPOSE_BIN) build

bash: .logo ## Opens a bash within the buildbox container.
	${COMPOSE_BUILD} bash


#### Tools

.PHONY: format

format: .logo ## Runs the format script (usage: make format FILE=<path>).
	${COMPOSE_BUILD} php scripts/imagemeta-format.php $(FILE)
