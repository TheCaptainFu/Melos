<?php

namespace Drupal\gg_members_attendance\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Drupal\gg_members\MemberManager;
use Drupal\gg_members_attendance\Attendance;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The "Μήνας" tab: members down the side, the month's days across, a tick
 * where they came; and the same as a CSV for Excel.
 */
class MonthController extends ControllerBase {

  public function __construct(
    protected Attendance $attendance,
    protected MemberManager $memberManager,
  ) {}

  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('gg_members_attendance.attendance'),
      $container->get('gg_members.manager'),
    );
  }

  public function page(Request $request): array {
    [$month, $classTid] = $this->filters($request);
    $grid = $this->attendance->month($month, $classTid);
    $current = date('Y-m');
    $first = new \DateTimeImmutable($month . '-01');
    $link = fn(string $m) => Url::fromRoute('gg_members_attendance.month', [], ['query' => array_filter(['month' => $m === $current ? NULL : $m, 'class' => $classTid])])->toString();
    $next = $first->modify('+1 month')->format('Y-m');

    return [
      '#theme' => 'gg_members_attendance_month',
      '#grid' => $grid,
      '#label' => MemberManager::MONTH_NAMES[(int) $first->format('n')] . ' ' . $first->format('Y'),
      '#previous_url' => $link($first->modify('-1 month')->format('Y-m')),
      '#next_url' => $next <= $current ? $link($next) : NULL,
      '#current_url' => $month !== $current ? $link($current) : NULL,
      '#classes' => array_map(fn($term) => $term->label(), $this->memberManager->getClasses()),
      '#class_tid' => $classTid,
      '#month' => $month,
      '#export_url' => Url::fromRoute('gg_members_attendance.month_export', [], ['query' => array_filter(['month' => $month, 'class' => $classTid])])->toString(),
      '#attached' => ['library' => ['gg_members/admin', 'gg_members/filter_autosubmit', 'gg_members_attendance/admin']],
      '#cache' => ['max-age' => 0],
    ];
  }

  /**
   * The month as a CSV that Excel opens (with a BOM, for the Greek): a row
   * per member, a column per day, "x" where they came, and the totals.
   */
  public function export(Request $request): StreamedResponse {
    [$month, $classTid] = $this->filters($request);
    $grid = $this->attendance->month($month, $classTid);

    $response = new StreamedResponse(function () use ($grid) {
      $handle = fopen('php://output', 'w');
      fwrite($handle, "\xEF\xBB\xBF");
      fputcsv($handle, array_merge(['Ονοματεπώνυμο', 'Τάξη'], array_keys($grid['days']), ['Σύνολο']));
      foreach ($grid['members'] as $member) {
        $row = [$member['name'], (string) $member['class_name']];
        foreach (array_keys($grid['days']) as $day) {
          $row[] = isset($member['days'][$day]) ? 'x' : '';
        }
        $row[] = $member['total'];
        fputcsv($handle, $row);
      }
      fputcsv($handle, array_merge(['Σύνολο ημέρας', ''], array_column($grid['days'], 'total'), [array_sum(array_column($grid['days'], 'total'))]));
      fclose($handle);
    });
    $response->headers->set('Content-Type', 'text/csv; charset=utf-8');
    $response->headers->set('Content-Disposition', 'attachment; filename="parousies-' . $month . '.csv"');
    return $response;
  }

  /**
   * The month and class asked for: ?month=YYYY-MM (this month, and never a
   * future one), ?class= (0 = all).
   */
  protected function filters(Request $request): array {
    $month = $request->query->get('month');
    $current = date('Y-m');
    if (!is_string($month) || !preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month) || $month > $current) {
      $month = $current;
    }
    $classTid = (int) $request->query->get('class');
    if (!isset($this->memberManager->getClasses()[$classTid])) {
      $classTid = 0;
    }
    return [$month, $classTid];
  }

}
