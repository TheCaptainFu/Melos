<?php

namespace Drupal\gg_members;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\Session\AccountInterface;

/**
 * The academy's expenses (rent, coaches, bills…), and what's left of the
 * month's income after them.
 *
 * Fixed monthly costs are set up once as recurring expenses ("πάγια") and
 * entered automatically each month on their day — see generateRecurring().
 */
class ExpenseManager {

  /**
   * Expense categories: key => [label, colour].
   */
  public const CATEGORIES = [
    'rent' => ['Ενοίκιο', '#2563eb'],
    'staff' => ['Προπονητές / προσωπικό', '#7c3aed'],
    'bills' => ['Λογαριασμοί', '#0d9488'],
    'equipment' => ['Εξοπλισμός', '#f59e0b'],
    'travel' => ['Μετακινήσεις / αγώνες', '#db2777'],
    'marketing' => ['Διαφήμιση', '#16a34a'],
    'other' => ['Άλλο', '#64748b'],
  ];

  public function __construct(
    protected Connection $database,
    protected TimeInterface $time,
    protected AccountInterface $currentUser,
    protected ConfigFactoryInterface $configFactory,
    protected LockBackendInterface $lock,
  ) {}

  /**
   * Category key => label, for select lists.
   */
  public static function categoryOptions(): array {
    return array_map(fn(array $category) => $category[0], self::CATEGORIES);
  }

  /**
   * One expense, or NULL.
   */
  public function get(int $id): ?\stdClass {
    $row = $this->database->select('gg_members_expense', 'e')
      ->fields('e')
      ->condition('id', $id)
      ->execute()
      ->fetchObject();
    return $row ?: NULL;
  }

  /**
   * A month's expenses, newest first.
   *
   * @param string $month
   *   'YYYY-MM'.
   *
   * @return \stdClass[]
   */
  public function forMonth(string $month): array {
    return $this->database->select('gg_members_expense', 'e')
      ->fields('e')
      ->condition('expense_date', $month . '-01', '>=')
      ->condition('expense_date', $this->nextMonth($month) . '-01', '<')
      ->orderBy('expense_date', 'DESC')
      ->orderBy('id', 'DESC')
      ->execute()
      ->fetchAll();
  }

  /**
   * A month's expenses per category, biggest first: category => total.
   */
  public function totalsByCategory(string $month): array {
    $totals = [];
    foreach ($this->forMonth($month) as $expense) {
      $totals[$expense->category] = ($totals[$expense->category] ?? 0) + (float) $expense->amount;
    }
    arsort($totals);
    return $totals;
  }

  /**
   * Money received in a month (payments by payment date — the same measure
   * as the statistics page's income).
   */
  public function income(string $month): float {
    return (float) $this->database->query(
      'SELECT COALESCE(SUM(amount), 0) FROM {gg_members_payment} WHERE payment_date >= :start AND payment_date < :end',
      [':start' => $month . '-01', ':end' => $this->nextMonth($month) . '-01']
    )->fetchField();
  }

  /**
   * Saves an expense: a new one, or changes to an existing one ($id).
   *
   * @param array $data
   *   expense_date ('YYYY-MM-DD'), category, amount, description.
   */
  public function save(array $data, ?int $id = NULL): int {
    $fields = [
      'expense_date' => $data['expense_date'],
      'category' => isset(self::CATEGORIES[$data['category']]) ? $data['category'] : 'other',
      'amount' => $data['amount'],
      'description' => ($data['description'] ?? '') ?: NULL,
    ];
    if ($id) {
      $this->database->update('gg_members_expense')->fields($fields)->condition('id', $id)->execute();
      return $id;
    }
    return (int) $this->database->insert('gg_members_expense')
      ->fields($fields + [
        'uid' => (int) $this->currentUser->id(),
        'created' => $this->time->getRequestTime(),
      ])
      ->execute();
  }

  public function delete(int $id): void {
    $this->database->delete('gg_members_expense')->condition('id', $id)->execute();
  }

  /**
   * One recurring expense, or NULL.
   */
  public function getRecurring(int $id): ?\stdClass {
    $row = $this->database->select('gg_members_expense_recurring', 'r')
      ->fields('r')
      ->condition('id', $id)
      ->execute()
      ->fetchObject();
    return $row ?: NULL;
  }

  /**
   * All recurring expenses, active ones first, then by day of the month.
   *
   * @return \stdClass[]
   */
  public function allRecurring(): array {
    return $this->database->select('gg_members_expense_recurring', 'r')
      ->fields('r')
      ->orderBy('active', 'DESC')
      ->orderBy('day')
      ->orderBy('id')
      ->execute()
      ->fetchAll();
  }

  /**
   * Saves a recurring expense: a new one, or changes to an existing one
   * ($id) — which apply to the months entered from now on, never to ones
   * already entered.
   *
   * @param array $data
   *   start_date ('YYYY-MM-DD': the first one; its day of the month is
   *   repeated every month), category, amount, description; optionally
   *   only_open (skip the months the academy is closed) and active — left
   *   as they are on an existing one when not given.
   */
  public function saveRecurring(array $data, ?int $id = NULL): int {
    $fields = [
      'category' => isset(self::CATEGORIES[$data['category']]) ? $data['category'] : 'other',
      'amount' => $data['amount'],
      'day' => max(1, min(31, (int) substr($data['start_date'], 8, 2))),
      'start_month' => substr($data['start_date'], 0, 7),
      'description' => $data['description'],
    ];
    foreach (['only_open', 'active'] as $flag) {
      if (array_key_exists($flag, $data)) {
        $fields[$flag] = (int) !empty($data[$flag]);
      }
    }
    if ($id) {
      $this->database->update('gg_members_expense_recurring')->fields($fields)->condition('id', $id)->execute();
    }
    else {
      $id = (int) $this->database->insert('gg_members_expense_recurring')
        ->fields($fields + [
          'only_open' => 0,
          'active' => 1,
          'created' => $this->time->getRequestTime(),
        ])
        ->execute();
    }
    // A new one whose day has already come this month is entered now.
    $this->generateRecurring();
    return $id;
  }

  /**
   * Deletes a recurring expense. The expenses it already entered stay.
   */
  public function deleteRecurring(int $id): void {
    $this->database->delete('gg_members_expense_recurring')->condition('id', $id)->execute();
  }

  /**
   * Enters every active recurring expense for each month it's due and
   * hasn't been entered yet, up to today. Run whenever the expenses or
   * statistics are looked at, and on cron, so nothing depends on anyone
   * remembering.
   *
   * Each recurring expense remembers the last month it handled, so a month
   * is never entered twice — and an entry deleted by hand (say the rent
   * wasn't paid that month) doesn't come back.
   *
   * @return int
   *   How many expenses were entered.
   */
  public function generateRecurring(): int {
    // Two page loads at once must not both enter the same month.
    if (!$this->lock->acquire('gg_members_recurring_expenses')) {
      return 0;
    }
    try {
      $today = date('Y-m-d', $this->time->getRequestTime());
      $freeMonths = array_map('intval', $this->configFactory->get('gg_members.settings')->get('free_months') ?? []);
      $entered = 0;

      $items = $this->database->select('gg_members_expense_recurring', 'r')
        ->fields('r')
        ->condition('active', 1)
        ->execute()
        ->fetchAll();
      foreach ($items as $item) {
        // From its first month, or the month after the last one handled —
        // whichever is later (the first date may have been moved forward).
        $month = $item->last_month ? max($item->start_month, $this->nextMonth($item->last_month)) : $item->start_month;
        $handled = $item->last_month;
        while ($month <= substr($today, 0, 7)) {
          // Day 29-31 falls on the last day of shorter months.
          $daysInMonth = (int) (new \DateTimeImmutable($month . '-01'))->format('t');
          $date = $month . '-' . sprintf('%02d', min((int) $item->day, $daysInMonth));
          if ($date > $today) {
            break;
          }
          $closed = $item->only_open && in_array((int) substr($month, 5, 2), $freeMonths, TRUE);
          if (!$closed) {
            $this->database->insert('gg_members_expense')
              ->fields([
                'expense_date' => $date,
                'category' => $item->category,
                'amount' => $item->amount,
                'description' => $item->description,
                'recurring_id' => $item->id,
                'uid' => 0,
                'created' => $this->time->getRequestTime(),
              ])
              ->execute();
            $entered++;
          }
          $handled = $month;
          $month = $this->nextMonth($month);
        }
        if ($handled !== $item->last_month) {
          $this->database->update('gg_members_expense_recurring')
            ->fields(['last_month' => $handled])
            ->condition('id', $item->id)
            ->execute();
        }
      }
      return $entered;
    }
    finally {
      $this->lock->release('gg_members_recurring_expenses');
    }
  }

  /**
   * 'YYYY-MM' of the month after the given one.
   */
  public function nextMonth(string $month): string {
    return (new \DateTimeImmutable($month . '-01'))->modify('+1 month')->format('Y-m');
  }

  /**
   * 'YYYY-MM' of the month before the given one.
   */
  public function previousMonth(string $month): string {
    return (new \DateTimeImmutable($month . '-01'))->modify('-1 month')->format('Y-m');
  }

}
