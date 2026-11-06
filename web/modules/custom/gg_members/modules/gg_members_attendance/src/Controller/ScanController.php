<?php

namespace Drupal\gg_members_attendance\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Drupal\gg_members_attendance\Attendance;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The door: the scanner page, what it calls for each card, and the card's
 * own link (for when a card is scanned with a phone's camera app).
 */
class ScanController extends ControllerBase {

  public function __construct(protected Attendance $attendance) {}

  public static function create(ContainerInterface $container) {
    return new static($container->get('gg_members_attendance.attendance'));
  }

  /**
   * The link in a card's QR. On the door device (an account that may
   * record attendance) it records it and shows the same screen as the
   * scanner; on anyone else's phone it only says this is a club card —
   * nothing about the member.
   */
  public function checkin(string $token): Response {
    $club = $this->config('system.site')->get('name');
    if (!$this->canRecord()) {
      return $this->standalone([
        '#theme' => 'gg_members_attendance_checkin',
        '#club' => $club,
        '#result' => NULL,
      ]);
    }
    return $this->standalone([
      '#theme' => 'gg_members_attendance_checkin',
      '#club' => $club,
      '#result' => $this->attendance->scan($token),
      '#scanner_url' => Url::fromRoute('gg_members_attendance.scan')->toString(),
    ]);
  }

  /**
   * The scanner: the camera full screen, reading cards one after another.
   */
  public function scanner(): Response {
    return $this->standalone([
      '#theme' => 'gg_members_attendance_scanner',
      '#club' => $this->config('system.site')->get('name'),
      '#today' => $this->attendance->count(),
      '#record_url' => Url::fromRoute('gg_members_attendance.scan_record')->toString(),
      '#token_url' => Url::fromRoute('system.csrftoken')->toString(),
      '#jsqr_url' => base_path() . $this->moduleHandler()->getModule('gg_members_attendance')->getPath() . '/js/vendor/jsqr/jsQR.js',
      '#scanner_js_url' => base_path() . $this->moduleHandler()->getModule('gg_members_attendance')->getPath() . '/js/scanner.js?v=1',
      '#back_url' => $this->currentUser()->hasPermission('manage gg_members attendance') ? Url::fromRoute('gg_members_attendance.today')->toString() : NULL,
    ]);
  }

  /**
   * One scanned card, from the scanner: records it and says what to show.
   */
  public function record(Request $request): JsonResponse {
    $data = json_decode((string) $request->getContent(), TRUE);
    $code = is_array($data) && is_string($data['code'] ?? NULL) ? $data['code'] : '';
    $result = $this->attendance->scan(mb_substr($code, 0, 300));
    $result['today'] = $this->attendance->count();
    return new JsonResponse($result, 200, ['Cache-Control' => 'no-store']);
  }

  protected function canRecord(): bool {
    $account = $this->currentUser();
    return $account->hasPermission('record gg_members attendance') || $account->hasPermission('manage gg_members attendance');
  }

  /**
   * A page of its own, outside the site's theme.
   */
  protected function standalone(array $build): Response {
    return new Response((string) \Drupal::service('renderer')->renderInIsolation($build), 200, [
      'Content-Type' => 'text/html; charset=UTF-8',
      'Cache-Control' => 'no-store',
      'X-Robots-Tag' => 'noindex',
    ]);
  }

}
