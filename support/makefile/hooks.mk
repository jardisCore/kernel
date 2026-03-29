<---hooks-------->: ## -----------------------------------------------------------------------
install-hooks: ## Install git hooks (pre-commit + pre-push)
	@echo '#!/bin/bash' > .git/hooks/pre-commit
	@echo 'bash ./support/pre-commit-hook.sh' >> .git/hooks/pre-commit
	@chmod +x .git/hooks/pre-commit
	@echo '#!/bin/bash' > .git/hooks/pre-push
	@echo '# Jardis Pre-Push Hook — Quality Gate' >> .git/hooks/pre-push
	@echo 'set -e' >> .git/hooks/pre-push
	@echo 'echo "=== Jardis Pre-Push Quality Gate ==="' >> .git/hooks/pre-push
	@echo 'echo ">>> make phpcs"' >> .git/hooks/pre-push
	@echo 'make phpcs || { echo "PHPCS fehlgeschlagen — Push abgebrochen"; exit 1; }' >> .git/hooks/pre-push
	@echo 'echo ">>> make phpstan"' >> .git/hooks/pre-push
	@echo 'make phpstan || { echo "PHPStan fehlgeschlagen — Push abgebrochen"; exit 1; }' >> .git/hooks/pre-push
	@echo 'echo ">>> make phpunit"' >> .git/hooks/pre-push
	@echo 'make phpunit || { echo "PHPUnit fehlgeschlagen — Push abgebrochen"; exit 1; }' >> .git/hooks/pre-push
	@echo 'echo "=== Quality Gate bestanden ==="' >> .git/hooks/pre-push
	@chmod +x .git/hooks/pre-push
	@echo "Hooks installed."
.PHONY: install-hooks
