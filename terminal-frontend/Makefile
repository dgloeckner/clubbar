.PHONY: help reset-db run test test-integration analyze clean

help:
	@echo "Club Bar Terminal - Development Commands"
	@echo ""
	@echo "Usage: make [command]"
	@echo ""
	@echo "Commands:"
	@echo "  reset-db      Reset the local SQLite database (removes all data)"
	@echo "  run           Run the app on a connected device/emulator"
	@echo "  test          Run unit + widget tests (log: test-results/latest.log)"
	@echo "  test-integration  Run integration tests against the real app"
	@echo "  analyze       Run Flutter analysis"
	@echo "  clean         Clean build artifacts"
	@echo "  dev-setup     Install dependencies"
	@echo ""

# Reset database and reseed with mock data
reset-db:
	@bash scripts/reset-db.sh

# Run the app
run:
	@flutter run

# Run all tests. Goes through the runner so every run leaves
# test-results/latest.{log,json} behind and a hang is killed rather than
# waited on — see scripts/run-tests.sh.
test:
	@bash scripts/run-tests.sh

# Run the integration tests (drives the real app; slower than `test`)
test-integration:
	@RUN_TIMEOUT=1200 bash scripts/run-tests.sh integration_test/ --exclude-tags=walkthrough

# Run Flutter analyzer
analyze:
	@flutter analyze

# Clean build artifacts
clean:
	@flutter clean

# Setup development environment
dev-setup:
	@echo "📦 Getting Flutter dependencies..."
	@flutter pub get
	@echo "✅ Setup complete!"

# Run app after resetting DB
reset-and-run: reset-db run

# Run tests and analyzer
test-all: analyze test
