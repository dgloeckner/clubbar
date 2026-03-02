#!/bin/bash

# Development script to reset the Flutter app database
# Removes the local SQLite database to start fresh with mock data seeding

set -e  # Exit on error

# Color output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

echo -e "${YELLOW}🔄 Resetting Club Bar Terminal database...${NC}\n"

# macOS - App container path
MACOS_DB_PATH="$HOME/Library/Containers/com.example.clubbarTerminal/Data/Library/Application Support/com.example.clubbarTerminal/clubbar_terminal.db"

# Linux - typical Flutter app cache location
LINUX_DB_PATH="$HOME/.local/share/clubbar_terminal/clubbar_terminal.db"

# Detect OS and remove database
if [[ "$OSTYPE" == "darwin"* ]]; then
    # macOS
    if [ -f "$MACOS_DB_PATH" ]; then
        rm "$MACOS_DB_PATH"
        echo -e "${GREEN}✅ Deleted macOS database:${NC}"
        echo "   $MACOS_DB_PATH"
    else
        echo -e "${YELLOW}ℹ️  macOS database not found (app may not have run yet)${NC}"
        echo "   Path: $MACOS_DB_PATH"
    fi
elif [[ "$OSTYPE" == "linux-gnu"* ]]; then
    # Linux
    if [ -f "$LINUX_DB_PATH" ]; then
        rm "$LINUX_DB_PATH"
        echo -e "${GREEN}✅ Deleted Linux database:${NC}"
        echo "   $LINUX_DB_PATH"
    else
        echo -e "${YELLOW}ℹ️  Linux database not found (app may not have run yet)${NC}"
        echo "   Path: $LINUX_DB_PATH"
    fi
else
    echo -e "${RED}❌ Unsupported OS: $OSTYPE${NC}"
    exit 1
fi

echo ""
echo -e "${GREEN}✨ Database reset complete!${NC}"
echo ""
echo -e "${YELLOW}Next steps:${NC}"
echo "  1. Run: flutter run"
echo "  2. The app will seed fresh mock data on startup"
echo "  3. RFID card: card-123 (John Doe) or card-456 (Jane Smith)"
echo ""
