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
   * The headline numbers: money actually received over the last 12 months
   * (by payment date, this month included), and what that makes per month
   * the academy was open.
   */
  public function basics(): array {
    $months = $this->lastYearMonths();
    $end = end($months);

    $total = (float) $this->database->query(
      'SELECT COALESCE(SUM(amount), 0) FROM {gg_members_payment} WHERE payment_date >= :start',
      [':start' => $months[0]->format('Y-m-d')]
    )->fetchField();

    // Months the academy is closed (summer) bring nothing in by design, so
    // they'd only drag the average down; it's per month of operation.
    $freeMonths = array_map('intval', $this->configFactory->get('gg_members.settings')->get('free_months') ?? []);
    $openMonths = $this->countOpenMonths($months, $freeMonths);

    return [
      'total' => $total,
      'average' => $openMonths ? $total / $openMonths : 0.0,
      'open_months' => $openMonths,
      'closed_months' => 12 - $openMonths,
      'from' => $this->monthAccusative($months[0]),
      'to' => $this->monthAccusative($end),
    ];
  }

  /**
   * The same last-year income month by month (by payment date), for the
   * monthly columns.
   *
   * @return array
   *   'months': one entry per month, oldest first — label, year, amount,
   *   percent (of the best month, for the column height), closed (a month
   *   the academy doesn't charge, with nothing in), current (this month, so
   *   still filling up), best (the highest complete month); 'best_amount'.
   */
  public function monthly(): array {
    $months = $this->lastYearMonths();
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
   * The same last-year income split by class: each class's share of the
   * money (by the paying member's class) and of the active members.
   *
   * @return array
   *   'total' and 'members' (whole academy), and 'classes': one entry per
   *   class in class order — name, color, revenue, revenue_percent,
   *   members, members_percent, average (per month the class was open).
   */
  public function byClass(): array {
    $months = $this->lastYearMonths();

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
      // Per month this class was open (classes can have their own closed
      // months, see the settings page).
      $openMonths = $this->countOpenMonths($months, $this->memberManager->getFreeMonths((int) $tid));
      $classes[] = [
        'name' => $term->label(),
        'color' => self::CLASS_COLORS[$index++ % count(self::CLASS_COLORS)],
        'revenue' => $classRevenue[$tid],
        'revenue_percent' => $revenuePercents[$tid],
        'members' => $classMembers[$tid],
        'members_percent' => $memberPercents[$tid],
        'average' => $openMonths ? $classRevenue[$tid] / $openMonths : 0.0,
      ];
    }

    return [
      'total' => $total,
      'members' => $totalMembers,
      'classes' => $classes,
    ];
  }

  /**
   * Membership over the last year: who's active now, who joined, who left
   * and how long people stay — for the whole academy and per class.
   *
   * @return array
   *   Whole-academy 'active', 'active_year_ago', 'joined', 'left', 'tenure'
   *   (average months a member stays: enrollment until leaving, or until
   *   now), and 'classes': one entry per class in class order (same colours
   *   as byClass()) — name, color, active, active_percent, joined, left,
   *   age (average, active members), tenure.
   */
  public function members(): array {
    $months = $this->lastYearMonths();
    $start = $months[0]->format('Y-m');
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
      if ($joinMonth >= $start) {
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
        'tenure' => $average($class['tenure']),
      ];
    }

    return [
      'active' => $totals['active'],
      'active_year_ago' => $totals['active_year_ago'],
      'joined' => $totals['joined'],
      'left' => $totals['left'],
      'tenure' => $average($totals['tenure']),
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
   * The last 12 months, this one included, oldest first.
   *
   * @return \DateTimeImmutable[]
   *   The first day of each month.
   */
  protected function lastYearMonths(): array {
    $months = [];
    $end = new \DateTimeImmutable('first day of this month');
    for ($cursor = $end->modify('-11 months'); $cursor <= $end; $cursor = $cursor->modify('+1 month')) {
      $months[] = $cursor;
    }
    return $months;
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
