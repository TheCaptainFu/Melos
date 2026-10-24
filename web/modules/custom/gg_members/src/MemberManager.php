<?php

namespace Drupal\gg_members;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Query\SelectInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Url;
use Drupal\file\FileInterface;
use Drupal\file\FileUsage\FileUsageInterface;

/**
 * CRUD and payment-status logic for the member roster.
 */
class MemberManager {

  /**
   * Document categories a member can upload files under - each unlimited.
   */
  public const FILE_CATEGORIES = ['doctor_note', 'photo', 'birth_certificate', 'card'];

  /**
   * Greek month names, keyed 1-12.
   */
  public const MONTH_NAMES = [1 => 'Ιανουάριος', 'Φεβρουάριος', 'Μάρτιος', 'Απρίλιος', 'Μάιος', 'Ιούνιος', 'Ιούλιος', 'Αύγουστος', 'Σεπτέμβριος', 'Οκτώβριος', 'Νοέμβριος', 'Δεκέμβριος'];

  /**
   * How many months ahead a payment can be recorded for (advance payment).
   */
  public const MONTHS_AHEAD = 2;

  /**
   * Non-billable calendar months per class tid, cached for the request.
   */
  protected array $freeMonths = [];

  public function __construct(
    protected Connection $database,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected TimeInterface $time,
    protected FileUsageInterface $fileUsage,
    protected ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * Returns all classes (terms) in the gg_members_class vocabulary.
   *
   * @return \Drupal\taxonomy\TermInterface[]
   */
  public function getClasses(): array {
    $storage = $this->entityTypeManager->getStorage('taxonomy_term');
    $ids = $storage->getQuery()
      ->condition('vid', 'gg_members_class')
      ->accessCheck(FALSE)
      ->sort('weight')
      ->sort('name')
      ->execute();

    return $ids ? $storage->loadMultiple($ids) : [];
  }

  /**
   * Builds (but does not execute) a filtered, joined member listing query.
   *
   * Doesn't filter by "owes" — whether a member owes anything depends on
   * their whole payment history since enrollment (see getUnpaidMonths()),
   * which isn't practical to express as a single SQL condition here; the
   * caller filters the loaded rows instead.
   *
   * @param array $filters
   *   Optional keys: class_tid, status, search (matches name/phone/email/ΑΜΚΑ).
   */
  public function membersQuery(array $filters = []): SelectInterface {
    $query = $this->database->select('gg_members_member', 'm');
    $query->fields('m');
    $query->leftJoin('taxonomy_term_field_data', 't', 'm.class_tid = t.tid');
    $query->addField('t', 'name', 'class_name');

    if (!empty($filters['class_tid'])) {
      $query->condition('m.class_tid', $filters['class_tid']);
    }
    if (isset($filters['status']) && $filters['status'] !== '') {
      $query->condition('m.status', $filters['status']);
    }
    if (!empty($filters['search'])) {
      $like = '%' . $this->database->escapeLike($filters['search']) . '%';
      $query->condition($query->orConditionGroup()
        ->condition('m.name', $like, 'LIKE')
        ->condition('m.phone', $like, 'LIKE')
        ->condition('m.email', $like, 'LIKE')
        ->condition('m.amka', $like, 'LIKE')
        ->condition('m.card_number', $like, 'LIKE'));
    }

    $query->orderBy('m.name');

    return $query;
  }

  /**
   * Loads a single member row, or NULL if it doesn't exist.
   */
  public function getMember(int $id): ?\stdClass {
    $row = $this->database->select('gg_members_member', 'm')
      ->fields('m')
      ->condition('id', $id)
      ->execute()
      ->fetchObject();

    return $row ?: NULL;
  }

  /**
   * Calendar months (1-12) a class isn't charged for, e.g. July and August
   * when the academy is closed: the class's own list from the settings page
   * if it has one, otherwise the global default.
   *
   * @return int[]
   */
  public function getFreeMonths(int $classTid): array {
    if (!isset($this->freeMonths[$classTid])) {
      $config = $this->configFactory->get('gg_members.settings');
      $months = $config->get('free_months') ?? [];
      foreach ($config->get('class_free_months') ?? [] as $override) {
        if ((int) $override['tid'] === $classTid) {
          $months = $override['months'] ?? [];
          break;
        }
      }
      $this->freeMonths[$classTid] = array_map('intval', $months);
    }
    return $this->freeMonths[$classTid];
  }

  /**
   * Month-by-month account for one member: what each billable month costs
   * (at the fee in force that month), how much has been paid towards it,
   * and what's left. Runs from enrollment through the current month (or the
   * month they left), plus $monthsAhead future months for advance payment;
   * months the member's class isn't charged for are left out entirely.
   *
   * @param array $paid
   *   'YYYY-MM' => total paid towards that month (see getPaidAmounts()).
   * @param array $fees
   *   'YYYY-MM' => fee in force from that month on, oldest first (see
   *   getFeeHistory()).
   *
   * @return array
   *   'YYYY-MM' => ['due' => float, 'paid' => float, 'remaining' => float],
   *   oldest first.
   */
  public function computeLedger(\stdClass $member, array $paid, array $fees, int $monthsAhead = 0): array {
    $start = $this->enrollmentMonth($member);
    $end = $this->billingEndMonth($member);
    if ($monthsAhead && (int) $member->status) {
      $end = $end->modify('+' . $monthsAhead . ' months');
    }
    $freeMonths = $this->getFreeMonths((int) $member->class_tid);

    $ledger = [];
    for ($cursor = $start; $cursor <= $end; $cursor = $cursor->modify('+1 month')) {
      if (in_array((int) $cursor->format('n'), $freeMonths, TRUE)) {
        continue;
      }
      $month = $cursor->format('Y-m');
      $due = $this->feeForMonth($member, $fees, $month);
      $paidAmount = round((float) ($paid[$month] ?? 0), 2);
      $ledger[$month] = [
        'due' => $due,
        'paid' => $paidAmount,
        'remaining' => max(0.0, round($due - $paidAmount, 2)),
      ];
    }

    return $ledger;
  }

  /**
   * Loads and computes one member's ledger (see computeLedger()).
   */
  public function getLedger(int $memberId, int $monthsAhead = 0): array {
    $member = $this->getMember($memberId);
    return $member
      ? $this->computeLedger($member, $this->getPaidAmounts($memberId), $this->getFeeHistory($memberId), $monthsAhead)
      : [];
  }

  /**
   * Lists every billable month from enrollment through the current month
   * that isn't fully paid — the member's whole outstanding balance, not just
   * this month. A partly paid month still counts until it's settled.
   *
   * @return string[]
   *   'YYYY-MM' values, oldest first.
   */
  public function getUnpaidMonths(int $memberId): array {
    return array_keys(self::openMonths($this->getLedger($memberId)));
  }

  /**
   * Same as getUnpaidMonths(), for an already-loaded member row — lets
   * listings compute it for the whole roster from getPaidAmountsByMember()
   * and getFeeHistoryByMember() instead of several queries per member.
   */
  public function computeUnpaidMonths(\stdClass $member, array $paid, array $fees): array {
    return array_keys(self::openMonths($this->computeLedger($member, $paid, $fees)));
  }

  /**
   * Whether a member has any unpaid month in their history.
   */
  public function owesAnything(int $memberId): bool {
    return (bool) $this->getUnpaidMonths($memberId);
  }

  /**
   * Months a payment can be recorded for: every not-yet-settled month from
   * enrollment through MONTHS_AHEAD months into the future. Backs the month
   * checkboxes on the payment form, so a month before enrollment, a month
   * the class isn't charged for, or one already settled can't be picked.
   *
   * @return array
   *   Ledger entries (see computeLedger()) keyed 'YYYY-MM', oldest first.
   */
  public function getPayableMonths(int $memberId): array {
    return self::openMonths($this->getLedger($memberId, self::MONTHS_AHEAD));
  }

  /**
   * The ledger entries that still have something left to pay.
   */
  public static function openMonths(array $ledger): array {
    return array_filter($ledger, fn(array $entry) => $entry['remaining'] > 0);
  }

  /**
   * Total still owed across a ledger.
   */
  public static function outstanding(array $ledger): float {
    return round(array_sum(array_column($ledger, 'remaining')), 2);
  }

  /**
   * The fee charged for one month: the most recent fee change on or before
   * it. Months before the first recorded change use that first fee.
   */
  protected function feeForMonth(\stdClass $member, array $fees, string $month): float {
    if (!$fees) {
      return (float) $member->monthly_fee;
    }
    $fee = reset($fees);
    foreach ($fees as $from => $amount) {
      if ($from > $month) {
        break;
      }
      $fee = $amount;
    }
    return (float) $fee;
  }

  /**
   * The first day of the month a member's subscription starts counting
   * from: their enrollment date, or lacking that, when the record was
   * created. Capped at 120 months back so a bad/ancient date can't cause
   * an effectively unbounded month range elsewhere.
   */
  protected function enrollmentMonth(\stdClass $member): \DateTimeImmutable {
    $startDate = $member->enrollment_date ?: date('Y-m-d', (int) $member->created);
    $start = (new \DateTimeImmutable($startDate))->modify('first day of this month');

    $earliest = (new \DateTimeImmutable('first day of this month'))->modify('-120 months');
    return $start < $earliest ? $earliest : $start;
  }

  /**
   * The last month a member is charged for: the current month, or for an
   * inactive member the month they left (inclusive) — so someone who quit
   * doesn't keep "owing" every month forever.
   */
  protected function billingEndMonth(\stdClass $member): \DateTimeImmutable {
    $end = new \DateTimeImmutable('first day of this month');
    if (!(int) $member->status) {
      $leftDate = ($member->left_date ?? NULL) ?: date('Y-m-d', (int) $member->changed);
      $left = (new \DateTimeImmutable($leftDate))->modify('first day of this month');
      if ($left < $end) {
        $end = $left;
      }
    }
    return $end;
  }

  /**
   * Every member's paid totals per month in one query:
   * member id => ['YYYY-MM' => amount].
   */
  public function getPaidAmountsByMember(): array {
    $map = [];
    foreach ($this->paidAmountsQuery()->execute() as $row) {
      $map[(int) $row->member_id][$row->month_covered] = (float) $row->total;
    }
    return $map;
  }

  /**
   * One member's paid totals per month: 'YYYY-MM' => amount. Several
   * payments towards the same month (instalments) are added up.
   */
  public function getPaidAmounts(int $memberId): array {
    $query = $this->paidAmountsQuery();
    $query->condition('p.member_id', $memberId);
    return array_map('floatval', $query->execute()->fetchAllKeyed(1, 2));
  }

  protected function paidAmountsQuery(): SelectInterface {
    $query = $this->database->select('gg_members_payment', 'p');
    $query->fields('p', ['member_id', 'month_covered']);
    $query->addExpression('SUM(p.amount)', 'total');
    $query->groupBy('p.member_id');
    $query->groupBy('p.month_covered');
    return $query;
  }

  /**
   * Every member's fee history in one query:
   * member id => ['YYYY-MM' => fee], oldest first.
   */
  public function getFeeHistoryByMember(): array {
    $map = [];
    $rows = $this->database->select('gg_members_fee', 'f')
      ->fields('f', ['member_id', 'effective_from', 'fee'])
      ->orderBy('effective_from')
      ->execute();
    foreach ($rows as $row) {
      $map[(int) $row->member_id][$row->effective_from] = (float) $row->fee;
    }
    return $map;
  }

  /**
   * One member's fee history: 'YYYY-MM' => fee in force from that month on,
   * oldest first.
   */
  public function getFeeHistory(int $memberId): array {
    return array_map('floatval', $this->database->select('gg_members_fee', 'f')
      ->fields('f', ['effective_from', 'fee'])
      ->condition('member_id', $memberId)
      ->orderBy('effective_from')
      ->execute()
      ->fetchAllKeyed());
  }

  /**
   * Sets a member's fee from a given month on. Replaces any change already
   * recorded for that month or later, so "45€ from March" means exactly
   * that, whatever was there before. Months before it keep their old fee.
   *
   * @param string $fromMonth
   *   'YYYY-MM'.
   */
  public function setFee(int $memberId, float|string $fee, string $fromMonth): void {
    $transaction = $this->database->startTransaction();
    try {
      $this->database->delete('gg_members_fee')
        ->condition('member_id', $memberId)
        ->condition('effective_from', $fromMonth, '>=')
        ->execute();
      $this->database->insert('gg_members_fee')
        ->fields([
          'member_id' => $memberId,
          'fee' => $fee,
          'effective_from' => $fromMonth,
          'created' => $this->time->getRequestTime(),
        ])
        ->execute();
    }
    catch (\Exception $e) {
      $transaction->rollBack();
      throw $e;
    }
  }

  /**
   * Which document categories each member has at least one file in, in one
   * query: member id => [category => TRUE].
   */
  public function getFileCategoriesByMember(): array {
    $map = [];
    $rows = $this->database->select('gg_members_file', 'f')
      ->fields('f', ['member_id', 'category'])
      ->distinct()
      ->execute();
    foreach ($rows as $row) {
      $map[(int) $row->member_id][$row->category] = TRUE;
    }
    return $map;
  }

  /**
   * The roster page (with its page number, search and filters) the editor
   * came from, carried along in a ?back= query parameter, so "← Όλα τα μέλη"
   * and saving return them there instead of to page 1.
   *
   * Only internal /admin/members URLs are accepted (no open redirect).
   * A dedicated parameter rather than Drupal's ?destination=, which would
   * also hijack the payments form's own stay-on-this-page redirect.
   */
  public static function backFromRequest(): ?string {
    $back = \Drupal::request()->query->get('back');
    if (!is_string($back) || !preg_match('#^/admin/members(?:[/?]|$)#', $back) || str_contains($back, '//')) {
      return NULL;
    }
    return $back;
  }

  /**
   * The roster URL to return to: the carried ?back= one, or page 1.
   */
  public static function listUrl(?string $back): Url {
    return $back ? Url::fromUserInput($back) : Url::fromRoute('gg_members.dashboard');
  }

  /**
   * Adds the ?back= roster URL to a link so it survives the next click.
   */
  public static function withBack(Url $url, ?string $back): Url {
    if ($back) {
      $url->setOption('query', ['back' => $back] + ($url->getOption('query') ?: []));
    }
    return $url;
  }

  /**
   * "← Όλα τα μέλη" (+ "Προφίλ μέλους" for an existing member) button row
   * pinned to the top of the member add/edit and payments forms, so the
   * editor always has a way back without hunting for it.
   */
  public static function backLinks(?int $memberId = NULL): array {
    $back = self::backFromRequest();
    $element = [
      '#type' => 'container',
      '#attributes' => ['class' => ['gg-members-actions']],
      '#weight' => -100,
      '#cache' => ['contexts' => ['url.query_args:back']],
      'list' => [
        '#type' => 'link',
        '#title' => t('← Όλα τα μέλη'),
        '#url' => self::listUrl($back),
        '#attributes' => ['class' => ['button']],
      ],
    ];
    if ($memberId) {
      $element['profile'] = [
        '#type' => 'link',
        '#title' => t('Προφίλ μέλους'),
        '#url' => self::withBack(Url::fromRoute('gg_members.member_view', ['member' => $memberId]), $back),
        '#attributes' => ['class' => ['button']],
      ];
    }
    return $element;
  }

  /**
   * Formats a 'YYYY-MM' month as e.g. "Σεπτέμβριος 2026".
   */
  public static function formatMonth(string $month): string {
    $parts = explode('-', $month);
    $number = (int) ($parts[1] ?? 0);
    return isset(self::MONTH_NAMES[$number]) ? self::MONTH_NAMES[$number] . ' ' . $parts[0] : $month;
  }

  /**
   * Formats a ledger month as e.g. "Σεπτέμβριος 2026", adding what's left
   * for a partly paid one: "Σεπτέμβριος 2026 (υπόλοιπο 10,00€)".
   */
  public static function formatLedgerMonth(string $month, array $entry): string {
    $label = self::formatMonth($month);
    if ($entry['paid'] > 0 && $entry['remaining'] > 0) {
      $label .= ' (' . t('υπόλοιπο @amount€', ['@amount' => self::formatMoney($entry['remaining'])]) . ')';
    }
    return $label;
  }

  /**
   * Formats an amount the Greek way: 1.234,50.
   */
  public static function formatMoney(float|string $amount): string {
    return number_format((float) $amount, 2, ',', '.');
  }

  /**
   * Creates a new member, with their fee in force from enrollment on.
   */
  public function createMember(array $data): int {
    $now = $this->time->getRequestTime();
    $id = (int) $this->database->insert('gg_members_member')
      ->fields($this->memberFields($data) + [
        'created' => $now,
        'changed' => $now,
      ])
      ->execute();

    $fromMonth = $data['enrollment_date'] ? substr($data['enrollment_date'], 0, 7) : date('Y-m', $now);
    $this->setFee($id, $data['monthly_fee'], $fromMonth);

    return $id;
  }

  /**
   * Updates an existing member. A changed fee applies from $feeFromMonth
   * ('YYYY-MM', default the current month) on; earlier months keep being
   * charged the fee that was in force back then.
   */
  public function updateMember(int $id, array $data, ?string $feeFromMonth = NULL): void {
    $old = $this->getMember($id);

    $this->database->update('gg_members_member')
      ->fields($this->memberFields($data) + ['changed' => $this->time->getRequestTime()])
      ->condition('id', $id)
      ->execute();

    if ($old && round((float) $old->monthly_fee, 2) !== round((float) $data['monthly_fee'], 2)) {
      $this->setFee($id, $data['monthly_fee'], $feeFromMonth ?? date('Y-m'));
    }
  }

  /**
   * Deletes a member, their payment history, and their uploaded files
   * (releasing file usage so Drupal's file GC can clean them up).
   */
  public function deleteMember(int $id): void {
    $transaction = $this->database->startTransaction();
    try {
      $fileStorage = $this->entityTypeManager->getStorage('file');
      $fids = $this->database->select('gg_members_file', 'f')
        ->fields('f', ['fid'])
        ->condition('member_id', $id)
        ->execute()
        ->fetchCol();
      foreach ($fileStorage->loadMultiple($fids) as $file) {
        $this->releaseFile($file, $id);
      }

      $this->database->delete('gg_members_file')->condition('member_id', $id)->execute();
      $this->database->delete('gg_members_payment')->condition('member_id', $id)->execute();
      $this->database->delete('gg_members_fee')->condition('member_id', $id)->execute();
      $this->database->delete('gg_members_reminder')->condition('member_id', $id)->execute();
      $this->database->delete('gg_members_member')->condition('id', $id)->execute();
    }
    catch (\Exception $e) {
      $transaction->rollBack();
      throw $e;
    }
  }

  /**
   * Normalizes the common field set shared by create/update.
   */
  protected function memberFields(array $data): array {
    return [
      'name' => $data['name'],
      'class_tid' => (int) $data['class_tid'],
      'monthly_fee' => $data['monthly_fee'],
      'phone' => $data['phone'] ?? NULL,
      'email' => $data['email'] ?? NULL,
      'guardian_name' => $data['guardian_name'] ?? NULL,
      'guardian_phone' => $data['guardian_phone'] ?? NULL,
      'enrollment_date' => $data['enrollment_date'] ?? NULL,
      'birth_date' => ($data['birth_date'] ?? NULL) ?: NULL,
      'card_number' => ($data['card_number'] ?? NULL) ?: NULL,
      'amka' => ($data['amka'] ?? NULL) ?: NULL,
      'status' => (int) ($data['status'] ?? 1),
      'left_date' => ($data['left_date'] ?? NULL) ?: NULL,
      'notes' => $data['notes'] ?? NULL,
    ];
  }

  /**
   * Lists a member's uploaded files in one category, oldest first.
   *
   * @return \stdClass[]
   */
  public function getMemberFiles(int $memberId, string $category): array {
    return $this->database->select('gg_members_file', 'f')
      ->fields('f', ['id', 'fid'])
      ->condition('member_id', $memberId)
      ->condition('category', $category)
      ->orderBy('id')
      ->execute()
      ->fetchAll();
  }

  /**
   * Whether a member has at least one file in the given category - backs
   * the dashboard's doctor's-note badge.
   */
  public function hasFiles(int $memberId, string $category): bool {
    return (bool) $this->database->select('gg_members_file', 'f')
      ->condition('member_id', $memberId)
      ->condition('category', $category)
      ->range(0, 1)
      ->countQuery()
      ->execute()
      ->fetchField();
  }

  /**
   * Reconciles one category's stored files against a managed_file widget's
   * submitted fids: newly-attached files are made permanent and tracked,
   * files the editor removed in the widget are released. No cap on count -
   * a member can have as many files as needed in each category.
   *
   * @param int[] $fids
   *   The category's #multiple managed_file value after submission.
   */
  public function syncMemberFiles(int $memberId, string $category, array $fids): void {
    $fids = array_unique(array_filter(array_map('intval', $fids)));
    $existing = [];
    foreach ($this->getMemberFiles($memberId, $category) as $row) {
      $existing[(int) $row->fid] = (int) $row->id;
    }

    $fileStorage = $this->entityTypeManager->getStorage('file');

    foreach ($fids as $fid) {
      if (isset($existing[$fid])) {
        // Still attached - leave it alone.
        unset($existing[$fid]);
        continue;
      }
      $file = $fileStorage->load($fid);
      if ($file instanceof FileInterface) {
        if (!$file->isPermanent()) {
          $file->setPermanent();
          $file->save();
        }
        $this->fileUsage->add($file, 'gg_members', 'gg_members_member', $memberId);
      }
      $this->database->insert('gg_members_file')
        ->fields([
          'member_id' => $memberId,
          'category' => $category,
          'fid' => $fid,
          'created' => $this->time->getRequestTime(),
        ])
        ->execute();
    }

    // Whatever's left in $existing was removed from the widget.
    foreach ($existing as $fid => $rowId) {
      $file = $fileStorage->load($fid);
      if ($file instanceof FileInterface) {
        $this->releaseFile($file, $memberId);
      }
      $this->database->delete('gg_members_file')->condition('id', $rowId)->execute();
    }
  }

  /**
   * Drops this member's usage of a file and, if nothing else uses it,
   * deletes it outright. Core otherwise leaves unused files on disk forever
   * (make_unused_managed_files_temporary is off), which isn't acceptable for
   * medical certificates and birth certificates of people no longer here.
   */
  protected function releaseFile(FileInterface $file, int $memberId): void {
    $this->fileUsage->delete($file, 'gg_members', 'gg_members_member', $memberId);
    if (!$this->fileUsage->listUsage($file)) {
      $file->delete();
    }
  }

  /**
   * Lists a member's payment history, most recent first.
   *
   * @return \stdClass[]
   */
  public function getPaymentHistory(int $memberId): array {
    return $this->database->select('gg_members_payment', 'p')
      ->fields('p')
      ->condition('member_id', $memberId)
      ->orderBy('month_covered', 'DESC')
      ->orderBy('id', 'DESC')
      ->execute()
      ->fetchAll();
  }

  /**
   * Records one payment towards one month. A month can take several
   * payments (instalments); it's settled once they add up to its fee.
   */
  public function recordPayment(int $memberId, array $data): void {
    $this->database->insert('gg_members_payment')
      ->fields([
        'member_id' => $memberId,
        'month_covered' => $data['month_covered'],
        'amount' => $data['amount'],
        'payment_date' => $data['payment_date'],
        'notes' => ($data['notes'] ?? NULL) ?: NULL,
        'created' => $this->time->getRequestTime(),
      ])
      ->execute();
  }

  /**
   * Splits one amount across several months, oldest first, each taking up
   * to what's still owed on it. E.g. 100€ for Sep-Nov at 40€/month settles
   * Sep and Oct and leaves 20€ towards Nov.
   *
   * @param string[] $months
   *   'YYYY-MM' months to pay, which must all be payable (see
   *   getPayableMonths()).
   *
   * @return array
   *   'YYYY-MM' => amount recorded, for the months that got something.
   */
  public function recordPayments(int $memberId, array $months, float $amount, string $paymentDate, ?string $notes = NULL): array {
    $payable = $this->getPayableMonths($memberId);
    sort($months);

    // Whole cents, so the split can't drift by floating point rounding.
    $left = (int) round($amount * 100);
    $recorded = [];

    $transaction = $this->database->startTransaction();
    try {
      foreach ($months as $month) {
        if ($left <= 0 || !isset($payable[$month])) {
          continue;
        }
        $share = min($left, (int) round($payable[$month]['remaining'] * 100));
        $this->recordPayment($memberId, [
          'month_covered' => $month,
          'amount' => $share / 100,
          'payment_date' => $paymentDate,
          'notes' => $notes,
        ]);
        $recorded[$month] = $share / 100;
        $left -= $share;
      }
    }
    catch (\Exception $e) {
      $transaction->rollBack();
      throw $e;
    }

    return $recorded;
  }

  /**
   * Removes one payment record.
   */
  public function deletePayment(int $id, int $memberId): bool {
    $affected = $this->database->delete('gg_members_payment')
      ->condition('id', $id)
      ->condition('member_id', $memberId)
      ->execute();

    return (bool) $affected;
  }

  /**
   * Totals for one member's payments page: what they've paid in total,
   * how many payments, and when the last one landed.
   */
  public function getMemberStats(int $memberId): array {
    $totalPaid = (float) $this->database->query(
      'SELECT COALESCE(SUM(amount), 0) FROM {gg_members_payment} WHERE member_id = :id',
      [':id' => $memberId]
    )->fetchField();

    $paymentCount = (int) $this->database->select('gg_members_payment', 'p')
      ->condition('member_id', $memberId)
      ->countQuery()
      ->execute()
      ->fetchField();

    $lastPaymentDate = $this->database->select('gg_members_payment', 'p')
      ->fields('p', ['payment_date'])
      ->condition('member_id', $memberId)
      ->orderBy('payment_date', 'DESC')
      ->range(0, 1)
      ->execute()
      ->fetchField();

    return [
      'total_paid' => $totalPaid,
      'payment_count' => $paymentCount,
      'last_payment_date' => $lastPaymentDate ?: NULL,
    ];
  }

  /**
   * The whole roster at a glance, for the roster's stats bar: member
   * counts, who owes and how much, and this month's collection
   * — what's due from everyone charged for it, how much of that has come in,
   * and how many have settled it in full. Always the whole roster,
   * regardless of any filters.
   *
   * @param array|null $paidByMember
   *   getPaidAmountsByMember(), if already loaded.
   * @param array|null $feesByMember
   *   getFeeHistoryByMember(), if already loaded.
   */
  public function rosterSummary(?array $paidByMember = NULL, ?array $feesByMember = NULL): array {
    $paidByMember ??= $this->getPaidAmountsByMember();
    $feesByMember ??= $this->getFeeHistoryByMember();

    $currentMonth = date('Y-m');
    $membersOwing = 0;
    $outstanding = 0.0;
    $monthExpected = 0.0;
    $monthCollected = 0.0;
    $monthBilled = 0;
    $monthPaid = 0;
    foreach ($this->membersQuery()->execute() as $record) {
      $memberId = (int) $record->id;
      // Exact: each month at the fee in force back then, minus whatever was
      // already paid towards it.
      $ledger = $this->computeLedger($record, $paidByMember[$memberId] ?? [], $feesByMember[$memberId] ?? []);
      $owed = self::outstanding($ledger);
      if ($owed > 0) {
        $membersOwing++;
        $outstanding += $owed;
      }

      // Not in the ledger: not enrolled yet, already left, or a month the
      // class isn't charged for.
      if (isset($ledger[$currentMonth])) {
        $entry = $ledger[$currentMonth];
        $monthBilled++;
        $monthExpected += $entry['due'];
        $monthCollected += min($entry['paid'], $entry['due']);
        if ($entry['remaining'] <= 0) {
          $monthPaid++;
        }
      }
    }
    return $this->getStats() + [
      'members_owing' => $membersOwing,
      'outstanding' => $outstanding,
      'month_expected' => $monthExpected,
      'month_collected' => $monthCollected,
      'month_percent' => $monthExpected > 0 ? (int) min(100, round($monthCollected / $monthExpected * 100)) : 0,
      'month_billed' => $monthBilled,
      'month_paid' => $monthPaid,
    ];
  }

  /**
   * Roster-wide member counts for the dashboard stats bar. Unaffected by the
   * dashboard's own filters/pager — always reflects the whole roster.
   */
  public function getStats(): array {
    $activeMembers = (int) $this->database->select('gg_members_member', 'm')
      ->condition('status', 1)
      ->countQuery()
      ->execute()
      ->fetchField();

    $totalMembers = (int) $this->database->select('gg_members_member', 'm')
      ->countQuery()
      ->execute()
      ->fetchField();

    return [
      'active_members' => $activeMembers,
      'total_members' => $totalMembers,
    ];
  }

}
