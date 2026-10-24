<?php

namespace Drupal\gg_members\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Drupal\gg_members\ExpenseManager;
use Drupal\gg_members\MemberStats;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Statistics: income (the main page), expenses, and profit — the same
 * layout three times.
 */
class MemberStatsController extends ControllerBase {

  public function __construct(
    protected MemberStats $memberStats,
    protected ExpenseManager $expenses,
  ) {}

  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('gg_members.stats'),
      $container->get('gg_members.expenses'),
    );
  }

  public function page(Request $request): array {
    $chartHistory = $this->memberStats->history(TRUE);
    [$history, $chart] = $this->period($request, $this->memberStats->history(), $chartHistory, 'gg_members.stats');

    return [
      '#theme' => 'gg_members_stats',
      '#history' => $history,
      '#chart' => $chart,
      '#basics' => $this->memberStats->basics($history),
      '#monthly' => $this->memberStats->monthly($chartHistory),
      '#by_class' => $this->memberStats->byClass($history),
      '#members' => $this->memberStats->members($history),
      '#attached' => ['library' => ['gg_members/admin', 'gg_members/stats_chart']],
      '#cache' => ['max-age' => 0],
    ];
  }

  public function expenses(Request $request): array {
    // Fixed monthly costs that have come due count too.
    $this->expenses->generateRecurring();
    $chartHistory = $this->memberStats->expenseHistory(TRUE);
    [$history, $chart] = $this->period($request, $this->memberStats->expenseHistory(), $chartHistory, 'gg_members.stats_expenses');

    return [
      '#theme' => 'gg_members_stats_expenses',
      '#history' => $history,
      '#chart' => $chart,
      '#basics' => $this->memberStats->expenseBasics($history),
      '#monthly' => $this->memberStats->expenseMonthly($chartHistory),
      '#categories' => $this->memberStats->expensesByCategory($history),
      '#attached' => ['library' => ['gg_members/admin', 'gg_members/stats_chart']],
      '#cache' => ['max-age' => 0],
    ];
  }

  public function profit(Request $request): array {
    $this->expenses->generateRecurring();
    $chartHistory = $this->memberStats->profitHistory(TRUE);
    [$history, $chart] = $this->period($request, $this->memberStats->profitHistory(), $chartHistory, 'gg_members.stats_profit');

    return [
      '#theme' => 'gg_members_stats_profit',
      '#history' => $history,
      '#chart' => $chart,
      '#basics' => $this->memberStats->profitBasics($history),
      '#monthly' => $this->memberStats->profitMonthly($chartHistory),
      '#attached' => ['library' => ['gg_members/admin', 'gg_members/stats_chart']],
      '#cache' => ['max-age' => 0],
    ];
  }

  /**
   * What the page is about: the last 12 months (its usual history), or a
   * month picked by clicking its column (?month=YYYY-MM) — then the whole
   * page is about that month.
   *
   * @return array
   *   [the history to build the page from, the columns' variables: selected
   *   ('YYYY-MM' or NULL), reset_url (back to the last 12 months), short
   *   (fewer than 12 months of data), from (first month, accusative)].
   */
  protected function period(Request $request, array $yearHistory, array $chartHistory, string $route): array {
    $shown = array_map(fn($month) => $month->format('Y-m'), $chartHistory['months']);
    $selected = $request->query->get('month');
    $selected = is_string($selected) && in_array($selected, $shown, TRUE) ? $selected : NULL;

    return [$selected ? $this->memberStats->monthHistory($selected) : $yearHistory, [
      'selected' => $selected,
      'reset_url' => Url::fromRoute($route)->toString(),
      'short' => count($shown) < 12,
      'from' => $chartHistory['from'],
    ]];
  }

}
