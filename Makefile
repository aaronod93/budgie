# Lil' Budgie dev shortcuts. Windows users without make: use .\dev.ps1 instead.

API_URL ?= http://localhost:8000

.PHONY: help docker api reverb worker scheduler web tunnel phone test

help: ## List available targets
	@grep -E '^[a-z-]+:.*##' $(MAKEFILE_LIST) | awk -F ':.*## ' '{printf "  make %-11s %s\n", $$1, $$2}'

docker: ## Start MariaDB, Redis, Mailpit containers
	docker compose up -d

api: ## Run the Laravel API on 0.0.0.0:8000
	cd api && php artisan serve --host=0.0.0.0 --port=8000

reverb: ## Run the Reverb websocket server on :8080 (live updates)
	cd api && php artisan reverb:start --host=0.0.0.0 --port=8080

worker: ## Run the queue worker (emails, invitations)
	cd api && php artisan queue:work --tries=3

scheduler: ## Run the scheduler (posts due scheduled transactions)
	cd api && php artisan schedule:work

web: ## Run the Nuxt web app on :3000
	cd web && npm run dev

tunnel: ## Bridge phone's localhost:8000 to this machine over USB
	adb reverse tcp:8000 tcp:8000

phone: tunnel ## Build + run the app on the connected Android device
	cd mobile && flutter run

test: ## Run API + mobile test suites
	cd api && php artisan test
	cd mobile && flutter analyze && flutter test
