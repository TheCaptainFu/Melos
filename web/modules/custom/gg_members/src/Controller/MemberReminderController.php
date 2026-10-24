<?php

namespace Drupal\gg_members\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Drupal\gg_members\MemberManager;
use Drupal\gg_members\MemberReminders;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The reminder dialog (opened over the roster, or as a page on its own),
 * and the small endpoint its buttons call to log a sent reminder.
 */
class MemberReminderController extends ControllerBase {

  public function __construct(protected MemberReminders $reminders) {}

  public static function create(ContainerInterface $container) {
    return new static($container->get('gg_members.reminders'));
  }

  public function title(int $member): string {
    $reminder = $this->reminders->build($member);
    return $reminder ? (string) $this->t('Υπενθύμιση: @name', ['@name' => $reminder['name']]) : (string) $this->t('Υπενθύμιση');
  }

  public function dialog(int $member): array {
    $reminder = $this->reminders->build($member);
    if (!$reminder) {
      throw new NotFoundHttpException();
    }

    $logUrls = [];
    foreach (array_keys(MemberReminders::CHANNELS) as $channel) {
      $logUrls[$channel] = Url::fromRoute('gg_members.reminder_log', ['member' => $member, 'channel' => $channel])->toString();
    }

    return [
      '#theme' => 'gg_members_reminder',
      '#reminder' => $reminder + [
        'last_label' => $reminder['last'] ? $this->t('@channel, @date', [
          '@channel' => MemberReminders::CHANNELS[$reminder['last']['channel']] ?? $reminder['last']['channel'],
          '@date' => date('d/m/Y H:i', $reminder['last']['created']),
        ]) : NULL,
      ],
      '#urls' => [
        'edit' => MemberManager::withBack(Url::fromRoute('gg_members.member_edit', ['member' => $member]), MemberManager::backFromRequest()),
        'log' => $logUrls,
      ],
      '#attached' => ['library' => ['gg_members/admin', 'gg_members/reminder']],
      '#cache' => ['max-age' => 0],
    ];
  }

  /**
   * Logs a sent reminder; called by the dialog's buttons right before they
   * hand over to Viber/SMS/WhatsApp. CSRF-protected by the route.
   */
  public function log(int $member, string $channel): JsonResponse {
    if (!isset(MemberReminders::CHANNELS[$channel]) || !$this->reminders->build($member)) {
      throw new NotFoundHttpException();
    }
    $this->reminders->log($member, $channel);
    return new JsonResponse(['ok' => TRUE, 'date' => date('d/m')]);
  }

}
