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
    return [
      '#theme' => 'gg_members_stats',
      '#basics' => $this->memberStats->basics(),
      '#monthly' => $this->memberStats->monthly(),
      '#by_class' => $this->memberStats->byClass(),
      '#members' => $this->memberStats->members(),
      '#attached' => ['library' => ['gg_members/admin']],
      '#cache' => ['max-age' => 0],
    ];
  }

}
