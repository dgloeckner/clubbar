#!/bin/bash

# Development script to reset the backend database with test data
# Runs the reset_test_data.sql script against the MariaDB container

set -e  # Exit on error

# Run from the repo root regardless of where this script was invoked from
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$(cd "$SCRIPT_DIR/.." && pwd)"

# Color output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

echo -e "${YELLOW}🔄 Resetting Club Bar backend database...${NC}\n"

# Check if Docker containers are running
if ! docker compose ps | grep -q "database"; then
    echo -e "${RED}❌ Error: Database container is not running${NC}"
    echo "   Run: docker compose up -d"
    exit 1
fi

# Run the reset script
echo "   Clearing existing data..."
echo "   Loading test data..."
docker compose exec -T database mysql -u root -proot clubbar < backend/db/reset_test_data.sql

echo ""
echo -e "${GREEN}✨ Database reset complete!${NC}"
echo ""
echo -e "${YELLOW}Test data loaded:${NC}"
echo "  • 2 categories (Getränke, Sauna)"
echo "  • 13 products with icons (8 beverages, 5 sauna)"
echo "  • 8 members with valid SEPA data"
echo "  • 3 terminals (Bar, Sauna active; Terrace inactive)"
echo ""
echo -e "${YELLOW}Test terminal tokens:${NC}"
echo "  • Bar Terminal:     test-token-bar-terminal-0001"
echo "  • Sauna Terminal:   test-token-sauna-terminal-002"
echo "  • Terrace Terminal: test-token-terrace-term-0003"
echo ""
echo -e "${YELLOW}Note:${NC} Admin users were preserved"
echo ""
