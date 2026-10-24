<?php

namespace Drupal\gg_members\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Drupal\gg_members\MemberManager;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Member roster listing, with class/status/"owes" filters.
 */
class MemberDashboardController extends ControllerBase {

  public function __construct(protected MemberManager $memberManager) {}

  public static function create(ContainerInterface $container) {
    return new static($container->get('gg_members.manager'));
  }

  public function list(Request $request): array {
    $filters = [
      'search' => trim((string) $request->query->get('search', '')),
      'class_tid' => $request->query->get('class_tid') ?: NULL,
      'status' => $request->query->has('status') ? $request->query->get('status') : NULL,
      'owes' => (bool) $request->query->get('owes'),
      'unpaid_current' => (bool) $request->query->get('unpaid_current'),
    ];
    $currentMonth = date('Y-m');

    $query = $this->memberManager->membersQuery(array_filter(
      ['search' => $filters['search'], 'class_tid' => $filters['class_tid'], 'status' => $filters['status']],
      fn($v) => $v !== NULL && $v !== ''
    ));

    // "Owes" depends on each member's full payment history (see
    // getUnpaidMonths()), not a single column, so it can't be a SQL
    // condition on the query above — build every row first, then filter and
    // paginate in PHP. Fine at the scale of one academy's roster.
    // This exact roster view (page, search, filters), handed to every link
    // below as ?back= so returning from a member lands here, not on page 1.
    $listQuery = array_intersect_key($request->query->all(), array_flip(['page', 'search', 'class_tid', 'status', 'owes', 'unpaid_current']));
    $back = Url::fromRoute('gg_members.dashboard', [], ['query' => $listQuery])->toString();

    // Whole-roster lookups in three queries, instead of several per member.
    $paidByMember = $this->memberManager->getPaidAmountsByMember();
    $feesByMember = $this->memberManager->getFeeHistoryByMember();
    $filesByMember = $this->memberManager->getFileCategoriesByMember();

    $allRows = [];
    foreach ($query->execute() as $record) {
      $memberId = (int) $record->id;
      $ledger = $this->memberManager->computeLedger($record, $paidByMember[$memberId] ?? [], $feesByMember[$memberId] ?? []);
      $openMonths = MemberManager::openMonths($ledger);
      $unpaidMonths = array_keys($openMonths);
      // Minors usually have no phone of their own; the guardian's is the one
      // the academy actually calls.
      $guardianPhone = !$record->phone && $record->guardian_phone;
      $allRows[] = [
        'id' => $record->id,
        'name' => $record->name,
        'class_name' => $record->class_name,
        'monthly_fee' => MemberManager::formatMoney($record->monthly_fee),
        'phone' => $guardianPhone ? $record->guardian_phone : $record->phone,
        'phone_is_guardian' => $guardianPhone,
        'status' => (bool) $record->status,
        'owes' => (bool) $unpaidMonths,
        'unpaid_months' => $unpaidMonths,
        'unpaid_count_label' => $this->formatPlural(count($unpaidMonths), '1 μήνας', '@count μήνες'),
        'outstanding' => MemberManager::formatMoney(MemberManager::outstanding($openMonths)),
        'unpaid_current' => isset($openMonths[$currentMonth]),
        'unpaid_title' => implode(', ', array_map([MemberManager::class, 'formatLedgerMonth'], $unpaidMonths, $openMonths)),
        'documents' => $this->documentStatuses($filesByMember[$memberId] ?? []),
        'view_url' => MemberManager::withBack(Url::fromRoute('gg_members.member_view', ['member' => $record->id]), $back),
        'edit_url' => MemberManager::withBack(Url::fromRoute('gg_members.member_edit', ['member' => $record->id]), $back),
        'payments_url' => MemberManager::withBack(Url::fromRoute('gg_members.payments', ['member' => $record->id]), $back),
      ];
    }

    if ($filters['owes']) {
      $allRows = array_values(array_filter($allRows, fn(array $row) => $row['owes']));
    }
    if ($filters['unpaid_current']) {
      $allRows = array_values(array_filter($allRows, fn(array $row) => $row['unpaid_current']));
    }

    $pagerManager = \Drupal::service('pager.manager');
    $pager = $pagerManager->createPager(count($allRows), 25);
    $page = $pager->getCurrentPage();
    $rows = array_slice($allRows, $page * 25, 25);

    $classes = [];
    foreach ($this->memberManager->getClasses() as $term) {
      $classes[$term->id()] = $term->label();
    }

    return [
      '#theme' => 'gg_members_dashboard',
      '#classes' => $classes,
      '#filters' => $filters,
      '#rows' => $rows,
      '#add_url' => MemberManager::withBack(Url::fromRoute('gg_members.member_add'), $back),
      '#stats' => $this->memberManager->rosterSummary($paidByMember, $feesByMember),
      '#pager' => ['#type' => 'pager'],
      '#attached' => ['library' => ['gg_members/admin', 'gg_members/remember_scroll', 'gg_members/filter_autosubmit']],
    ];
  }


  /**
   * The roster's document icons, one per category: whether the member has
   * at least one file in it. The doctor's note is flagged as required — it's
   * the one an academy is obliged to hold, so only its absence shows red.
   *
   * @param array $categories
   *   Category => TRUE for each category the member has files in.
   */
  protected function documentStatuses(array $categories): array {
    $documents = [];
    foreach ([
      'doctor_note' => $this->t('Χαρτί γιατρού'),
      'photo' => $this->t('Φωτογραφία'),
      'birth_certificate' => $this->t('Πιστοποιητικό γέννησης'),
      'card' => $this->t('Δελτίο'),
    ] as $key => $label) {
      $ok = isset($categories[$key]);
      $documents[] = [
        'key' => $key,
        'ok' => $ok,
        'required' => $key === 'doctor_note',
        'title' => $label . ': ' . ($ok ? $this->t('Έχει') : $this->t('Δεν έχει')),
      ];
    }
    return $documents;
  }

}
