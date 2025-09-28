#!/usr/bin/env bash
# Automated testing script for the advertising campaign infrastructure

DOMAIN="${DOMAIN:-domain.com}"
BASE_URL="http://${DOMAIN}"
COLORS=true

# Color codes for output
if [ "$COLORS" = true ]; then
    RED='\033[0;31m'
    GREEN='\033[0;32m'
    YELLOW='\033[1;33m'
    BLUE='\033[0;34m'
    NC='\033[0m' # No Color
else
    RED=''
    GREEN=''
    YELLOW=''
    BLUE=''
    NC=''
fi

# Helper functions
print_test() {
    echo -e "${BLUE}[TEST]${NC} $1"
}

print_success() {
    echo -e "${GREEN}[PASS]${NC} $1"
}

print_error() {
    echo -e "${RED}[FAIL]${NC} $1"
}

print_warning() {
    echo -e "${YELLOW}[WARN]${NC} $1"
}

# Test counter
TESTS_TOTAL=0
TESTS_PASSED=0
TESTS_FAILED=0

run_test() {
    local test_name="$1"
    local expected_code="$2"
    local url="$3"
    local user_agent="$4"
    local additional_args="$5"
    
    TESTS_TOTAL=$((TESTS_TOTAL + 1))
    print_test "$test_name"
    
    local response
    if [ -n "$user_agent" ]; then
        response=$(curl -s -i -A "$user_agent" $additional_args "$url" 2>/dev/null)
    else
        response=$(curl -s -i $additional_args "$url" 2>/dev/null)
    fi
    
    local http_code=$(echo "$response" | head -n 1 | grep -o '[0-9]\{3\}')
    
    if [ "$http_code" = "$expected_code" ]; then
        print_success "HTTP $http_code (expected $expected_code)"
        TESTS_PASSED=$((TESTS_PASSED + 1))
        return 0
    else
        print_error "HTTP $http_code (expected $expected_code)"
        TESTS_FAILED=$((TESTS_FAILED + 1))
        return 1
    fi
}

check_redirect() {
    local test_name="$1"
    local url="$2"
    local user_agent="$3"
    local expected_location="$4"
    
    TESTS_TOTAL=$((TESTS_TOTAL + 1))
    print_test "$test_name"
    
    local response
    if [ -n "$user_agent" ]; then
        response=$(curl -s -i -A "$user_agent" "$url" 2>/dev/null)
    else
        response=$(curl -s -i "$url" 2>/dev/null)
    fi
    
    local location=$(echo "$response" | grep -i "location:" | cut -d' ' -f2- | tr -d '\r\n')
    
    if [[ "$location" == *"$expected_location"* ]]; then
        print_success "Redirects to $location"
        TESTS_PASSED=$((TESTS_PASSED + 1))
        return 0
    else
        print_error "Expected redirect to contain '$expected_location', got '$location'"
        TESTS_FAILED=$((TESTS_FAILED + 1))
        return 1
    fi
}

echo "======================================"
echo "  Ad Campaign Infrastructure Tests"
echo "======================================"
echo "Testing domain: $DOMAIN"
echo "Base URL: $BASE_URL"
echo ""

# Test 1: White page accessibility
run_test "White page loads correctly" "200" "$BASE_URL/"

# Test 2: Bot User-Agent should redirect to white page
check_redirect "Bot UA redirects to white page" "$BASE_URL/route" "curl/7.68.0" "/white.html"

# Test 3: Another bot UA test
check_redirect "Python bot redirects to white page" "$BASE_URL/route" "python-requests/2.28.1" "/white.html"

# Test 4: Human User-Agent should redirect to challenge
check_redirect "Human UA redirects to challenge" "$BASE_URL/route" "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36" "/js_challenge.html"

# Test 5: Mobile User-Agent test
check_redirect "Mobile UA redirects to challenge" "$BASE_URL/route" "Mozilla/5.0 (iPhone; CPU iPhone OS 15_0 like Mac OS X) AppleWebKit/605.1.15" "/js_challenge.html"

# Test 6: Route endpoint with parameters
check_redirect "Route with UTM parameters" "$BASE_URL/route?utm_source=test&utm_campaign=demo" "Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36" "/js_challenge.html"

# Test 7: Verify endpoint should require POST
run_test "Verify endpoint rejects GET" "405" "$BASE_URL/verify"

# Test 8: Verify endpoint with POST but no data
run_test "Verify endpoint with empty POST" "400" "$BASE_URL/verify" "" "-X POST"

# Test 9: Rate limiting test (multiple requests)
echo ""
print_test "Rate limiting test (sending 10 requests rapidly)"
rate_limit_failed=false
for i in {1..10}; do
    response=$(curl -s -i -A "curl/7.68.0" "$BASE_URL/route" 2>/dev/null)
    http_code=$(echo "$response" | head -n 1 | grep -o '[0-9]\{3\}')
    if [ "$http_code" != "302" ]; then
        rate_limit_failed=true
        break
    fi
    sleep 0.1
done

TESTS_TOTAL=$((TESTS_TOTAL + 1))
if [ "$rate_limit_failed" = false ]; then
    print_success "Rate limiting working (all requests handled)"
    TESTS_PASSED=$((TESTS_PASSED + 1))
else
    print_warning "Rate limiting may be active or server error occurred"
    TESTS_FAILED=$((TESTS_FAILED + 1))
fi

# Test 10: Check if logs are being written
echo ""
print_test "Checking if decision logs are being written"
TESTS_TOTAL=$((TESTS_TOTAL + 1))

# Make a request first
curl -s -A "test-agent" "$BASE_URL/route" > /dev/null 2>&1
sleep 1

# Check if log file exists and has recent entries
if [ -f "/var/www/$DOMAIN/logs/decisions.log" ]; then
    recent_logs=$(find "/var/www/$DOMAIN/logs/decisions.log" -mmin -1 2>/dev/null)
    if [ -n "$recent_logs" ]; then
        print_success "Decision logs are being written"
        TESTS_PASSED=$((TESTS_PASSED + 1))
    else
        print_error "Decision log file exists but no recent entries"
        TESTS_FAILED=$((TESTS_FAILED + 1))
    fi
else
    print_error "Decision log file not found"
    TESTS_FAILED=$((TESTS_FAILED + 1))
fi

# Test 11: Database connectivity test
echo ""
print_test "Testing database connectivity"
TESTS_TOTAL=$((TESTS_TOTAL + 1))

# Try to connect to PostgreSQL and check if clicks table exists
if command -v psql >/dev/null 2>&1; then
    if PGPASSWORD="$DB_PASSWORD" psql -h "$DB_HOST" -U "$DB_USER" -d "$DB_NAME" -c "\dt clicks" >/dev/null 2>&1; then
        print_success "Database connection and clicks table verified"
        TESTS_PASSED=$((TESTS_PASSED + 1))
    else
        print_error "Cannot connect to database or clicks table missing"
        TESTS_FAILED=$((TESTS_FAILED + 1))
    fi
else
    print_warning "psql not available, skipping database test"
    TESTS_FAILED=$((TESTS_FAILED + 1))
fi

# Test 12: Redis connectivity test
echo ""
print_test "Testing Redis connectivity"
TESTS_TOTAL=$((TESTS_TOTAL + 1))

if command -v redis-cli >/dev/null 2>&1; then
    if redis-cli ping >/dev/null 2>&1; then
        print_success "Redis connection verified"
        TESTS_PASSED=$((TESTS_PASSED + 1))
    else
        print_error "Cannot connect to Redis"
        TESTS_FAILED=$((TESTS_FAILED + 1))
    fi
else
    print_warning "redis-cli not available, skipping Redis test"
    TESTS_FAILED=$((TESTS_FAILED + 1))
fi

# Summary
echo ""
echo "======================================"
echo "           TEST SUMMARY"
echo "======================================"
echo "Total tests: $TESTS_TOTAL"
echo -e "Passed: ${GREEN}$TESTS_PASSED${NC}"
echo -e "Failed: ${RED}$TESTS_FAILED${NC}"

if [ $TESTS_FAILED -eq 0 ]; then
    echo -e "${GREEN}All tests passed!${NC}"
    exit 0
else
    echo -e "${RED}Some tests failed. Please check the configuration.${NC}"
    exit 1
fi
