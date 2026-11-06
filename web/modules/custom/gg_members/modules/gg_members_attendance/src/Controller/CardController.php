<?php

namespace Drupal\gg_members_attendance\Controller;

use Drupal\Core\Batch\BatchBuilder;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Drupal\gg_members\MemberManager;
use Drupal\gg_members_attendance\QrCards;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Members' QR cards: the card's own page and image (public, by code), the
 * list of everyone's cards, printing them, emailing them, renewing one.
 */
class CardController extends ControllerBase {

  public function __construct(
    protected QrCards $cards,
    protected MemberManager $memberManager,
  ) {}

  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('gg_members_attendance.cards'),
      $container->get('gg_members.manager'),
    );
  }

  /**
   * The card as its holder opens it from the email: the QR, big, with the
   * member's name and class — nothing else about them.
   */
  public function page(string $token): Response {
    $member = $this->memberByToken($token);
    $build = [
      '#theme' => 'gg_members_attendance_card_page',
      '#name' => $member->name,
      '#class_name' => $this->className((int) $member->class_tid),
      '#club' => $this->config('system.site')->get('name'),
      '#qr_url' => Url::fromRoute('gg_members_attendance.card_qr', ['token' => $token])->toString(),
    ];
    return $this->standalone($build);
  }

  public function image(string $token): Response {
    $this->memberByToken($token);
    return new Response($this->cards->png($token), 200, [
      'Content-Type' => 'image/png',
      'Cache-Control' => 'private, max-age=3600',
      'X-Robots-Tag' => 'noindex',
    ]);
  }

  /**
   * The "Παρουσιολόγιο" tab: every member's card — whether it's been
   * emailed, and buttons to email, open or print it.
   */
  public function list(Request $request): array {
    $this->cards->ensureAll();
    $classes = $this->memberManager->getClasses();
    $classTid = (int) $request->query->get('class');
    if (!isset($classes[$classTid])) {
      $classTid = 0;
    }

    $query = $this->memberManager->membersQuery(array_filter(['class_tid' => $classTid, 'status' => 1]));
    $back = $this->currentUrl();
    $rows = [];
    $unsent = 0;
    foreach ($query->execute() as $member) {
      $id = (int) $member->id;
      $sent = $this->cards->sentInfo($id);
      $hasEmail = (bool) filter_var(trim((string) $member->email), FILTER_VALIDATE_EMAIL);
      if ($hasEmail && !$sent) {
        $unsent++;
      }
      $rows[] = [
        'name' => $member->name,
        'class_name' => $member->class_name,
        'email' => $member->email,
        'has_email' => $hasEmail,
        'sent' => $sent ? date('d/m/Y', $sent['sent']) : NULL,
        'profile_url' => Url::fromRoute('gg_members.member_view', ['member' => $id])->toString(),
        'card_url' => $this->cards->cardUrl($this->cards->token($id)),
        'send_url' => $this->actionUrl('gg_members_attendance.send', $id, $back),
      ];
    }

    return [
      '#theme' => 'gg_members_attendance_cards',
      '#rows' => $rows,
      '#classes' => array_map(fn($term) => $term->label(), $classes),
      '#class_tid' => $classTid,
      '#unsent' => $unsent,
      '#print_url' => Url::fromRoute('gg_members_attendance.print', [], ['query' => array_filter(['class' => $classTid])])->toString(),
      '#send_all_url' => Url::fromRoute('gg_members_attendance.send_all', [], ['query' => array_filter(['class' => $classTid])])->toString(),
      '#attached' => ['library' => ['gg_members/admin', 'gg_members/filter_autosubmit', 'gg_members_attendance/admin']],
      '#cache' => ['max-age' => 0],
    ];
  }

  /**
   * Cards to print and cut out: eight to an A4 page.
   */
  public function printCards(Request $request): Response {
    $this->cards->ensureAll();
    $classes = $this->memberManager->getClasses();
    $classTid = (int) $request->query->get('class');
    $query = $this->memberManager->membersQuery(array_filter(['class_tid' => isset($classes[$classTid]) ? $classTid : 0, 'status' => 1]));
    $cards = [];
    foreach ($query->execute() as $member) {
      $cards[] = [
        'name' => $member->name,
        'class_name' => $member->class_name,
        'qr_url' => Url::fromRoute('gg_members_attendance.card_qr', ['token' => $this->cards->token((int) $member->id)])->toString(),
      ];
    }
    return $this->standalone([
      '#theme' => 'gg_members_attendance_print',
      '#cards' => $cards,
      '#club' => $this->config('system.site')->get('name'),
    ]);
  }

  public function send(int $member, Request $request): RedirectResponse {
    $error = $this->cards->send($member);
    if ($error) {
      $this->messenger()->addError($error);
    }
    else {
      $this->messenger()->addStatus($this->t('Η κάρτα στάλθηκε με email.'));
    }
    return $this->back($request, $member);
  }

  public function renew(int $member, Request $request): RedirectResponse {
    if (!$this->memberManager->getMember($member)) {
      throw new NotFoundHttpException();
    }
    $this->cards->renew($member);
    $this->messenger()->addStatus($this->t('Φτιάχτηκε νέο QR. Η παλιά κάρτα δεν ισχύει πια — στείλτε ή τυπώστε τη νέα.'));
    return $this->back($request, $member);
  }

  /**
   * Emails every card not sent yet (to members with an email), a few at a
   * time with a progress bar.
   */
  public function sendAll(Request $request) {
    $classes = $this->memberManager->getClasses();
    $classTid = (int) $request->query->get('class');
    $query = $this->memberManager->membersQuery(array_filter(['class_tid' => isset($classes[$classTid]) ? $classTid : 0, 'status' => 1]));
    $ids = [];
    foreach ($query->execute() as $member) {
      if (filter_var(trim((string) $member->email), FILTER_VALIDATE_EMAIL) && !$this->cards->sentInfo((int) $member->id)) {
        $ids[] = (int) $member->id;
      }
    }

    $batch = (new BatchBuilder())
      ->setTitle($this->t('Αποστολή καρτών QR'))
      ->setInitMessage($this->t('Ξεκινάει η αποστολή…'))
      ->setProgressMessage($this->t('Στάλθηκαν @current από @total.'))
      ->setFinishCallback([static::class, 'sendAllFinished']);
    foreach ($ids as $id) {
      $batch->addOperation([static::class, 'sendOne'], [$id]);
    }
    batch_set($batch->toArray());
    return batch_process(Url::fromRoute('gg_members_attendance.cards', [], ['query' => array_filter(['class' => $classTid])]));
  }

  /**
   * Batch operation: one member's card.
   */
  public static function sendOne(int $memberId, &$context): void {
    $error = \Drupal::service('gg_members_attendance.cards')->send($memberId);
    $context['results'][$error ? 'failed' : 'sent'][] = $memberId;
    if ($error) {
      $context['results']['errors'][] = $error;
    }
  }

  public static function sendAllFinished(bool $success, array $results): void {
    $messenger = \Drupal::messenger();
    $sent = count($results['sent'] ?? []);
    $failed = count($results['failed'] ?? []);
    $messenger->addStatus(\Drupal::translation()->formatPlural($sent, 'Στάλθηκε 1 κάρτα.', 'Στάλθηκαν @count κάρτες.'));
    if ($failed) {
      $messenger->addError(\Drupal::translation()->formatPlural($failed, '1 δεν στάλθηκε: @error', '@count δεν στάλθηκαν, π.χ.: @error', ['@error' => $results['errors'][0] ?? '']));
    }
  }

  protected function memberByToken(string $token): \stdClass {
    $id = $this->cards->memberIdByToken($token);
    $member = $id ? $this->memberManager->getMember($id) : NULL;
    if (!$member) {
      throw new NotFoundHttpException();
    }
    return $member;
  }

  protected function className(int $tid): ?string {
    $classes = $this->memberManager->getClasses();
    return isset($classes[$tid]) ? $classes[$tid]->label() : NULL;
  }

  /**
   * A link to one of the member actions, with its CSRF token and the page
   * to come back to.
   */
  protected function actionUrl(string $route, int $memberId, string $back): string {
    $url = Url::fromRoute($route, ['member' => $memberId], ['query' => ['destination' => $back]]);
    $token = \Drupal::csrfToken()->get($url->getInternalPath());
    $url->setOption('query', ['destination' => $back, 'token' => $token]);
    return $url->toString();
  }

  protected function currentUrl(): string {
    return Url::fromRoute('<current>', [], ['query' => \Drupal::request()->query->all()])->toString();
  }

  protected function back(Request $request, int $memberId): RedirectResponse {
    $destination = $request->query->get('destination');
    $url = is_string($destination) && str_starts_with($destination, '/')
      ? $request->getSchemeAndHttpHost() . $destination
      : Url::fromRoute('gg_members.member_view', ['member' => $memberId], ['absolute' => TRUE])->toString();
    return new RedirectResponse($url);
  }

  /**
   * A page of its own, outside the site's theme (the card, the printout).
   */
  protected function standalone(array $build): Response {
    return new Response((string) \Drupal::service('renderer')->renderInIsolation($build), 200, [
      'Content-Type' => 'text/html; charset=UTF-8',
      'X-Robots-Tag' => 'noindex',
    ]);
  }

}
