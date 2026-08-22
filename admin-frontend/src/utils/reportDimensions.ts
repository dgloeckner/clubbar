/**
 * What to call a report row whose group has no name.
 *
 * `GET /admin/reports/{type}` answers each row with a `dimension` and a
 * `dimension_id`, and `dimension` is **null** when the group cannot be named.
 * That happens for one reason, and it is a designed-for one: ADR-0033 §1 lets a
 * terminal upload a sale whose `product_id` resolves to no product — the drink
 * was poured against a cached catalogue, and refusing the row would lose the
 * record of a sale that happened. The sale still belongs in the revenue it
 * produced, so it still gets a row; the row just has nothing to be called.
 *
 * The backend used to answer the literal string `Unknown` for those rows, which
 * a German admin read untranslated between "Helles" and "Wasser (1l)". Naming
 * the row is this side's business — the API is language-agnostic and returns
 * facts, not labels — so the translation happens here.
 *
 * `dimension_id` is what keeps two nameless rows apart. Two sales naming two
 * different missing products are two rows, and without the id in the label they
 * would read as one repeated. Only the product grouping can produce a nameless
 * row that still carries an id: a category is reached *through* a product, so
 * when the product is missing the category id is missing with it, and a
 * member's row is enforced by a foreign key that cannot dangle.
 */

/** The row fields this module reads — a structural subset of `ReportDataItem`. */
export interface ReportDimensionSource {
  dimension?: string | null
  dimension_id?: string | null
}

/**
 * Every locale key this module can ask for. Exported so the unit suite can
 * assert each one exists in every locale file — a missing key renders as the
 * key itself, which is exactly the class of bug this module was written to end.
 */
export const UNNAMED_DIMENSION_KEYS = [
  'reports.unnamedProduct',
  'reports.unnamedProductWithId',
  'reports.unnamedCategory',
  'reports.unnamedDimension',
] as const

/** How much of an id is enough to tell two dead products apart on screen. */
const SHORT_ID_LENGTH = 8

type Translate = (key: string, params?: Record<string, string>) => string

/**
 * The label for one report row, in the reader's language.
 *
 * @param groupBy The grouping the row belongs to, so a nameless product does
 *        not read the same as a nameless category.
 */
export function reportDimensionLabel(
  row: ReportDimensionSource,
  groupBy: string,
  translate: Translate,
): string {
  const named = row.dimension
  if (named != null && named !== '') {
    return named
  }

  const shortId = row.dimension_id?.slice(0, SHORT_ID_LENGTH)

  if (groupBy === 'product') {
    return shortId
      ? translate('reports.unnamedProductWithId', { id: shortId })
      : translate('reports.unnamedProduct')
  }
  if (groupBy === 'category') {
    return translate('reports.unnamedCategory')
  }

  return translate('reports.unnamedDimension')
}
