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
  public function history(): array {
    $end = new \DateTimeImmutable('first day of this month');
    $yearStart = $end->modify('-11 months');
    $firstPayment = $this->database->query('SELECT MIN(payment_date) FROM {gg_members_payment}')->fetchField();
    $dataStart = $firstPayment ? (new \DateTimeImmutable($firstPayment))->modify('first day of this month') : $end;
    $start = max($yearStart, $dataStart);

    $months = [];
    for ($cursor = $start; $cursor <= $end; $cursor = $cursor->modify('+1 month')) {
      $months[] = $cursor;
    }

    return [
      'months' => $months,
      'partial' => $start > $yearStart,
      'empty' => !$firstPayment,
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
      'SELECT COALESCE(SUM(amount), 0) FROM {gg_members_payment} WHERE payment_date >= :start',
      [':start' => $months[0]->format('Y-m-d')]
    )->fetchField();

    // Months the academy is closed (summer) bring nothing in by design, so
    // they'd only drag the average down; it's per month of operation.
    $freeMonths = array_map('intval', $this->configFactory->get('gg_members.settings')->get('free_months') ?? []);
    [$completeTotal, $completeMonths] = $this->completeMonths($months, $freeMonths);

    return [
      'total' => $total,
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
    $months = $history['months'];
    $amounts = $this->database->query(
      'SELECT SUBSTRING(payment_date, 1, 7), SUM(amount) FROM {gg_members_payment} WHERE payment_date >= :start GROUP BY SUBSTRING(payment_date, 1, 7)',
      [':start' => $months[0]->format('Y-m-d')]
    )->fetchAllKeyed();
    $freeMonths = array_map('intval', $this->configFactory->get('gg_members.settings')->get('free_months') ?? []);
    $currentMonth = end($months)->format('Y-m');

    // The best month is the best *complete* one: this month is still
    // filling up, so it only counts if there's nothing else to compare.
    $complete = array_map('floatval', array_diff_key($amounts, [$currentMonth => TRUE]));
    $best = $complete ? max($complete) : (float) ($amounts[$currentMonth] ?? 0);
    $rows = [];
    foreach ($months as $month) {
      $key = $month->format('Y-m');
      $amount = (float) ($amounts[$key] ?? 0);
      $rows[] = [
        'label' => self::SHORT_MONTHS[(int) $month->format('n')],
        'name' => MemberManager::formatMonth($key),
        'year' => $month->format('Y'),
        'amount' => $amount,
        'percent' => $best > 0 ? min(100, round($amount / $best * 100, 1)) : 0,
        'closed' => $amount == 0 && in_array((int) $month->format('n'), $freeMonths, TRUE),
        'current' => $key === $currentMonth,
        'best' => $best > 0 && $amount == $best && ($key !== $currentMonth || !$complete),
      ];
    }

    return ['months' => $rows, 'best_amount' => $best];
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
      'SELECT m.class_tid, SUM(p.amount) FROM {gg_members_payment} p INNER JOIN {gg_members_member} m ON m.id = p.member_id WHERE p.payment_date >= :start GROUP BY m.class_tid',
      [':start' => $months[0]->format('Y-m-d')]
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
      if ($member->enrollment_date && $joinMonth >= $joinedFrom->format('Y-m') && !($history['partial'] && count($months) === 1)) {
        $totals['joined']++;
        $perClass[$tid]['joined']++;
      }
      if ($leaveMonth && $leaveMonth >= $start) {
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
        'tenure' => $history['partial'] ? NULL : $average($class['tenure']),
      ];
    }

    return [
      'active' => $totals['active'],
      'active_year_ago' => $history['partial'] ? NULL : $totals['active_year_ago'],
      'joined' => $totals['joined'],
      'joined_from' => $this->monthAccusative($joinedFrom),
      'left' => $totals['left'],
      'tenure' => $history['partial'] ? NULL : $average($totals['tenure']),
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
