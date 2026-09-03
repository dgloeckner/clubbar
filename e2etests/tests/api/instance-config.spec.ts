import { test, expect } from '@playwright/test';

/**
 * Instance config endpoint (ADR-0034).
 *
 * Public, read before any session exists — the login page shows the club's name
 * and the panel needs the club's zone before it can render a single timestamp.
 */
test.describe('Instance Config Endpoint', () => {
  test('GET /api/instance-config names the club and the clock it reads in', async ({ request }) => {
    const response = await request.get('/api/instance-config');

    expect(response.status()).toBe(200);

    const body = await response.json();
    expect(body.instance_name).toBeDefined();
    expect(typeof body.time_zone).toBe('string');
    // Says whether that zone was chosen or fallen back to. The fallback is
    // silent everywhere it is used, so this is the only place a club can find
    // out it never chose one (#365).
    expect(['configured', 'default', 'invalid']).toContain(body.time_zone_source);
  });

  /**
   * The panel converts every instant the API labels "Z" into this zone, so it
   * has to be something `Intl` accepts. A blank or bogus value would throw a
   * RangeError on every timestamp on the screen, which is why the backend falls
   * back to Europe/Berlin rather than passing a typo through (#365).
   */
  test('the reported zone is one Intl can actually format in', async ({ request }) => {
    const body = await request.get('/api/instance-config').then((r) => r.json());

    expect(() =>
      new Intl.DateTimeFormat('de-DE', { timeZone: body.time_zone }).format(new Date())
    ).not.toThrow();
  });
});
