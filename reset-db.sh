#!/bin/bash

# Development script to reset the backend database with test data
# Runs the reset_test_data.sql script against the MariaDB container

set -e  # Exit on error

# Color output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

echo -e "${YELLOW}🔄 Resetting Ruderbar backend database...${NC}\n"

# Check if Docker containers are running
if ! docker compose ps | grep -q "database"; then
    echo -e "${RED}❌ Error: Database container is not running${NC}"
    echo "   Run: docker compose up -d"
    exit 1
fi

# Run the reset script
echo "   Clearing existing data..."
echo "   Loading test data..."
docker compose exec -T database mysql -u root -proot ruderbar < backend/db/reset_test_data.sql

echo ""
echo -e "${GREEN}✨ Database reset complete!${NC}"
echo ""
echo -e "${YELLOW}Test data loaded:${NC}"
echo "  • 2 categories (Getränke, Sauna)"
echo "  • 12 products with icons"
echo "  • 8 members with valid SEPA data"
echo "  • 10 sample transactions"
echo ""
echo -e "${YELLOW}Note:${NC} Admin users and terminals were preserved"
echo ""
