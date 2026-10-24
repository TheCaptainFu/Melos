<?php

namespace Drupal\gg_members\Form;

use Drupal\Component\Utility\Html;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\gg_members\MemberManager;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Records a payment for one member and lists their payment history.
 */
class PaymentForm extends FormBase {

  protected \stdClass $member;

  public function __construct(protected MemberManager $memberManager) {}

  public static function create(ContainerInterface $container) {
    return new static($container->get('gg_members.manager'));
  }

  public function getFormId() {
    return 'gg_members_payment_form';
  }

  /**
   * Title callback for the route.
   */
  public function title(int $member): string {
    $record = $this->memberManager->getMember($member);
    return $record ? (string) $this->t('Πληρωμές: @name', ['@name' => $record->name]) : (string) $this->t('Πληρωμές');
  }

  public function buildForm(array $form, FormStateInterface $form_state, ?int $member = NULL) {
    $record = $member ? $this->memberManager->getMember($member) : NULL;
    if (!$record) {
      throw new NotFoundHttpException();
    }
    $this->member = $record;

    $form['#attributes']['class'][] = 'gg-members-admin';
    $form['#attached']['library'][] = 'gg_members/admin';

    $form['back'] = MemberManager::backLinks($member);

    $form['member_id'] = ['#type' => 'value', '#value' => $member];

    $stats = $this->memberManager->getMemberStats($member);
    $form['stats'] = [
      '#markup' => $this->buildStatsMarkup($stats),
    ];

    $ledger = $this->memberManager->getLedger($member);
    $unpaidMonths = MemberManager::openMonths($ledger);

    $intro = (string) $this->t('Μηνιαία συνδρομή: @fee€.', ['@fee' => MemberManager::formatMoney($record->monthly_fee)]);
    $fees = $this->memberManager->getFeeHistory($member);
    if (count($fees) > 1) {
      $changes = [];
      foreach ($fees as $from => $fee) {
        $changes[] = $this->t('@fee€ από @month', ['@fee' => MemberManager::formatMoney($fee), '@month' => MemberManager::formatMonth($from)]);
      }
      $intro .= ' ' . $this->t('Αλλαγές συνδρομής: @changes.', ['@changes' => implode(', ', $changes)]);
    }
    $form['intro'] = [
      '#markup' => '<p class="gg-members-legend">' . Html::escape($intro) . '</p>',
    ];

    if ($unpaidMonths) {
      $form['unpaid'] = [
        '#markup' => '<div class="gg-members-unpaid-banner">'
          . '<strong>' . $this->formatPlural(count($unpaidMonths), 'Χρωστάει 1 μήνα (@total€):', 'Χρωστάει @count μήνες (@total€):', [
            '@total' => MemberManager::formatMoney(MemberManager::outstanding($unpaidMonths)),
          ]) . '</strong> '
          . Html::escape(implode(', ', array_map([MemberManager::class, 'formatLedgerMonth'], array_keys($unpaidMonths), $unpaidMonths)))
          . ' <a href="' . Html::escape(MemberManager::withBack(\Drupal\Core\Url::fromRoute('gg_members.reminder', ['member' => $member]), MemberManager::backFromRequest())->toString()) . '" class="button button--small use-ajax gg-members-remind-button" data-dialog-type="modal" data-dialog-options=\'{"width":560,"dialogClass":"gg-members-reminder-dialog"}\'>' . $this->t('Υπενθύμιση') . '</a>'
          . '</div>',
      ];
      $form['#attached']['library'][] = 'core/drupal.dialog.ajax';
    }
    else {
      $form['unpaid'] = [
        '#markup' => '<div class="gg-members-unpaid-banner gg-members-unpaid-banner--clear">' . $this->t('Ενήμερος — καμία εκκρεμότητα.') . '</div>',
      ];
    }

    $payableMonths = $this->memberManager->getPayableMonths($member);

    $form['payment'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Καταχώρηση πληρωμής'),
      '#attributes' => ['class' => ['gg-members-fieldset']],
    ];

    if (!$payableMonths) {
      // Every month from enrollment through MONTHS_AHEAD months ahead is
      // already settled — nothing legitimate left to record right now.
      $form['payment']['empty'] = [
        '#markup' => '<p class="gg-members-legend">' . $this->formatPlural(MemberManager::MONTHS_AHEAD, 'Δεν υπάρχει μήνας προς πληρωμή αυτή τη στιγμή — όλοι οι μήνες μέχρι και 1 μήνα μπροστά είναι ήδη εξοφλημένοι.', 'Δεν υπάρχει μήνας προς πληρωμή αυτή τη στιγμή — όλοι οι μήνες μέχρι και @count μήνες μπροστά είναι ήδη εξοφλημένοι.') . '</p>',
      ];
      $this->buildHistory($form, $member);
      return $form;
    }

    $form['#attributes']['class'][] = 'gg-members-payment-form';
    $form['#attached']['library'][] = 'gg_members/payment_form';

    $options = [];
    foreach ($payableMonths as $month => $entry) {
      $options[$month] = $entry['paid'] > 0
        ? $this->t('@month — @remaining€ (υπόλοιπο από @due€)', [
          '@month' => MemberManager::formatMonth($month),
          '@remaining' => MemberManager::formatMoney($entry['remaining']),
          '@due' => MemberManager::formatMoney($entry['due']),
        ])
        : $this->t('@month — @remaining€', [
          '@month' => MemberManager::formatMonth($month),
          '@remaining' => MemberManager::formatMoney($entry['remaining']),
        ]);
    }
    $firstMonth = array_key_first($payableMonths);

    $form['payment']['months'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Μήνες'),
      '#description' => $this->t('Τσέκαρε έναν ή περισσότερους μήνες. Το ποσό μοιράζεται ξεκινώντας από τον παλαιότερο· αν δεν φτάνει για όλους, ο τελευταίος μένει με υπόλοιπο.'),
      '#options' => $options,
      '#default_value' => [$firstMonth],
      '#required' => TRUE,
    ];
    foreach ($payableMonths as $month => $entry) {
      // Read by payment-form.js to keep the amount in step with the ticks.
      $form['payment']['months'][$month]['#attributes']['data-remaining'] = number_format($entry['remaining'], 2, '.', '');
    }

    $form['payment']['amount'] = [
      '#type' => 'number',
      '#title' => $this->t('Ποσό (€)'),
      '#description' => $this->t('Συμπληρώνεται μόνο του με το υπόλοιπο των μηνών που τσέκαρες. Βάλε μικρότερο ποσό για μερική πληρωμή.'),
      '#step' => 0.01,
      '#min' => 0.01,
      '#required' => TRUE,
      '#default_value' => number_format($payableMonths[$firstMonth]['remaining'], 2, '.', ''),
    ];
    $form['payment']['payment_date'] = [
      '#type' => 'date',
      '#title' => $this->t('Ημερομηνία πληρωμής'),
      '#required' => TRUE,
      '#default_value' => (new \DateTimeImmutable('today'))->format('Y-m-d'),
    ];
    $form['payment']['notes'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Σημειώσεις'),
      '#maxlength' => 255,
    ];

    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Καταχώρηση'),
    ];

    $this->buildHistory($form, $member);

    return $form;
  }

  /**
   * Builds the per-member stats bar (same visual style as the dashboard's
   * roster-wide one, scoped to just this member).
   */
  protected function buildStatsMarkup(array $stats): string {
    $lastPayment = $stats['last_payment_date'] ?: (string) $this->t('Καμία ακόμα');

    $items = [
      [number_format($stats['total_paid'], 2, ',', '.') . '€', $this->t('Σύνολο πληρωμών')],
      [(string) $stats['payment_count'], $this->t('Αριθμός πληρωμών')],
      [$lastPayment, $this->t('Τελευταία πληρωμή')],
    ];

    $html = '<div class="gg-members-stats">';
    foreach ($items as [$value, $label]) {
      $html .= '<div class="gg-members-stat">'
        . '<span class="gg-members-stat__value">' . Html::escape($value) . '</span>'
        . '<span class="gg-members-stat__label">' . Html::escape($label) . '</span>'
        . '</div>';
    }
    $html .= '</div>';

    return $html;
  }

  /**
   * Adds a read-only table of past payments, with removal.
   */
  protected function buildHistory(array &$form, int $memberId): void {
    $fullHistory = $this->memberManager->getPaymentHistory($memberId);

    $form['history'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Ιστορικό πληρωμών'),
      '#attributes' => ['class' => ['gg-members-fieldset']],
    ];

    if (!$fullHistory) {
      $form['history']['empty'] = [
        '#markup' => '<p class="gg-members-legend">' . $this->t('Δεν υπάρχουν καταχωρημένες πληρωμές ακόμα.') . '</p>',
      ];
      return;
    }

    $pagerManager = \Drupal::service('pager.manager');
    $pager = $pagerManager->createPager(count($fullHistory), 5);
    $page = $pager->getCurrentPage();
    $history = array_slice($fullHistory, $page * 5, 5);

    $form['history']['table'] = [
      '#type' => 'table',
      '#attributes' => ['class' => ['gg-members-table']],
      '#header' => [$this->t('Μήνας'), $this->t('Ποσό'), $this->t('Ημ/νία πληρωμής'), $this->t('Σημειώσεις'), ''],
      '#prefix' => '<div class="gg-members-table-scroll">',
      '#suffix' => '</div>',
    ];

    foreach ($history as $payment) {
      $id = (int) $payment->id;
      $form['history']['table'][$id]['month'] = ['#markup' => MemberManager::formatMonth($payment->month_covered)];
      $form['history']['table'][$id]['amount'] = ['#markup' => MemberManager::formatMoney($payment->amount) . '€'];
      $form['history']['table'][$id]['date'] = ['#markup' => $payment->payment_date];
      $form['history']['table'][$id]['notes'] = ['#markup' => $payment->notes ? Html::escape($payment->notes) : ''];
      $form['history']['table'][$id]['remove'] = [
        '#type' => 'submit',
        '#value' => $this->t('Αφαίρεση'),
        '#name' => 'remove_payment_' . $id,
        '#payment_id' => $id,
        '#limit_validation_errors' => [['member_id']],
        '#validate' => [],
        '#submit' => ['::removePayment'],
      ];
    }

    $form['history']['pager'] = ['#type' => 'pager'];
  }

  public function validateForm(array &$form, FormStateInterface $form_state) {
    $amount = $form_state->getValue('amount');
    if ($amount === '' || $amount === NULL || (float) $amount <= 0) {
      $form_state->setErrorByName('amount', $this->t('Παρακαλώ βάλε έγκυρο ποσό.'));
      return;
    }

    $months = array_keys(array_filter((array) $form_state->getValue('months')));
    if (!$months) {
      return;
    }

    // The checkboxes only list payable months, but check again server-side —
    // they could be stale if this tab was left open while a payment was
    // recorded elsewhere in the meantime.
    $memberId = (int) $form_state->getValue('member_id');
    $payable = $this->memberManager->getPayableMonths($memberId);
    $total = 0.0;
    foreach ($months as $month) {
      if (!isset($payable[$month])) {
        $form_state->setErrorByName('months', $this->t('Ο μήνας @month είναι ήδη εξοφλημένος ή δεν είναι έγκυρος για καταχώρηση πληρωμής.', ['@month' => MemberManager::formatMonth($month)]));
        return;
      }
      $total += $payable[$month]['remaining'];
    }

    if (round((float) $amount, 2) > round($total, 2)) {
      $form_state->setErrorByName('amount', $this->t('Το ποσό (@amount€) είναι μεγαλύτερο από το υπόλοιπο των μηνών που τσέκαρες (@total€).', [
        '@amount' => MemberManager::formatMoney($amount),
        '@total' => MemberManager::formatMoney($total),
      ]));
    }
  }

  public function submitForm(array &$form, FormStateInterface $form_state) {
    $memberId = (int) $form_state->getValue('member_id');

    $recorded = $this->memberManager->recordPayments(
      $memberId,
      array_keys(array_filter((array) $form_state->getValue('months'))),
      (float) $form_state->getValue('amount'),
      $form_state->getValue('payment_date'),
      trim((string) $form_state->getValue('notes')),
    );

    $parts = [];
    foreach ($recorded as $month => $share) {
      $parts[] = MemberManager::formatMonth($month) . ': ' . MemberManager::formatMoney($share) . '€';
    }
    $this->messenger()->addStatus($this->t('Η πληρωμή καταχωρήθηκε (@months).', ['@months' => implode(', ', $parts)]));
    $form_state->setRedirectUrl($this->paymentsUrl($memberId));
  }

  public function removePayment(array &$form, FormStateInterface $form_state) {
    $memberId = (int) $form_state->getValue('member_id');
    $id = (int) $form_state->getTriggeringElement()['#payment_id'];

    $this->memberManager->deletePayment($id, $memberId);
    $this->messenger()->addStatus($this->t('Η πληρωμή αφαιρέθηκε.'));
    $form_state->setRedirectUrl($this->paymentsUrl($memberId));
  }

  /**
   * This member's payments page, keeping the ?back= roster URL so the
   * "← Όλα τα μέλη" button still returns to the right roster page.
   */
  protected function paymentsUrl(int $memberId): \Drupal\Core\Url {
    return MemberManager::withBack(
      \Drupal\Core\Url::fromRoute('gg_members.payments', ['member' => $memberId]),
      MemberManager::backFromRequest()
    );
  }

}
