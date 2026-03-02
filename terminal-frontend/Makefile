.PHONY: help reset-db run test analyze clean

help:
	@echo "Club Bar Terminal - Development Commands"
	@echo ""
	@echo "Usage: make [command]"
	@echo ""
	@echo "Commands:"
	@echo "  reset-db      Reset the local SQLite database (removes all data)"
	@echo "  run           Run the app on a connected device/emulator"
	@echo "  test          Run all 204 unit tests"
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

# Run all tests
test:
	@flutter test

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
