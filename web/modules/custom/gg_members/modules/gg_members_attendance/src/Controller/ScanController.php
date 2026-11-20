<?php

namespace Drupal\gg_members_attendance\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Drupal\gg_members_attendance\Attendance;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

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
   * The door account's home page: a big "open the scanner" button, who
   * came today, and logging out. Not logged in: the login, then back here.
   */
  public function door(): Response {
    if ($this->currentUser()->isAnonymous()) {
      return new RedirectResponse(Url::fromRoute('user.login', [], ['query' => ['destination' => Url::fromRoute('gg_members_attendance.door')->toString()]])->toString());
    }
    if (!$this->canRecord()) {
      throw new AccessDeniedHttpException();
    }
    $today = date('Y-m-d', \Drupal::time()->getRequestTime());
    $recent = array_map(fn($row) => [
      'name' => $row['name'],
      'time' => $row['time'],
    ], array_slice($this->attendance->day($today), 0, 5));
    // The logout link's CSRF token is a placeholder until rendered: carry
    // it along so the page gets the real one.
    $logout = Url::fromRoute('user.logout')->toString(TRUE);
    $build = [
      '#theme' => 'gg_members_attendance_door',
      '#club' => $this->config('system.site')->get('name'),
      '#today' => $this->attendance->count(),
      '#recent' => $recent,
      '#scanner_url' => Url::fromRoute('gg_members_attendance.scan')->toString(),
      '#logout_url' => $logout->getGeneratedUrl(),
      '#address' => preg_replace('#^https?://#', '', Url::fromRoute('gg_members_attendance.door', [], ['absolute' => TRUE])->toString()),
      '#manage_url' => $this->currentUser()->hasPermission('manage gg_members attendance') ? Url::fromRoute('gg_members_attendance.today')->toString() : NULL,
    ];
    $build += $this->brand();
    $logout->applyTo($build);
    return $this->standalone($build);
  }

  /**
   * The scanner: the camera full screen, reading cards one after another.
   */
  public function scanner(): Response {
    return $this->standalone($this->brand() + [
      '#theme' => 'gg_members_attendance_scanner',
      '#club' => $this->config('system.site')->get('name'),
      '#today' => $this->attendance->count(),
      '#record_url' => Url::fromRoute('gg_members_attendance.scan_record')->toString(),
      '#token_url' => Url::fromRoute('system.csrftoken')->toString(),
      '#jsqr_url' => base_path() . $this->moduleHandler()->getModule('gg_members_attendance')->getPath() . '/js/vendor/jsqr/jsQR.js',
      '#scanner_js_url' => base_path() . $this->moduleHandler()->getModule('gg_members_attendance')->getPath() . '/js/scanner.js?v=2',
      // The coach back to the Παρουσιολόγιο tab, the door account to its
      // home page.
      '#back_url' => $this->currentUser()->hasPermission('manage gg_members attendance')
        ? Url::fromRoute('gg_members_attendance.today')->toString()
        : Url::fromRoute('gg_members_attendance.door')->toString(),
      '#back_label' => $this->currentUser()->hasPermission('manage gg_members attendance') ? $this->t('Έξοδος') : $this->t('← Αρχική'),
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

  /**
   * The club's logo (from «Μόνο πρόγραμμα», if it's on) and today's date
   * in Greek, for the door's pages.
   */
  protected function brand(): array {
    $logo = NULL;
    if ($this->moduleHandler()->moduleExists('gg_members_app') && function_exists('gg_members_app_logo_url')) {
      $logo = gg_members_app_logo_url();
    }
    $days = ['Κυριακή', 'Δευτέρα', 'Τρίτη', 'Τετάρτη', 'Πέμπτη', 'Παρασκευή', 'Σάββατο'];
    $months = [1 => 'Ιανουαρίου', 'Φεβρουαρίου', 'Μαρτίου', 'Απριλίου', 'Μαΐου', 'Ιουνίου', 'Ιουλίου', 'Αυγούστου', 'Σεπτεμβρίου', 'Οκτωβρίου', 'Νοεμβρίου', 'Δεκεμβρίου'];
    $now = \Drupal::time()->getRequestTime();
    $club = (string) $this->config('system.site')->get('name');
    return [
      '#logo' => $logo,
      '#initial' => mb_strtoupper(mb_substr($club, 0, 1)),
      '#date' => $days[(int) date('w', $now)] . ' ' . date('j', $now) . ' ' . $months[(int) date('n', $now)],
    ];
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
