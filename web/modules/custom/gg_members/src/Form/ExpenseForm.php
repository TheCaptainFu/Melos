<?php

namespace Drupal\gg_members\Form;

use Drupal\Component\Utility\Html;
use Drupal\Core\Render\Markup;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\gg_members\ExpenseManager;
use Drupal\gg_members\MemberManager;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * The "Έξοδα" tab: one month at a time — income, expenses and what's left,
 * a form to add (or edit) an expense, where the money went by category,
 * and the month's expenses.
 *
 * The month is ?month=YYYY-MM (default: this month); ?edit=ID loads an
 * expense into the form for changes.
 */
class ExpenseForm extends FormBase {

  public function __construct(protected ExpenseManager $expenses) {}

  public static function create(ContainerInterface $container) {
    return new static($container->get('gg_members.expenses'));
  }

  public function getFormId() {
    return 'gg_members_expense_form';
  }

  public function buildForm(array $form, FormStateInterface $form_state) {
    $request = $this->getRequest();
    $month = $request->query->get('month');
    if (!is_string($month) || !preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) {
      $month = date('Y-m');
    }
    $editing = $request->query->get('edit') ? $this->expenses->get((int) $request->query->get('edit')) : NULL;
    // Enter any fixed monthly costs that have come due since last time.
    $this->expenses->generateRecurring();

    $form['#attributes']['class'][] = 'gg-members-admin';
    $form['#attributes']['class'][] = 'gg-members-expenses';
    $form['#attributes']['lang'] = 'el';
    $form['#attached']['library'][] = 'gg_members/admin';
    $form['#cache']['max-age'] = 0;

    $form['month'] = ['#type' => 'value', '#value' => $month];
    $form['expense_id'] = ['#type' => 'value', '#value' => $editing ? (int) $editing->id : NULL];

    // Markup::create(): these carry inline styles (category colours, bar
    // widths) that #markup's XSS filter would strip; everything variable in
    // them is escaped with Html::escape() where it's built.
    $form['nav'] = ['#markup' => Markup::create($this->monthNav($month))];
    if (!$this->expenses->allRecurring()) {
      $form['recurring_hint'] = [
        '#markup' => Markup::create('<div class="gg-members-notice gg-members-notice--plain"><p>'
          . $this->t('Ορίστε τα <a href=":url">πάγια έξοδα</a> σας (ενοίκιο, αμοιβές…) στις Ρυθμίσεις, για να καταχωρούνται αυτόματα κάθε μήνα.', [
            ':url' => Url::fromRoute('gg_members.settings', [], ['fragment' => 'recurring-expenses'])->toString(),
          ])
          . '</p></div>'),
      ];
    }
    $form['summary'] = ['#markup' => Markup::create($this->summary($month))];

    $form['entry'] = [
      '#type' => 'fieldset',
      '#title' => $editing ? $this->t('Επεξεργασία εξόδου') : $this->t('Νέο έξοδο'),
      '#attributes' => ['class' => ['gg-members-fieldset', 'gg-members-expense-entry']],
    ];
    $form['entry']['expense_date'] = [
      '#type' => 'date',
      '#title' => $this->t('Ημερομηνία'),
      '#required' => TRUE,
      '#default_value' => $editing->expense_date ?? ($month === date('Y-m') ? date('Y-m-d') : $month . '-01'),
    ];
    $form['entry']['category'] = [
      '#type' => 'select',
      '#title' => $this->t('Κατηγορία'),
      '#options' => ExpenseManager::categoryOptions(),
      '#required' => TRUE,
      '#default_value' => $editing->category ?? 'rent',
    ];
    $form['entry']['amount'] = [
      '#type' => 'number',
      '#title' => $this->t('Ποσό (€)'),
      '#step' => 0.01,
      '#min' => 0.01,
      '#required' => TRUE,
      '#default_value' => $editing->amount ?? '',
    ];
    $form['entry']['description'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Περιγραφή'),
      '#maxlength' => 255,
      '#placeholder' => $this->t('π.χ. Ενοίκιο γηπέδου Οκτωβρίου'),
      '#default_value' => $editing->description ?? '',
    ];
    $form['entry']['actions'] = ['#type' => 'actions'];
    $form['entry']['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $editing ? $this->t('Αποθήκευση αλλαγών') : $this->t('Προσθήκη εξόδου'),
      '#button_type' => 'primary',
    ];
    if ($editing) {
      $form['entry']['actions']['cancel'] = [
        '#type' => 'link',
        '#title' => $this->t('Ακύρωση'),
        '#url' => $this->monthUrl($month),
        '#attributes' => ['class' => ['button']],
      ];
    }

    $form['by_category'] = ['#markup' => Markup::create($this->byCategory($month))];
    $this->buildList($form, $month);

    return $form;
  }

  /**
   * ‹ Οκτώβριος 2026 › — the month being looked at, with arrows.
   */
  protected function monthNav(string $month): string {
    $previous = $this->monthUrl($this->expenses->previousMonth($month))->toString();
    $next = $this->monthUrl($this->expenses->nextMonth($month))->toString();
    $isCurrent = $month === date('Y-m');
    return '<div class="gg-members-month-nav">'
      . '<a href="' . Html::escape($previous) . '" class="gg-members-month-nav__arrow" aria-label="' . $this->t('Προηγούμενος μήνας') . '">‹</a>'
      . '<span class="gg-members-month-nav__label">' . Html::escape(MemberManager::formatMonth($month)) . '</span>'
      . ($isCurrent ? '<span class="gg-members-month-nav__arrow is-disabled" aria-hidden="true">›</span>' : '<a href="' . Html::escape($next) . '" class="gg-members-month-nav__arrow" aria-label="' . $this->t('Επόμενος μήνας') . '">›</a>')
      . (!$isCurrent ? '<a href="' . Html::escape($this->monthUrl(date('Y-m'))->toString()) . '" class="gg-members-month-nav__today">' . $this->t('Τρέχων μήνας') . '</a>' : '')
      . '</div>';
  }

  /**
   * Income, expenses and profit for the month, as three cards.
   */
  protected function summary(string $month): string {
    $income = $this->expenses->income($month);
    $spent = array_sum($this->expenses->totalsByCategory($month));
    $profit = $income - $spent;

    $cards = [
      [$this->t('Έσοδα'), $income, 'income', $this->t('όσα πληρώθηκαν τον μήνα')],
      [$this->t('Έξοδα'), $spent, 'expenses', $this->t('όσα καταχωρήθηκαν παρακάτω')],
      [$profit >= 0 ? $this->t('Κέρδος') : $this->t('Ζημιά'), abs($profit), $profit >= 0 ? 'profit' : 'loss', $this->t('έσοδα μείον έξοδα')],
    ];
    $html = '<div class="gg-members-stats gg-members-stats--big gg-members-money">';
    foreach ($cards as [$label, $amount, $modifier, $sub]) {
      $html .= '<div class="gg-members-stat gg-members-money__card gg-members-money__card--' . $modifier . '">'
        . '<span class="gg-members-stat__value">' . ($modifier === 'loss' ? '−' : '') . MemberManager::formatMoney($amount) . '€</span>'
        . '<span class="gg-members-stat__label">' . Html::escape($label) . '</span>'
        . '<span class="gg-members-stat__sub">' . Html::escape($sub) . '</span>'
        . '</div>';
    }
    return $html . '</div>';
  }

  /**
   * Where the month's money went: one bar per category, the biggest full.
   */
  protected function byCategory(string $month): string {
    $totals = $this->expenses->totalsByCategory($month);
    if (!$totals) {
      return '';
    }
    $sum = array_sum($totals);

    // Whole percents that add up to 100: round down, then hand the points
    // left over to the largest remainders.
    $exact = array_map(fn($amount) => $amount / $sum * 100, $totals);
    $percents = array_map('floor', $exact);
    $remainders = array_map(fn($key) => $exact[$key] - $percents[$key], array_combine(array_keys($exact), array_keys($exact)));
    arsort($remainders);
    foreach (array_slice(array_keys($remainders), 0, (int) (100 - array_sum($percents))) as $key) {
      $percents[$key]++;
    }

    // The pie: one slice per category, in the list's order.
    $stops = [];
    $legend = '';
    $start = 0;
    foreach ($totals as $category => $amount) {
      [$label, $color] = ExpenseManager::CATEGORIES[$category] ?? ExpenseManager::CATEGORIES['other'];
      $end = $start + $amount / $sum * 100;
      $stops[] = $color . ' ' . round($start, 2) . '% ' . round($end, 2) . '%';
      $start = $end;
      $legend .= '<div style="--category-color: ' . $color . '">'
        . '<span class="gg-members-expense-pie__dot"></span>'
        . '<span class="gg-members-expense-pie__name">' . Html::escape($label) . '</span>'
        . '<strong>' . MemberManager::formatMoney($amount) . '€</strong>'
        . '<em>' . (int) $percents[$category] . '%</em>'
        . '</div>';
    }

    return '<section class="gg-members-card gg-members-expense-categories"><h2>' . $this->t('Πού πήγαν τα λεφτά') . '</h2>'
      . '<div class="gg-members-expense-pie">'
      . '<div class="gg-members-expense-pie__pie" style="background: conic-gradient(' . implode(', ', $stops) . ')" role="img" aria-label="' . $this->t('Τα έξοδα του μήνα ανά κατηγορία') . '"></div>'
      . '<div class="gg-members-expense-pie__legend">' . $legend
      . '<div class="gg-members-expense-pie__total"><span></span><span>' . $this->t('Σύνολο') . '</span><strong>' . MemberManager::formatMoney($sum) . '€</strong><em>100%</em></div>'
      . '</div></div></section>';
  }

  /**
   * The month's expenses, each with edit and delete.
   */
  protected function buildList(array &$form, string $month): void {
    $rows = $this->expenses->forMonth($month);

    $form['list'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['gg-members-card', 'gg-members-expense-list']],
      'title' => ['#markup' => '<h2>' . $this->t('Όλα τα έξοδα του μήνα') . '</h2>'],
    ];
    if (!$rows) {
      $form['list']['empty'] = ['#markup' => '<p class="gg-members-legend">' . $this->t('Δεν έχουν καταχωρηθεί έξοδα για αυτόν τον μήνα.') . '</p>'];
      return;
    }

    $form['list']['table'] = [
      '#type' => 'table',
      '#attributes' => ['class' => ['gg-members-table']],
      '#header' => [$this->t('Ημερομηνία'), $this->t('Κατηγορία'), $this->t('Περιγραφή'), $this->t('Ποσό'), ''],
    ];
    foreach ($rows as $expense) {
      $id = (int) $expense->id;
      [$label, $color] = ExpenseManager::CATEGORIES[$expense->category] ?? ExpenseManager::CATEGORIES['other'];
      $date = \DateTimeImmutable::createFromFormat('Y-m-d', $expense->expense_date);
      $form['list']['table'][$id]['date'] = ['#wrapper_attributes' => ['class' => ['gg-members-expense-list__date'], 'data-label' => $this->t('Ημερομηνία')]] + ['#markup' => $date ? $date->format('d/m/Y') : Html::escape($expense->expense_date)];
      $form['list']['table'][$id]['category'] = ['#wrapper_attributes' => ['class' => ['gg-members-expense-list__category'], 'data-label' => $this->t('Κατηγορία')]] + ['#markup' => Markup::create('<span class="gg-members-category-chip" style="--category-color: ' . $color . '">' . Html::escape($label) . '</span>')];
      $form['list']['table'][$id]['description'] = ['#wrapper_attributes' => ['class' => ['gg-members-expense-list__description'], 'data-label' => $this->t('Περιγραφή')]] + ['#markup' => ($expense->description ? Html::escape($expense->description) : '<span class="gg-members-cell-empty">—</span>')
        . ($expense->recurring_id ? ' <span class="gg-members-recurring-tag" title="' . $this->t('Καταχωρήθηκε αυτόματα από τα πάγια έξοδα') . '">' . $this->t('πάγιο') . '</span>' : '')];
      $form['list']['table'][$id]['amount'] = ['#wrapper_attributes' => ['class' => ['gg-members-expense-list__amount'], 'data-label' => $this->t('Ποσό')]] + ['#markup' => '<strong>' . MemberManager::formatMoney($expense->amount) . '€</strong>'];
      $form['list']['table'][$id]['actions'] = [
        '#wrapper_attributes' => ['class' => ['gg-members-expense-list__actions']],
        '#type' => 'container',
        '#attributes' => ['class' => ['gg-members-row-actions']],
        'edit' => [
          '#type' => 'link',
          '#title' => $this->t('Επεξεργασία'),
          '#url' => $this->monthUrl($month, ['edit' => $id]),
          '#attributes' => ['class' => ['gg-members-action--payments']],
        ],
        'delete' => [
          '#type' => 'submit',
          '#value' => $this->t('Διαγραφή'),
          '#name' => 'delete_expense_' . $id,
          '#expense_id' => $id,
          '#limit_validation_errors' => [['month']],
          '#validate' => [],
          '#submit' => ['::deleteExpense'],
          '#attributes' => [
            'class' => ['gg-members-expense-delete'],
            'onclick' => 'return confirm(' . json_encode((string) $this->t('Διαγραφή αυτού του εξόδου;')) . ');',
          ],
        ],
      ];
    }
  }

  public function validateForm(array &$form, FormStateInterface $form_state) {
    if ((float) $form_state->getValue('amount') <= 0) {
      $form_state->setErrorByName('amount', $this->t('Παρακαλώ βάλε έγκυρο ποσό.'));
    }
  }

  public function submitForm(array &$form, FormStateInterface $form_state) {
    $id = $form_state->getValue('expense_id');
    $this->expenses->save([
      'expense_date' => $form_state->getValue('expense_date'),
      'category' => $form_state->getValue('category'),
      'amount' => $form_state->getValue('amount'),
      'description' => trim((string) $form_state->getValue('description')),
    ], $id ? (int) $id : NULL);

    $this->messenger()->addStatus($id ? $this->t('Οι αλλαγές αποθηκεύτηκαν.') : $this->t('Το έξοδο προστέθηκε.'));
    // Back to the month the expense belongs to.
    $form_state->setRedirectUrl($this->monthUrl(substr($form_state->getValue('expense_date'), 0, 7)));
  }

  public function deleteExpense(array &$form, FormStateInterface $form_state) {
    $this->expenses->delete((int) $form_state->getTriggeringElement()['#expense_id']);
    $this->messenger()->addStatus($this->t('Το έξοδο διαγράφηκε.'));
    $form_state->setRedirectUrl($this->monthUrl($form_state->getValue('month')));
  }

  protected function monthUrl(string $month, array $query = []): Url {
    return Url::fromRoute('gg_members.expenses', [], ['query' => ['month' => $month] + $query]);
  }

}
