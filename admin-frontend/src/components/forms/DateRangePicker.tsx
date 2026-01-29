/**
 * DateRangePicker Component
 * Reusable date range input component with two date fields (from/to)
 *
 * Props:
 * - dateFrom: Start date (ISO format YYYY-MM-DD)
 * - dateTo: End date (ISO format YYYY-MM-DD)
 * - onDateFromChange: Callback when start date changes
 * - onDateToChange: Callback when end date changes
 * - testId: Base test ID for this component
 */

interface DateRangePickerProps {
  dateFrom: string
  dateTo: string
  onDateFromChange: (date: string) => void
  onDateToChange: (date: string) => void
  testId?: string
}

export function DateRangePicker({
  dateFrom,
  dateTo,
  onDateFromChange,
  onDateToChange,
  testId = 'date-range-picker',
}: DateRangePickerProps) {
  return (
    <div className="flex gap-2 items-center">
      {/* From date */}
      <div className="flex flex-col">
        <label htmlFor={`${testId}-from`} className="text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
          From
        </label>
        <input
          id={`${testId}-from`}
          type="date"
          value={dateFrom}
          onChange={(e) => onDateFromChange(e.target.value)}
          data-testid={`${testId}-from`}
          className="px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500 bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100"
        />
      </div>

      {/* To date */}
      <div className="flex flex-col">
        <label htmlFor={`${testId}-to`} className="text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
          To
        </label>
        <input
          id={`${testId}-to`}
          type="date"
          value={dateTo}
          onChange={(e) => onDateToChange(e.target.value)}
          data-testid={`${testId}-to`}
          className="px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500 bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100"
        />
      </div>
    </div>
  )
}
