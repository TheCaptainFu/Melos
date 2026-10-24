<?php

namespace Drupal\gg_members;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;

/**
 * Numbers behind the statistics page.
 */
class MemberStats {

  /**
   * Short Greek month names, keyed 1-12.
   */
  /**
   * Greek month names in the genitive ("Έσοδα Σεπτεμβρίου"), keyed 1-12.
   */
  public const MONTHS_GENITIVE = [1 => 'Ιανουαρίου', 'Φεβρουαρίου', 'Μαρτίου', 'Απριλίου', 'Μαΐου', 'Ιουνίου', 'Ιουλίου', 'Αυγούστου', 'Σεπτεμβρίου', 'Οκτωβρίου', 'Νοεμβρίου', 'Δεκεμβρίου'];

  public const SHORT_MONTHS = [1 => 'Ιαν', 'Φεβ', 'Μάρ', 'Απρ', 'Μάι', 'Ιούν', 'Ιούλ', 'Αύγ', 'Σεπ', 'Οκτ', 'Νοέ', 'Δεκ'];

  /**
   * One colour per class, in class order — its card's strip, percentage
   * and bar.
   */
  public const CLASS_COLORS = ['#2563eb', '#16a34a', '#f59e0b', '#7c3aed', '#e11d48', '#0d9488', '#64748b', '#db2777'];

  public function __construct(
    protected Connection $database,
    protected ConfigFactoryInterface $configFactory,
    protected MemberManager $memberManager,
  ) {}

  /**
   * Where the numbers start: the last 12 months, or less if the module has
   * only been in use for a shorter time (its first recorded payment) —
   * months before that hold no data, not zero income.
   *
   * @return array
   *   'months': the first day of each month shown, oldest first, this month
   *   included; 'partial': TRUE if that's less than a full year of history;
   *   'empty': TRUE if there are no payments at all; 'from' and 'to': the
   *   first and last month, accusative ("Σεπτέμβριο 2026").
   */
  public function history(bool $all = FALSE): array {
    return $this->buildHistory($this->database->query('SELECT MIN(payment_date) FROM {gg_members_payment}')->fetchField() ?: NULL, $all);
  }

  /**
   * Same as history(), for the expenses page: from the first expense.
   */
  public function expenseHistory(bool $all = FALSE): array {
    return $this->buildHistory($this->database->query('SELECT MIN(expense_date) FROM {gg_members_expense}')->fetchField() ?: NULL, $all);
  }

  /**
   * Same as history(), for the profit page: from the first payment or
   * expense, whichever came first.
   */
  public function profitHistory(bool $all = FALSE): array {
    $dates = array_filter([
      $this->database->query('SELECT MIN(payment_date) FROM {gg_members_payment}')->fetchField(),
      $this->database->query('SELECT MIN(expense_date) FROM {gg_members_expense}')->fetchField(),
    ]);
    return $this->buildHistory($dates ? min($dates) : NULL, $all);
  }

  /**
   * A one-month period, for when a single month is picked from the period
   * list: the same shape as history(), with 'single' set and the month's
   * name in the genitive ('label': "Σεπτεμβρίου 2026") for headings.
   *
   * @param string $month
   *   'YYYY-MM'.
   */
  public function monthHistory(string $month): array {
    $start = new \DateTimeImmutable($month . '-01');
    return [
      'months' => [$start],
      'partial' => FALSE,
      'empty' => FALSE,
      'single' => TRUE,
      'label' => self::MONTHS_GENITIVE[(int) $start->format('n')] . ' ' . $start->format('Y'),
      'from' => $this->monthAccusative($start),
      'to' => $this->monthAccusative($start),
    ];
  }

  /**
   * 'YYYY-MM-DD' of the first day after a period.
   */
  protected function periodEnd(array $months): string {
    return end($months)->modify('+1 month')->format('Y-m-d');
  }

  /**
   * The months shown, from the last 12 or the first data, whichever is
   * later (see history()).
   *
   * @param string|null $firstDate
   *   'YYYY-MM-DD' of the first record, or NULL if there are none.
   * @param bool $all
   *   Every month since the first record instead (for the columns, which
   *   scroll back through the whole history).
   */
  protected function buildHistory(?string $firstDate, bool $all = FALSE): array {
    $end = new \DateTimeImmutable('first day of this month');
    $yearStart = $end->modify('-11 months');
    $dataStart = $firstDate ? (new \DateTimeImmutable($firstDate))->modify('first day of this month') : $end;
    $start = $all ? min($dataStart, $end) : max($yearStart, $dataStart);

    $months = [];
    for ($cursor = $start; $cursor <= $end; $cursor = $cursor->modify('+1 month')) {
      $months[] = $cursor;
    }

    return [
      'months' => $months,
      'partial' => $start > $yearStart,
      'empty' => !$firstDate,
      'from' => $this->monthAccusative($start),
      'to' => $this->monthAccusative($end),
    ];
  }

  /**
   * The headline numbers: money actually received over the period (by
   * payment date, this month included), and the average per month the
   * academy was open — over complete months only, since this one is still
   * filling up.
   */
  public function basics(array $history): array {
    $months = $history['months'];

    $total = (float) $this->database->query(
      'SELECT COALESCE(SUM(amount), 0) FROM {gg_members_payment} WHERE payment_date >= :start AND payment_date < :end',
      [':start' => $months[0]->format('Y-m-d'), ':end' => $this->periodEnd($months)]
    )->fetchField();
    $counts = $this->database->query(
      'SELECT COUNT(*) AS payments, COUNT(DISTINCT member_id) AS members FROM {gg_members_payment} WHERE payment_date >= :start AND payment_date < :end',
      [':start' => $months[0]->format('Y-m-d'), ':end' => $this->periodEnd($months)]
    )->fetchAssoc();

    // Months the academy is closed (summer) bring nothing in by design, so
    // they'd only drag the average down; it's per month of operation.
    $freeMonths = array_map('intval', $this->configFactory->get('gg_members.settings')->get('free_months') ?? []);
    [$completeTotal, $completeMonths] = $this->completeMonths($months, $freeMonths);

    return [
      'total' => $total,
      'count' => (int) $counts['payments'],
      'members' => (int) $counts['members'],
      'average' => $completeMonths ? $completeTotal / $completeMonths : $total,
      'average_months' => $completeMonths,
      'closed_months' => count($months) - $this->countOpenMonths($months, $freeMonths),
    ];
  }

  /**
   * Income from the complete months of a period that weren't closed months
   * (this month, still filling up, left out), and how many such months.
   *
   * @return array
   *   [total, number of months].
   */
  protected function completeMonths(array $months, array $freeMonths, ?int $classTid = NULL): array {
    $complete = array_filter(array_slice($months, 0, -1), fn(\DateTimeImmutable $month) => !in_array((int) $month->format('n'), $freeMonths, TRUE));
    if (!$complete) {
      return [0.0, 0];
    }
    $query = $this->database->select('gg_members_payment', 'p');
    $query->addExpression('COALESCE(SUM(p.amount), 0)', 'total');
    $query->condition('p.payment_date', $months[0]->format('Y-m-d'), '>=');
    $query->condition('p.payment_date', end($months)->format('Y-m-d'), '<');
    if ($classTid) {
      $query->join('gg_members_member', 'm', 'm.id = p.member_id');
      $query->condition('m.class_tid', $classTid);
    }
    return [(float) $query->execute()->fetchField(), count($complete)];
  }

  /**
   * The same income month by month (by payment date), for the monthly
   * columns — only the months there's data for.
   *
   * @return array
   *   'months': one entry per month, oldest first — label, year, amount,
   *   percent (of the best month, for the column height), closed (a month
   *   the academy doesn't charge, with nothing in), current (this month, so
   *   still filling up), best (the highest complete month); 'best_amount'.
   */
  public function monthly(array $history): array {
    $freeMonths = array_map('intval', $this->configFactory->get('gg_members.settings')->get('free_months') ?? []);
    return $this->monthlyRows($history['months'], $this->incomeByMonth($history['months']), $freeMonths);
  }

  /**
   * The monthly columns for the expenses page. Closed months aren't marked:
   * the rent is still paid in the summer.
   */
  public function expenseMonthly(array $history): array {
    return $this->monthlyRows($history['months'], $this->expensesByMonth($history['months']));
  }

  /**
   * The monthly columns for the profit page: income minus expenses, a loss
   * as a negative amount.
   */
  public function profitMonthly(array $history): array {
    $income = $this->incomeByMonth($history['months']);
    $expenses = $this->expensesByMonth($history['months']);
    $profit = [];
    foreach (array_keys($income + $expenses) as $month) {
      $profit[$month] = ($income[$month] ?? 0) - ($expenses[$month] ?? 0);
    }
    return $this->monthlyRows($history['months'], $profit);
  }

  /**
   * Money received per month (by payment date): 'YYYY-MM' => amount.
   */
  protected function incomeByMonth(array $months): array {
    return array_map('floatval', $this->database->query(
      'SELECT SUBSTRING(payment_date, 1, 7), SUM(amount) FROM {gg_members_payment} WHERE payment_date >= :start AND payment_date < :end GROUP BY SUBSTRING(payment_date, 1, 7)',
      [':start' => $months[0]->format('Y-m-d'), ':end' => $this->periodEnd($months)]
    )->fetchAllKeyed());
  }

  /**
   * Expenses per month: 'YYYY-MM' => amount.
   */
  protected function expensesByMonth(array $months): array {
    return array_map('floatval', $this->database->query(
      'SELECT SUBSTRING(expense_date, 1, 7), SUM(amount) FROM {gg_members_expense} WHERE expense_date >= :start AND expense_date < :end GROUP BY SUBSTRING(expense_date, 1, 7)',
      [':start' => $months[0]->format('Y-m-d'), ':end' => $this->periodEnd($months)]
    )->fetchAllKeyed());
  }

  /**
   * One column per month, for any of the three pages.
   *
   * @param \DateTimeImmutable[] $months
   * @param array $amounts
   *   'YYYY-MM' => amount (negative for a loss on the profit page).
   * @param int[] $freeMonths
   *   Calendar months to mark "closed" when nothing came in.
   *
   * @return array
   *   'months': one entry per month, oldest first — label, name, year,
   *   amount, percent (of the largest month, for the column height),
   *   negative, closed, current (this month, still filling up), best (the
   *   highest complete month); 'best_amount'.
   */
  protected function monthlyRows(array $months, array $amounts, array $freeMonths = []): array {
    $currentMonth = end($months)->format('Y-m');
    $inRange = array_intersect_key($amounts, array_flip(array_map(fn($month) => $month->format('Y-m'), $months)));

    // The best month is the best *complete* one: this month is still
    // filling up, so it only counts if there's nothing else to compare.
    $complete = array_diff_key($inRange, [$currentMonth => TRUE]);
    $best = $complete ? max($complete) : (float) ($inRange[$currentMonth] ?? 0);
    $largest = $inRange ? max(array_map('abs', $inRange)) : 0.0;

    $rows = [];
    foreach ($months as $month) {
      $key = $month->format('Y-m');
      $amount = (float) ($inRange[$key] ?? 0);
      $rows[] = [
        'key' => $key,
        'label' => self::SHORT_MONTHS[(int) $month->format('n')],
        'name' => MemberManager::formatMonth($key),
        'year' => $month->format('Y'),
        'amount' => $amount,
        'percent' => $largest > 0 ? min(100, round(abs($amount) / $largest * 100, 1)) : 0,
        'negative' => $amount < 0,
        'closed' => $amount == 0 && in_array((int) $month->format('n'), $freeMonths, TRUE),
        'current' => $key === $currentMonth,
        'best' => $best > 0 && $amount == $best && ($key !== $currentMonth || !$complete),
      ];
    }

    return ['months' => $rows, 'best_amount' => $best];
  }

  /**
   * The headline numbers for the expenses page: everything spent in the
   * period, and the average per complete month (all of them — costs like
   * rent don't stop when the academy closes).
   */
  public function expenseBasics(array $history): array {
    $byMonth = $this->expensesByMonth($history['months']);
    $complete = array_map(fn($month) => $month->format('Y-m'), array_slice($history['months'], 0, -1));
    $completeTotal = array_sum(array_intersect_key($byMonth, array_flip($complete)));

    return [
      'total' => array_sum($byMonth),
      'count' => (int) $this->database->query(
        'SELECT COUNT(*) FROM {gg_members_expense} WHERE expense_date >= :start AND expense_date < :end',
        [':start' => $history['months'][0]->format('Y-m-d'), ':end' => $this->periodEnd($history['months'])]
      )->fetchField(),
      'average' => $complete ? $completeTotal / count($complete) : array_sum($byMonth),
      'average_months' => count($complete),
    ];
  }

  /**
   * The headline numbers for the profit page: income, expenses, what's
   * left, and the average profit per complete month.
   */
  public function profitBasics(array $history): array {
    $income = $this->incomeByMonth($history['months']);
    $expenses = $this->expensesByMonth($history['months']);
    $complete = array_flip(array_map(fn($month) => $month->format('Y-m'), array_slice($history['months'], 0, -1)));
    $completeProfit = array_sum(array_intersect_key($income, $complete)) - array_sum(array_intersect_key($expenses, $complete));

    $totalIncome = array_sum($income);
    $totalExpenses = array_sum($expenses);
    return [
      'income' => $totalIncome,
      'expenses' => $totalExpenses,
      'profit' => $totalIncome - $totalExpenses,
      'average' => $complete ? $completeProfit / count($complete) : $totalIncome - $totalExpenses,
      'average_months' => count($complete),
      // Of every 100€ that comes in, how much goes on expenses.
      'expense_share' => $totalIncome > 0 ? (int) round(min($totalExpenses, $totalIncome) / $totalIncome * 100) : NULL,
    ];
  }

  /**
   * The period's expenses per category, biggest first: name, color,
   * amount, percent (of all expenses, adding up to 100), count, average
   * (per complete month).
   */
  public function expensesByCategory(array $history): array {
    $months = $history['months'];
    $rows = $this->database->query(
      'SELECT category, SUM(amount) AS total, COUNT(*) AS entries FROM {gg_members_expense} WHERE expense_date >= :start AND expense_date < :end GROUP BY category ORDER BY total DESC',
      [':start' => $months[0]->format('Y-m-d'), ':end' => $this->periodEnd($months)]
    )->fetchAllAssoc('category');
    $complete = max(1, count($months) - 1);
    $completeTotals = $this->database->query(
      'SELECT category, SUM(amount) FROM {gg_members_expense} WHERE expense_date >= :start AND expense_date < :end GROUP BY category',
      [':start' => $months[0]->format('Y-m-d'), ':end' => end($months)->format('Y-m-d')]
    )->fetchAllKeyed();

    $percents = $this->percentages(array_map(fn($row) => (float) $row->total, $rows));
    $categories = [];
    foreach ($rows as $key => $row) {
      [$label, $color] = ExpenseManager::CATEGORIES[$key] ?? ExpenseManager::CATEGORIES['other'];
      $categories[] = [
        'name' => $label,
        'color' => $color,
        'amount' => (float) $row->total,
        'percent' => $percents[$key],
        'count' => (int) $row->entries,
        'average' => count($months) > 1 ? (float) ($completeTotals[$key] ?? 0) / $complete : (float) $row->total,
      ];
    }
    return $categories;
  }

  /**
   * The same income split by class: each class's share of the money (by
   * the paying member's class) and of the active members.
   *
   * @return array
   *   'total' and 'members' (whole academy), and 'classes': one entry per
   *   class in class order — name, color, revenue, revenue_percent,
   *   members, members_percent, average (per complete month the class was
   *   open).
   */
  public function byClass(array $history): array {
    $months = $history['months'];

    $revenue = $this->database->query(
      'SELECT m.class_tid, SUM(p.amount) FROM {gg_members_payment} p INNER JOIN {gg_members_member} m ON m.id = p.member_id WHERE p.payment_date >= :start AND p.payment_date < :end GROUP BY m.class_tid',
      [':start' => $months[0]->format('Y-m-d'), ':end' => $this->periodEnd($months)]
    )->fetchAllKeyed();
    $payments = $this->database->query(
      'SELECT m.class_tid, COUNT(*) FROM {gg_members_payment} p INNER JOIN {gg_members_member} m ON m.id = p.member_id WHERE p.payment_date >= :start AND p.payment_date < :end GROUP BY m.class_tid',
      [':start' => $months[0]->format('Y-m-d'), ':end' => $this->periodEnd($months)]
    )->fetchAllKeyed();
    $members = $this->database->query(
      'SELECT class_tid, COUNT(*) FROM {gg_members_member} WHERE status = 1 GROUP BY class_tid'
    )->fetchAllKeyed();

    $total = (float) array_sum($revenue);
    $totalMembers = (int) array_sum($members);

    $terms = $this->memberManager->getClasses();
    $classRevenue = [];
    $classMembers = [];
    foreach ($terms as $tid => $term) {
      $classRevenue[$tid] = (float) ($revenue[$tid] ?? 0);
      $classMembers[$tid] = (int) ($members[$tid] ?? 0);
    }
    $revenuePercents = $this->percentages($classRevenue);
    $memberPercents = $this->percentages($classMembers);

    $classes = [];
    $index = 0;
    foreach ($terms as $tid => $term) {
      // Per complete month this class was open (classes can have their own
      // closed months, see the settings page).
      [$completeTotal, $completeMonths] = $this->completeMonths($months, $this->memberManager->getFreeMonths((int) $tid), (int) $tid);
      $classes[] = [
        'name' => $term->label(),
        'color' => self::CLASS_COLORS[$index++ % count(self::CLASS_COLORS)],
        'revenue' => $classRevenue[$tid],
        'revenue_percent' => $revenuePercents[$tid],
        'members' => $classMembers[$tid],
        'members_percent' => $memberPercents[$tid],
        'average' => $completeMonths ? $completeTotal / $completeMonths : $classRevenue[$tid],
        'payments' => (int) ($payments[$tid] ?? 0),
      ];
    }

    return [
      'total' => $total,
      'members' => $totalMembers,
      'classes' => $classes,
    ];
  }

  /**
   * Membership over the period: who's active now, who joined, who left and
   * how long people stay — for the whole academy and per class.
   *
   * With less than a year of history, everyone was entered when the module
   * was first used, so: joining counts only after that first month, and the
   * year-ago count and the average stay (both meaningless then) are NULL.
   * Members without an enrollment date never count as joined.
   *
   * @return array
   *   Whole-academy 'active', 'active_year_ago', 'joined', 'joined_from'
   *   (accusative month joins are counted from), 'left', 'tenure' (average
   *   months a member stays: enrollment until leaving, or until now), and
   *   'classes': one entry per class in class order (same colours as
   *   byClass()) — name, color, active, active_percent, joined, left, age
   *   (average, active members), tenure.
   */
  public function members(array $history): array {
    $months = $history['months'];
    $start = $months[0]->format('Y-m');
    $joinedFrom = $history['partial'] && count($months) > 1 ? $months[1] : $months[0];
    $currentMonth = end($months)->format('Y-m');
    $yearAgo = $months[0]->modify('-1 month')->format('Y-m');
    $today = new \DateTimeImmutable('today');

    $totals = ['active' => 0, 'active_year_ago' => 0, 'joined' => 0, 'left' => 0, 'tenure' => []];
    $perClass = [];
    $rows = $this->database->query('SELECT class_tid, status, enrollment_date, left_date, birth_date, created, changed FROM {gg_members_member}');
    foreach ($rows as $member) {
      $tid = (int) $member->class_tid;
      $perClass[$tid] ??= ['active' => 0, 'joined' => 0, 'left' => 0, 'ages' => [], 'tenure' => []];
      $isActive = (bool) (int) $member->status;
      // Same fallbacks as the billing logic: creation date for a missing
      // enrollment date, last edit for a missing leaving date.
      $joinMonth = substr($member->enrollment_date ?: date('Y-m-d', (int) $member->created), 0, 7);
      $leaveMonth = $isActive ? NULL : substr($member->left_date ?: date('Y-m-d', (int) $member->changed), 0, 7);

      if ($isActive) {
        $totals['active']++;
        $perClass[$tid]['active']++;
        $birth = $member->birth_date ? \DateTimeImmutable::createFromFormat('Y-m-d', $member->birth_date) : FALSE;
        if ($birth) {
          $perClass[$tid]['ages'][] = $birth->diff($today)->y;
        }
      }
      if ($joinMonth <= $yearAgo && ($isActive || $leaveMonth >= $yearAgo)) {
        $totals['active_year_ago']++;
      }
      // Only members with a recorded enrollment date can be said to have
      // joined in the period; without one, the fallback (when the record
      // was created) is just when they were typed in.
      if ($member->enrollment_date && $joinMonth >= $joinedFrom->format('Y-m') && $joinMonth <= $currentMonth && !($history['partial'] && count($months) === 1)) {
        $totals['joined']++;
        $perClass[$tid]['joined']++;
      }
      if ($leaveMonth && $leaveMonth >= $start && $leaveMonth <= $currentMonth) {
        $totals['left']++;
        $perClass[$tid]['left']++;
      }
      $tenure = $this->monthsBetween($joinMonth, $leaveMonth ?? $currentMonth) + 1;
      $totals['tenure'][] = $tenure;
      $perClass[$tid]['tenure'][] = $tenure;
    }

    $average = fn(array $values) => $values ? (int) round(array_sum($values) / count($values)) : NULL;
    $terms = $this->memberManager->getClasses();
    $percents = $this->percentages(array_map(fn($tid) => $perClass[$tid]['active'] ?? 0, array_combine(array_keys($terms), array_keys($terms))));

    $classes = [];
    $index = 0;
    foreach ($terms as $tid => $term) {
      $class = $perClass[$tid] ?? ['active' => 0, 'joined' => 0, 'left' => 0, 'ages' => [], 'tenure' => []];
      $classes[] = [
        'name' => $term->label(),
        'color' => self::CLASS_COLORS[$index++ % count(self::CLASS_COLORS)],
        'active' => $class['active'],
        'active_percent' => $percents[$tid],
        'joined' => $class['joined'],
        'left' => $class['left'],
        'age' => $average($class['ages']),
        'tenure' => $history['partial'] || !empty($history['single']) ? NULL : $average($class['tenure']),
      ];
    }

    return [
      'active' => $totals['active'],
      'active_year_ago' => $history['partial'] || !empty($history['single']) ? NULL : $totals['active_year_ago'],
      'joined' => $totals['joined'],
      'joined_from' => $this->monthAccusative($joinedFrom),
      'left' => $totals['left'],
      'tenure' => $history['partial'] || !empty($history['single']) ? NULL : $average($totals['tenure']),
      'classes' => $classes,
    ];
  }

  /**
   * Whole months from one 'YYYY-MM' to another (0 for the same month).
   */
  protected function monthsBetween(string $from, string $to): int {
    [$fromYear, $fromMonth] = array_map('intval', explode('-', $from));
    [$toYear, $toMonth] = array_map('intval', explode('-', $to));
    return max(0, ($toYear - $fromYear) * 12 + $toMonth - $fromMonth);
  }

  /**
   * Whole-number percentages of a total that always add up to exactly 100:
   * each share is rounded down, and the points still missing go to the
   * shares that lost the most in rounding (largest remainder).
   *
   * @param float[] $values
   *   Keyed values.
   *
   * @return int[]
   *   Same keys; all 0 if the values add up to nothing.
   */
  protected function percentages(array $values): array {
    $total = array_sum($values);
    if ($total <= 0) {
      return array_map(fn() => 0, $values);
    }
    $percents = [];
    $remainders = [];
    foreach ($values as $key => $value) {
      $exact = $value / $total * 100;
      $percents[$key] = (int) floor($exact);
      $remainders[$key] = $exact - $percents[$key];
    }
    arsort($remainders);
    foreach (array_slice(array_keys($remainders), 0, 100 - array_sum($percents)) as $key) {
      $percents[$key]++;
    }
    return $percents;
  }

  /**
   * How many of the given months aren't closed months.
   *
   * @param \DateTimeImmutable[] $months
   * @param int[] $freeMonths
   *   Calendar months (1-12) that aren't charged.
   */
  protected function countOpenMonths(array $months, array $freeMonths): int {
    return count(array_filter($months, fn(\DateTimeImmutable $month) => !in_array((int) $month->format('n'), $freeMonths, TRUE)));
  }

  /**
   * "Νοέμβριο 2025", as in "από τον Νοέμβριο 2025": the month name without
   * its final ς.
   */
  protected function monthAccusative(\DateTimeImmutable $month): string {
    return mb_substr(MemberManager::MONTH_NAMES[(int) $month->format('n')], 0, -1) . ' ' . $month->format('Y');
  }

}
