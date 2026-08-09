import { test, expect } from '../../fixtures/pageObjects'

/**
 * Products list refresh — a re-fetch must not unmount the toolbar (#137)
 *
 * The page used to swap itself for a loading message whenever it was loading
 * with an empty list. Those two conditions look like "first load", and are
 * also exactly what a search that matched nothing produces: the next debounced
 * request tore out the toolbar — with the focused search box inside it — and
 * the rest of the word went nowhere.
 *
 * Both tests type *past* an empty result, which is the only way to reach the
 * broken state. The second one holds the request open with a route delay so the
 * refresh state can be asserted rather than raced.
 *
 * No test data is created and nothing shared is mutated: both tests search for
 * a term unique to the run, so they see an empty list whatever else exists
 * (Patterns 001, 004).
 *
 * Implements E2E Testing Patterns 001, 004, 005, 006, 008.
 */

test.describe('Products list refresh keeps the toolbar mounted (#137)', () => {

  test('typing on past an empty result keeps focus and every keystroke', async ({
    authenticatedProductsPage,
  }) => {
    const term = `Zzz${Date.now()}`

    // A search nothing matches — the list is now empty and settled.
    await authenticatedProductsPage.search(term)
    await authenticatedProductsPage.expectEmptyStateVisible()

    // Keep typing, slower than the 500 ms debounce, so a request is in flight
    // over the empty list while the box has focus.
    const rest = '9876'
    await authenticatedProductsPage.typeIntoSearch(rest, 700)

    // The box was never remounted, so it still has focus and the whole word.
    await authenticatedProductsPage.expectSearchFocused()
    expect(await authenticatedProductsPage.getSearchValue()).toBe(`${term}${rest}`)
    await authenticatedProductsPage.expectToolbarVisible()
  })

  test('a refresh over an empty list dims the results, not the page', async ({
    authenticatedProductsPage,
    page,
  }) => {
    const term = `Zzz${Date.now()}`

    await authenticatedProductsPage.search(term)
    await authenticatedProductsPage.expectEmptyStateVisible()

    // Hold the next list request open long enough to assert on the refresh
    // state. Installed only now, so it cannot slow the setup above.
    const listRequest = (url: URL) => url.pathname.endsWith('/api/admin/products')
    const holdOpen = async (route: import('@playwright/test').Route) => {
      await new Promise((resolve) => setTimeout(resolve, 3000))
      await route.continue()
    }
    await page.route(listRequest, holdOpen)

    await authenticatedProductsPage.typeIntoSearch('5', 0)

    // The spinner belongs to the results region; the toolbar is still there.
    await authenticatedProductsPage.expectListLoadingOverlayOverResultsOnly()
    await authenticatedProductsPage.expectSearchFocused()

    await page.unroute(listRequest, holdOpen)
  })
})
