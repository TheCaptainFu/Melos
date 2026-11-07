<?php

namespace Drupal\gg_members_attendance;

use chillerlan\QRCode\Output\QROutputInterface;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Render\RendererInterface;
use Drupal\Core\Site\Settings;
use Drupal\Core\Url;
use Drupal\gg_members\MemberManager;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * Members' QR cards: the random code behind each one, the QR image, and the
 * email that sends it.
 *
 * The code is random and stands for nothing (not the member's id or name):
 * it can't be guessed, gives nothing away, and a new one makes a lost card
 * stop working.
 */
class QrCards {

  public function __construct(
    protected Connection $database,
    protected ConfigFactoryInterface $configFactory,
    protected MemberManager $memberManager,
    protected TimeInterface $time,
    protected RendererInterface $renderer,
    protected LoggerChannelFactoryInterface $loggerFactory,
  ) {}

  /**
   * A member's code, made the first time it's asked for.
   */
  public function token(int $memberId): string {
    $token = $this->database->query('SELECT token FROM {gg_members_qr} WHERE member_id = :id', [':id' => $memberId])->fetchField();
    return $token ?: $this->renew($memberId);
  }

  /**
   * A new code for a member; the old card stops working.
   */
  public function renew(int $memberId): string {
    $token = self::newToken();
    $this->database->merge('gg_members_qr')
      ->key('member_id', $memberId)
      ->fields(['token' => $token, 'created' => $this->time->getRequestTime(), 'sent' => NULL, 'sent_to' => NULL])
      ->execute();
    return $token;
  }

  /**
   * Cards for every member that hasn't got one yet.
   *
   * @return int
   *   How many were made.
   */
  public function ensureAll(): int {
    $ids = $this->database->query('SELECT m.id FROM {gg_members_member} m LEFT JOIN {gg_members_qr} q ON q.member_id = m.id WHERE q.member_id IS NULL')->fetchCol();
    foreach ($ids as $id) {
      $this->renew((int) $id);
    }
    return count($ids);
  }

  /**
   * The member a scanned code belongs to, or NULL for an unknown (or
   * replaced) card.
   */
  public function memberIdByToken(string $token): ?int {
    $id = $this->database->query('SELECT member_id FROM {gg_members_qr} WHERE token = :token', [':token' => $token])->fetchField();
    return $id ? (int) $id : NULL;
  }

  /**
   * When and where a member's card was last emailed: ['sent' => timestamp,
   * 'sent_to' => address], or NULL if it never was.
   */
  public function sentInfo(int $memberId): ?array {
    $row = $this->database->query('SELECT sent, sent_to FROM {gg_members_qr} WHERE member_id = :id', [':id' => $memberId])->fetchAssoc();
    return $row && $row['sent'] ? ['sent' => (int) $row['sent'], 'sent_to' => $row['sent_to']] : NULL;
  }

  /**
   * The card's QR as a PNG — an image, so it shows in emails too (they run
   * no JavaScript and most show no SVG). It holds the card's check-in link
   * (see checkinUrl()).
   */
  public function png(string $token): string {
    $options = new QROptions([
      'outputType' => QROutputInterface::GDIMAGE_PNG,
      'outputBase64' => FALSE,
      'scale' => 12,
      'quietzoneSize' => 2,
    ]);
    return (new QRCode($options))->render($this->checkinUrl($token));
  }

  /**
   * The link in the QR: on the club's door device it records the member's
   * attendance; on anyone else's phone it shows only that this is a club
   * card (CheckinController::checkin()).
   */
  public function checkinUrl(string $token): string {
    return Url::fromRoute('gg_members_attendance.checkin', ['token' => $token], ['absolute' => TRUE])->toString();
  }

  /**
   * The code in what a scanner read: the card's link, or a bare code.
   */
  public static function tokenFromScan(string $scanned): ?string {
    $scanned = trim($scanned);
    if (preg_match('#/checkin/([A-Za-z0-9_-]{20,64})/?$#', $scanned, $match)) {
      return $match[1];
    }
    return preg_match('/^[A-Za-z0-9_-]{20,64}$/', $scanned) ? $scanned : NULL;
  }

  /**
   * The card's own page (the QR, big, for showing at the door), absolute so
   * it works from an email.
   */
  public function cardUrl(string $token): string {
    return Url::fromRoute('gg_members_attendance.card', ['token' => $token], ['absolute' => TRUE])->toString();
  }

  /**
   * Emails a member their card: the QR in the message and attached, and a
   * link to the card's page.
   *
   * Sent the way the site sends its other mail (see transportDsn()), but
   * built here because Drupal's mailer sends plain text only.
   *
   * @return string|null
   *   NULL once sent, or why it wasn't (no email address, mail error).
   */
  public function send(int $memberId): ?string {
    $member = $this->memberManager->getMember($memberId);
    if (!$member) {
      return (string) t('Το μέλος δεν βρέθηκε.');
    }
    $to = trim((string) $member->email);
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
      return (string) t('Το μέλος δεν έχει (έγκυρο) email.');
    }

    $token = $this->token($memberId);
    $site = $this->configFactory->get('system.site');
    $club = (string) $site->get('name');
    $firstName = self::firstName((string) $member->name);
    $cardUrl = $this->cardUrl($token);

    $html = [
      '#theme' => 'gg_members_attendance_email',
      '#name' => (string) $member->name,
      '#first_name' => $firstName,
      '#club' => $club,
      '#card_url' => $cardUrl,
      '#for_guardian' => (bool) trim((string) $member->guardian_name),
    ];
    $subject = (string) t('Η κάρτα παρουσίας του/της @name — @club', ['@name' => $firstName, '@club' => $club]);
    // On a test site every card goes to one address instead (settings.php:
    // $settings['gg_members_mail_reroute'] = 'me@example.com'), the real
    // recipient named in the subject.
    $reroute = (string) Settings::get('gg_members_mail_reroute', '');
    if ($reroute !== '') {
      $subject = '[' . (string) t('ΔΟΚΙΜΗ — για @to', ['@to' => $to]) . '] ' . $subject;
    }
    $email = (new Email())
      ->from($this->sender())
      ->to($reroute !== '' ? $reroute : $to)
      ->subject($subject)
      ->html((string) $this->renderer->renderInIsolation($html))
      ->text((string) t("Γεια σας,\n\nαυτή είναι η κάρτα παρουσίας του/της @name στο @club. Δείξτε τον κωδικό QR στην είσοδο σε κάθε προπόνηση — από το κινητό (κρατήστε ένα screenshot) ή τυπωμένο.\n\nΗ κάρτα: @url\n", ['@name' => $firstName, '@club' => $club, '@url' => $cardUrl]))
      ->embed($this->png($token), 'qr', 'image/png')
      ->attach($this->png($token), 'qr-' . self::slug($firstName) . '.png', 'image/png');

    try {
      Transport::fromDsn($this->transportDsn())->send($email);
    }
    catch (\Throwable $e) {
      $this->loggerFactory->get('gg_members_attendance')->error('QR card for member @id not sent: @message', ['@id' => $memberId, '@message' => $e->getMessage()]);
      return (string) t('Το email δεν στάλθηκε: @message', ['@message' => $e->getMessage()]);
    }

    $this->database->update('gg_members_qr')
      ->fields(['sent' => $this->time->getRequestTime(), 'sent_to' => $to])
      ->condition('member_id', $memberId)
      ->execute();
    $this->loggerFactory->get('gg_members_attendance')->info('QR card for member @id sent to @to.', ['@id' => $memberId, '@to' => $to]);
    return NULL;
  }

  /**
   * The first word of a name, as a greeting uses it.
   */
  public static function firstName(string $name): string {
    $parts = preg_split('/\s+/u', trim($name));
    return $parts[0] ?? $name;
  }

  /**
   * Who the email is from: the SMTP module's sender when it's on (the
   * mailbox the host lets it send as), otherwise the site's email and name.
   */
  protected function sender(): Address {
    $smtp = $this->configFactory->get('smtp.settings');
    $site = $this->configFactory->get('system.site');
    if (\Drupal::moduleHandler()->moduleExists('smtp') && $smtp->get('smtp_on') && $smtp->get('smtp_from')) {
      return new Address((string) $smtp->get('smtp_from'), (string) ($smtp->get('smtp_fromname') ?: $site->get('name')));
    }
    return new Address((string) $site->get('mail'), (string) $site->get('name'));
  }

  /**
   * How the site sends mail, as a Symfony mailer DSN:
   * - the SMTP module's server, when it's on (e.g. the host's mailbox);
   * - system.mail:mailer_dsn, when the site uses Drupal's Symfony mailer;
   * - otherwise PHP's own sendmail_path, the same way Drupal's default
   *   php_mail sends (Mailpit under ddev).
   */
  protected function transportDsn(): string {
    $smtp = $this->configFactory->get('smtp.settings');
    if (\Drupal::moduleHandler()->moduleExists('smtp') && $smtp->get('smtp_on')) {
      $scheme = $smtp->get('smtp_protocol') === 'ssl' ? 'smtps' : 'smtp';
      $auth = $smtp->get('smtp_username')
        ? rawurlencode((string) $smtp->get('smtp_username')) . ':' . rawurlencode((string) $smtp->get('smtp_password')) . '@'
        : '';
      return $scheme . '://' . $auth . $smtp->get('smtp_host') . ':' . ($smtp->get('smtp_port') ?: ($scheme === 'smtps' ? 465 : 587));
    }
    $mail = $this->configFactory->get('system.mail');
    if (($mail->get('interface.default') === 'symfony_mailer') && $mail->get('mailer_dsn')) {
      return self::dsnString($mail->get('mailer_dsn'));
    }
    return 'native://default';
  }

  /**
   * system.mail:mailer_dsn (scheme, host, user, password, port, options) as
   * the "scheme://user:pass@host:port?options" string Symfony wants.
   */
  protected static function dsnString(array $dsn): string {
    $auth = '';
    if (!empty($dsn['user'])) {
      $auth = rawurlencode($dsn['user']) . (isset($dsn['password']) ? ':' . rawurlencode($dsn['password']) : '') . '@';
    }
    $port = !empty($dsn['port']) ? ':' . $dsn['port'] : '';
    $query = !empty($dsn['options']) ? '?' . http_build_query($dsn['options']) : '';
    return $dsn['scheme'] . '://' . $auth . ($dsn['host'] ?? 'default') . $port . $query;
  }

  /**
   * A token: 24 random bytes, base64url (32 characters).
   */
  protected static function newToken(): string {
    return rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '=');
  }

  /**
   * Latin letters/digits for a file name.
   */
  protected static function slug(string $text): string {
    $latin = \Drupal::transliteration()->transliterate($text, 'el');
    return trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($latin)), '-') ?: 'card';
  }

}
