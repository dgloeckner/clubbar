import { test, expect } from '@playwright/test';
import { execSync } from 'child_process';
import path from 'path';

const API_BASE = 'http://localhost:8080/api';

// Project root relative to this test file (e2etests/tests/api/ → project root)
const PROJECT_ROOT = path.resolve(__dirname, '../../..');

function dbExec(sql: string) {
  execSync(
    `docker compose exec -T database mysql -uroot -proot clubbar -e "${sql}"`,
    { cwd: PROJECT_ROOT, stdio: 'pipe' }
  );
}

function clearLoginAttempts() {
  dbExec('DELETE FROM login_attempts;');
}

/**
 * Seed exactly N failed login attempts for the Docker gateway IP.
 * We detect the IP by making a real request and reading the recorded attempt,
 * but since that's fragile across environments, we seed for ALL common IPs
 * (172.x gateway + 127.0.0.1 fallback) and also detect the actual IP.
 */
function seedFailedAttempts(count: number, ip: string) {
  // Insert N failed attempts for the given IP, backdated within the 15-minute window
  const values = Array.from({ length: count }, (_, i) =>
    `('${ip}', 'ratelimit-seed-${i}@test.com', NOW() - INTERVAL ${i} SECOND)`
  ).join(', ');
  dbExec(`INSERT INTO login_attempts (ip_address, email, attempted_at) VALUES ${values};`);
}

function getRecordedIp(): string {
  const result = execSync(
    `docker compose exec -T database mysql -uroot -proot clubbar -N -e "SELECT ip_address FROM login_attempts ORDER BY id DESC LIMIT 1;"`,
    { cwd: PROJECT_ROOT, stdio: 'pipe' }
  );
  return result.toString().trim();
}

test.describe('Login Rate Limiting', () => {
  test.describe.configure({ mode: 'serial' });

  test.afterAll(async () => {
    clearLoginAttempts();
  });

  test('blocks login after 5 failed attempts and returns correct 429 format', async ({ request }) => {
    // Step 1: Clear all attempts to start fresh
    clearLoginAttempts();

    // Step 2: Make one failed attempt to discover the actual REMOTE_ADDR
    // that the backend sees for requests from this test runner
    const probe = await request.post(`${API_BASE}/auth/login`, {
      data: { email: 'probe@ratelimit-test.com', password: 'wrong' },
    });
    expect(probe.status()).toBe(401);

    // Read the IP that was recorded
    const detectedIp = getRecordedIp();
    expect(detectedIp).toBeTruthy();

    // Step 3: Clear again, then seed exactly 5 failed attempts for detected IP
    // Using direct DB inserts avoids race conditions with parallel tests
    // that might clear the table via successful logins
    clearLoginAttempts();
    seedFailedAttempts(5, detectedIp);

    // Step 4: Next request should be rate-limited (429) - even with correct credentials
    const blocked = await request.post(`${API_BASE}/auth/login`, {
      data: { email: 'admin@example.com', password: 'password123' },
    });
    expect(blocked.status()).toBe(429);
    const body = await blocked.json();
    expect(body.error).toBe('too_many_attempts');
    expect(body.message).toBe('Too many login attempts. Please try again later.');
    expect(body.retry_after_seconds).toBe(900); // 15 minutes = 900 seconds

    // Verify Retry-After header is present
    const retryAfter = blocked.headers()['retry-after'];
    expect(retryAfter).toBe('900');

    // Verify subsequent attempts are also blocked with same format
    const blocked2 = await request.post(`${API_BASE}/auth/login`, {
      data: { email: 'another@test.com', password: 'wrong' },
    });
    expect(blocked2.status()).toBe(429);
    const body2 = await blocked2.json();
    expect(body2.error).toBe('too_many_attempts');
    expect(body2).toHaveProperty('retry_after_seconds');
  });
});
