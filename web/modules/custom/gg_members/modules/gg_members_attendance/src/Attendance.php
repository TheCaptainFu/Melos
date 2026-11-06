<?php

namespace Drupal\gg_members_attendance;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Image\ImageFactory;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\gg_members\MemberManager;

/**
 * Who came to training: one row per member per day, by QR at the door or
 * ticked by hand.
 */
class Attendance {

  public function __construct(
    protected Connection $database,
    protected MemberManager $memberManager,
    protected QrCards $cards,
    protected TimeInterface $time,
    protected AccountProxyInterface $currentUser,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected FileSystemInterface $fileSystem,
    protected ImageFactory $imageFactory,
  ) {}

  /**
   * What the door shows for a scanned card — and the attendance it records.
   *
   * @param string $scanned
   *   What the QR held: the card's link, or the bare code.
   *
   * @return array
   *   state: 'ok' (in), 'warn' (in, but the coach should have a word —
   *   no reason shown at the door), 'again' (already in today), 'inactive',
   *   'unknown'; and for a known member: name, class_name, photo (a small
   *   data: URI, or NULL), time ('H:i').
   */
  public function scan(string $scanned): array {
    $token = QrCards::tokenFromScan($scanned);
    $memberId = $token ? $this->cards->memberIdByToken($token) : NULL;
    $member = $memberId ? $this->memberManager->getMember($memberId) : NULL;
    if (!$member) {
      return ['state' => 'unknown'];
    }

    $result = [
      'name' => $member->name,
      'class_name' => $this->className((int) $member->class_tid),
      'photo' => $this->photo($memberId),
    ];
    if (!$member->status) {
      return ['state' => 'inactive'] + $result;
    }

    $recorded = $this->record($memberId, 'qr');
    $result['time'] = date('H:i', $recorded['time']);
    if (!$recorded['new']) {
      return ['state' => 'again'] + $result;
    }
    return ['state' => $this->issues($memberId) ? 'warn' : 'ok'] + $result;
  }

  /**
   * Records that a member came on a day (today by default); a second time
   * the same day changes nothing.
   *
   * @return array
   *   new (whether this added the row), time (when they were first marked
   *   that day), id.
   */
  public function record(int $memberId, string $method, ?string $date = NULL): array {
    $date = $date ?: date('Y-m-d', $this->time->getRequestTime());
    $existing = $this->database->query('SELECT id, created FROM {gg_members_attendance} WHERE member_id = :id AND attended = :date', [':id' => $memberId, ':date' => $date])->fetchAssoc();
    if ($existing) {
      return ['new' => FALSE, 'time' => (int) $existing['created'], 'id' => (int) $existing['id']];
    }

    $member = $this->memberManager->getMember($memberId);
    $now = $this->time->getRequestTime();
    $id = (int) $this->database->insert('gg_members_attendance')
      ->fields([
        'member_id' => $memberId,
        'attended' => $date,
        'created' => $now,
        'class_tid' => $member ? (int) $member->class_tid : 0,
        'method' => $method,
        'uid' => (int) $this->currentUser->id(),
      ])
      ->execute();
    return ['new' => TRUE, 'time' => $now, 'id' => $id];
  }

  /**
   * How many came on a day (today by default).
   */
  public function count(?string $date = NULL): int {
    $date = $date ?: date('Y-m-d', $this->time->getRequestTime());
    return (int) $this->database->query('SELECT COUNT(*) FROM {gg_members_attendance} WHERE attended = :date', [':date' => $date])->fetchField();
  }

  public function delete(int $id): void {
    $this->database->delete('gg_members_attendance')->condition('id', $id)->execute();
  }

  /**
   * Everyone who came on a day, latest first.
   *
   * @return array
   *   Rows: id, member_id, name, class_name, time ('H:i'), method, issues
   *   (see issues()).
   */
  public function day(string $date): array {
    $rows = $this->database->query(
      'SELECT a.id, a.member_id, a.created, a.method, m.name, t.name AS class_name
       FROM {gg_members_attendance} a
       INNER JOIN {gg_members_member} m ON m.id = a.member_id
       LEFT JOIN {taxonomy_term_field_data} t ON t.tid = a.class_tid
       WHERE a.attended = :date
       ORDER BY a.created DESC, a.id DESC',
      [':date' => $date]
    )->fetchAll();

    return array_map(fn($row) => [
      'id' => (int) $row->id,
      'member_id' => (int) $row->member_id,
      'name' => $row->name,
      'class_name' => $row->class_name,
      'time' => date('H:i', (int) $row->created),
      'method' => $row->method,
      'issues' => $this->issues((int) $row->member_id),
    ], $rows);
  }

  /**
   * What the coach should know about a member at the door: months left
   * unpaid, a missing doctor's note. Never shown on the door screen itself.
   *
   * The month under way doesn't count: early in the month nearly everyone
   * hasn't paid it yet.
   *
   * @return string[]
   */
  public function issues(int $memberId): array {
    $issues = [];
    $thisMonth = date('Y-m', $this->time->getRequestTime());
    $unpaid = count(array_filter($this->memberManager->getUnpaidMonths($memberId), fn($month) => $month < $thisMonth));
    if ($unpaid) {
      $issues[] = (string) \Drupal::translation()->formatPlural($unpaid, 'Χρωστάει 1 μήνα', 'Χρωστάει @count μήνες');
    }
    if (!$this->memberManager->hasFiles($memberId, 'doctor_note')) {
      $issues[] = (string) t('Λείπει χαρτί γιατρού');
    }
    return $issues;
  }

  /**
   * The member's photo, small, as a data: URI — photos are private files,
   * which the door's account can't open.
   */
  protected function photo(int $memberId): ?string {
    $rows = $this->memberManager->getMemberFiles($memberId, 'photo');
    $row = end($rows);
    $file = $row ? $this->entityTypeManager->getStorage('file')->load((int) $row->fid) : NULL;
    if (!$file) {
      return NULL;
    }
    $directory = 'temporary://gg_members_attendance';
    $copy = $directory . '/photo-' . $file->id() . '-' . (int) $file->getChangedTime() . '.jpg';
    if (!file_exists($copy)) {
      $image = $this->imageFactory->get($file->getFileUri());
      if (!$image->isValid()) {
        return NULL;
      }
      $this->fileSystem->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY);
      $image->scaleAndCrop(240, 240);
      $image->convert('jpeg');
      if (!$image->save($copy)) {
        return NULL;
      }
    }
    return 'data:image/jpeg;base64,' . base64_encode(file_get_contents($copy));
  }

  protected function className(int $tid): ?string {
    $term = $tid ? $this->entityTypeManager->getStorage('taxonomy_term')->load($tid) : NULL;
    return $term ? $term->label() : NULL;
  }

}
