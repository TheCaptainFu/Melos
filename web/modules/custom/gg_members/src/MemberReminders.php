<?php

namespace Drupal\gg_members;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Session\AccountInterface;

/**
 * Payment reminders: who to send one to, the ready-made message, and a log
 * of when each was sent (so the roster can show it and nobody gets two).
 *
 * Sending itself happens on the secretary's own phone or computer — the
 * reminder dialog opens Viber/SMS/WhatsApp with the message filled in.
 */
class MemberReminders {

  /**
   * The ways a reminder can go out: key => label.
   */
  public const CHANNELS = [
    'viber' => 'Viber',
    'sms' => 'SMS',
    'whatsapp' => 'WhatsApp',
    'copy' => 'Αντιγραφή',
  ];

  /**
   * The message used until the settings page says otherwise.
   */
  public const DEFAULT_TEMPLATE = 'Καλησπέρα σας! Σας υπενθυμίζουμε ότι εκκρεμεί η συνδρομή του μέλους [όνομα] για [μήνες] (σύνολο [ποσό]). Ευχαριστούμε! — [σύλλογος]';

  public function __construct(
    protected Connection $database,
    protected ConfigFactoryInterface $configFactory,
    protected MemberManager $memberManager,
    protected TimeInterface $time,
    protected AccountInterface $currentUser,
  ) {}

  /**
   * Everything the reminder dialog needs for one member.
   *
   * @return array|null
   *   NULL if the member doesn't exist, otherwise: name, recipient (who gets
   *   it: the guardian for a member with one, otherwise the member),
   *   is_guardian, phone (as stored), international (for Viber/WhatsApp
   *   links, e.g. +306912345678, or NULL without a phone), message,
   *   outstanding, months (count), last (see lastReminder()).
   */
  public function build(int $memberId): ?array {
    $member = $this->memberManager->getMember($memberId);
    if (!$member) {
      return NULL;
    }
    $open = MemberManager::openMonths($this->memberManager->getLedger($memberId));
    $toGuardian = (bool) $member->guardian_phone;
    $phone = $toGuardian ? $member->guardian_phone : $member->phone;

    return [
      'name' => $member->name,
      'recipient' => $toGuardian ? ($member->guardian_name ?: $member->name) : $member->name,
      'is_guardian' => $toGuardian,
      'phone' => $phone,
      'international' => self::internationalPhone($phone),
      'message' => $this->message($member->name, array_keys($open), MemberManager::outstanding($open)),
      'outstanding' => MemberManager::outstanding($open),
      'months' => count($open),
      'last' => $this->lastReminder($memberId),
    ];
  }

  /**
   * Fills in the settings page's message template.
   *
   * @param string[] $months
   *   Unpaid 'YYYY-MM' months, oldest first.
   */
  public function message(string $name, array $months, float $amount): string {
    $settings = $this->configFactory->get('gg_members.settings');
    $template = $settings->get('reminder_template') ?: self::DEFAULT_TEMPLATE;
    $club = $settings->get('club_name') ?: $this->configFactory->get('system.site')->get('name');

    return strtr($template, [
      '[όνομα]' => $name,
      '[μήνες]' => self::monthsAccusative($months),
      '[ποσό]' => MemberManager::formatMoney($amount) . '€',
      '[σύλλογος]' => (string) $club,
    ]);
  }

  /**
   * Records that a reminder went out.
   */
  public function log(int $memberId, string $channel): void {
    $this->database->insert('gg_members_reminder')
      ->fields([
        'member_id' => $memberId,
        'channel' => $channel,
        'uid' => (int) $this->currentUser->id(),
        'created' => $this->time->getRequestTime(),
      ])
      ->execute();
  }

  /**
   * The latest reminder sent to one member: ['created' => timestamp,
   * 'channel' => key], or NULL if none.
   */
  public function lastReminder(int $memberId): ?array {
    $row = $this->database->select('gg_members_reminder', 'r')
      ->fields('r', ['created', 'channel'])
      ->condition('member_id', $memberId)
      ->orderBy('created', 'DESC')
      ->orderBy('id', 'DESC')
      ->range(0, 1)
      ->execute()
      ->fetchAssoc();
    return $row ? ['created' => (int) $row['created'], 'channel' => $row['channel']] : NULL;
  }

  /**
   * Every member's latest reminder time in one query: member id =>
   * timestamp.
   */
  public function lastRemindersByMember(): array {
    $query = $this->database->select('gg_members_reminder', 'r');
    $query->addField('r', 'member_id');
    $query->addExpression('MAX(r.created)', 'last');
    $query->groupBy('r.member_id');
    return array_map('intval', $query->execute()->fetchAllKeyed());
  }

  /**
   * A phone number in international form for Viber/WhatsApp links: Greek
   * numbers typed without a country code (69…, 2…) get +30; 00… becomes +…;
   * anything else is kept as typed (digits and a leading + only).
   */
  public static function internationalPhone(?string $phone): ?string {
    $number = preg_replace('/[^\d+]/', '', (string) $phone);
    if ($number === '' || $number === '+') {
      return NULL;
    }
    if (str_starts_with($number, '+')) {
      return $number;
    }
    if (str_starts_with($number, '00')) {
      return '+' . substr($number, 2);
    }
    if (preg_match('/^(69|2)\d{8}$/', $number) && strlen($number) === 10) {
      return '+30' . $number;
    }
    if (preg_match('/^30\d{10}$/', $number)) {
      return '+' . $number;
    }
    return $number;
  }

  /**
   * Months the way they'd be said in the message: "Σεπτέμβριο και Οκτώβριο
   * 2026", "Δεκέμβριο 2025 και Ιανουάριο 2026" — accusative, the year once
   * per run of months in the same year.
   *
   * @param string[] $months
   *   'YYYY-MM', oldest first.
   */
  public static function monthsAccusative(array $months): string {
    $byYear = [];
    foreach ($months as $month) {
      [$year, $number] = explode('-', $month);
      $byYear[$year][] = mb_substr(MemberManager::MONTH_NAMES[(int) $number], 0, -1);
    }
    $groups = [];
    foreach ($byYear as $year => $names) {
      $groups[] = self::joinAnd($names) . ' ' . $year;
    }
    return self::joinAnd($groups);
  }

  /**
   * "α", "α και β", "α, β και γ".
   */
  protected static function joinAnd(array $items): string {
    $last = array_pop($items);
    return $items ? implode(', ', $items) . ' και ' . $last : (string) $last;
  }

}
