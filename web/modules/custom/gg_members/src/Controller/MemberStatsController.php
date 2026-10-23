<?php

namespace Drupal\gg_members\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\gg_members\MemberStats;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Statistics page.
 */
class MemberStatsController extends ControllerBase {

  public function __construct(protected MemberStats $memberStats) {}

  public static function create(ContainerInterface $container) {
    return new static($container->get('gg_members.stats'));
  }

  public function page(): array {
    $history = $this->memberStats->history();

    return [
      '#theme' => 'gg_members_stats',
      '#history' => $history,
      '#basics' => $this->memberStats->basics($history),
      '#monthly' => $this->memberStats->monthly($history),
      '#by_class' => $this->memberStats->byClass($history),
      '#members' => $this->memberStats->members($history),
      '#attached' => ['library' => ['gg_members/admin']],
      '#cache' => ['max-age' => 0],
    ];
  }

}
