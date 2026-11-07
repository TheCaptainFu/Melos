<?php

namespace Drupal\gg_members_expenses;

use Drupal\Component\Utility\Html;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Render\Markup;
use Drupal\Core\Url;
use Drupal\gg_members\Form\SettingsForm;
use Drupal\gg_members\MemberManager;

/**
 * "Πάγια έξοδα" in the members settings (gg_members_settings_form): the
 * fixed monthly expenses, with their own add/edit/delete buttons, which
 * save straight away and leave the rest of the settings alone.
 */
class RecurringSettings {

  /**
   * Adds the section to the settings form: the same fields as a single
   * expense, where the date is the first one and its day repeats every
   * month; the list of them underneath. ?edit_recurring=ID loads one into
   * the fields.
   */
  public static function build(array &$form): void {
    $expenses = self::expenses();
    $editId = (int) \Drupal::request()->query->get('edit_recurring');
    $editing = $editId ? $expenses->getRecurring($editId) : NULL;

    $form['recurring'] = SettingsForm::section(t('Πάγια έξοδα'), t('Έξοδα που επαναλαμβάνονται κάθε μήνα, όπως το ενοίκιο και οι αμοιβές. Τα ορίζετε μία φορά και καταχωρούνται μόνα τους στα Έξοδα κάθε μήνα, την ίδια μέρα με την ημερομηνία που βάλατε.'));
    $form['recurring']['#attributes']['id'] = 'recurring-expenses';
    // After the settings' own sections, before the save button.
    $form['recurring']['#weight'] = 50;

    // Not #required: these fields only matter to their own button below,
    // not to "Αποθήκευση ρυθμίσεων" — validateRecurring() checks them.
    $required = ['class' => ['form-required']];
    $form['recurring']['entry'] = [
      '#type' => 'container',
      '#tree' => TRUE,
      '#parents' => ['recurring'],
      '#attributes' => ['class' => ['gg-members-expense-row']],
    ];
    $form['recurring']['entry']['start_date'] = [
      '#type' => 'date',
      '#title' => t('Ημερομηνία'),
      '#label_attributes' => $required,
      '#default_value' => $editing ? $editing->start_month . '-' . sprintf('%02d', $editing->day) : date('Y-m-d'),
    ];
    $form['recurring']['entry']['category'] = [
      '#type' => 'select',
      '#title' => t('Κατηγορία'),
      '#label_attributes' => $required,
      '#options' => ExpenseManager::categoryOptions(),
      '#default_value' => $editing->category ?? 'rent',
    ];
    $form['recurring']['entry']['amount'] = [
      '#type' => 'number',
      '#title' => t('Ποσό (€)'),
      '#label_attributes' => $required,
      '#step' => 0.01,
      '#min' => 0.01,
      '#default_value' => $editing->amount ?? '',
    ];
    $form['recurring']['entry']['description'] = [
      '#type' => 'textfield',
      '#title' => t('Περιγραφή'),
      '#maxlength' => 255,
      '#placeholder' => t('π.χ. Ενοίκιο γηπέδου'),
      '#default_value' => $editing->description ?? '',
    ];
    $form['recurring']['buttons'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['gg-members-recurring-buttons']],
      'save' => [
        '#type' => 'submit',
        '#value' => $editing ? t('Αποθήκευση πάγιου') : t('Προσθήκη πάγιου'),
        '#name' => 'recurring_save',
        '#recurring_id' => $editing ? (int) $editing->id : NULL,
        '#limit_validation_errors' => [['recurring']],
        '#validate' => [[static::class, 'validate']],
        '#submit' => [[static::class, 'save']],
      ],
    ];
    if ($editing) {
      $form['recurring']['buttons']['cancel'] = [
        '#type' => 'link',
        '#title' => t('Ακύρωση'),
        '#url' => self::url(),
        '#attributes' => ['class' => ['button']],
      ];
    }

    $items = $expenses->allRecurring();
    if (!$items) {
      $form['recurring']['empty'] = ['#markup' => '<p class="gg-members-legend gg-members-recurring-empty">' . t('Δεν έχετε ορίσει ακόμα πάγια έξοδα. Ξεκινήστε από το ενοίκιο και τις αμοιβές.') . '</p>'];
      return;
    }
    $monthly = array_sum(array_map(fn($item) => $item->active ? (float) $item->amount : 0, $items));
    $form['recurring']['list'] = [
      '#type' => 'table',
      '#attributes' => ['class' => ['gg-members-table', 'gg-members-recurring-table']],
      '#header' => [t('Περιγραφή'), t('Κατηγορία'), t('Ποσό'), t('Πότε'), ''],
      '#footer' => [[
        'data' => [
          ['data' => t('Σύνολο ανά μήνα'), 'colspan' => 2],
          ['data' => Markup::create('<strong>' . MemberManager::formatMoney($monthly) . '€</strong>'), 'colspan' => 3],
        ],
      ]],
    ];
    foreach ($items as $item) {
      $id = (int) $item->id;
      [$label, $color] = ExpenseManager::CATEGORIES[$item->category] ?? ExpenseManager::CATEGORIES['other'];
      $when = t('κάθε μήνα στις @day', ['@day' => $item->day])
        . '<span class="gg-members-cell-note">' . t('από τον @month', ['@month' => mb_substr(MemberManager::MONTH_NAMES[(int) substr($item->start_month, 5)], 0, -1) . ' ' . substr($item->start_month, 0, 4)])
        . ($item->only_open ? ' · ' . t('εκτός κλειστών μηνών') : '')
        . (!$item->active ? ' · ' . t('σε παύση') : '')
        . '</span>';
      $form['recurring']['list'][$id]['description'] = ['#markup' => '<strong>' . Html::escape($item->description) . '</strong>'];
      $form['recurring']['list'][$id]['category'] = ['#markup' => Markup::create('<span class="gg-members-category-chip" style="--category-color: ' . $color . '">' . Html::escape($label) . '</span>')];
      $form['recurring']['list'][$id]['amount'] = ['#markup' => '<strong>' . MemberManager::formatMoney($item->amount) . '€</strong>'];
      $form['recurring']['list'][$id]['when'] = ['#markup' => $when];
      $form['recurring']['list'][$id]['actions'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['gg-members-row-actions']],
        'edit' => [
          '#type' => 'link',
          '#title' => t('Επεξεργασία'),
          '#url' => self::url(['edit_recurring' => $id]),
          '#attributes' => ['class' => ['gg-members-action--payments']],
        ],
        'delete' => [
          '#type' => 'submit',
          '#value' => t('Διαγραφή'),
          '#name' => 'delete_recurring_' . $id,
          '#recurring_id' => $id,
          '#limit_validation_errors' => [],
          '#validate' => [],
          '#submit' => [[static::class, 'delete']],
          '#attributes' => [
            'class' => ['gg-members-expense-delete'],
            'onclick' => 'return confirm(' . json_encode((string) t('Διαγραφή αυτού του πάγιου εξόδου; Όσα έχουν ήδη καταχωρηθεί στα Έξοδα θα μείνουν.')) . ');',
          ],
        ],
      ];
    }
  }

  public static function validate(array &$form, FormStateInterface $form_state) {
    $values = (array) $form_state->getValue('recurring');
    if (empty($values['start_date'])) {
      $form_state->setErrorByName('recurring][start_date', t('Βάλτε την ημερομηνία της πρώτης χρέωσης.'));
    }
    if ((float) ($values['amount'] ?? 0) <= 0) {
      $form_state->setErrorByName('recurring][amount', t('Βάλτε το ποσό του πάγιου εξόδου.'));
    }
  }

  public static function save(array &$form, FormStateInterface $form_state) {
    $values = (array) $form_state->getValue('recurring');
    $id = $form_state->getTriggeringElement()['#recurring_id'];
    $category = $values['category'] ?? 'other';
    self::expenses()->saveRecurring([
      'start_date' => $values['start_date'],
      'category' => $category,
      'amount' => $values['amount'],
      'description' => trim((string) ($values['description'] ?? '')) ?: ExpenseManager::categoryOptions()[$category] ?? '',
    ], $id ? (int) $id : NULL);
    \Drupal::messenger()->addStatus($id ? t('Το πάγιο έξοδο αποθηκεύτηκε.') : t('Το πάγιο έξοδο προστέθηκε· θα καταχωρείται αυτόματα κάθε μήνα.'));
    $form_state->setRedirectUrl(self::url());
  }

  public static function delete(array &$form, FormStateInterface $form_state) {
    self::expenses()->deleteRecurring((int) $form_state->getTriggeringElement()['#recurring_id']);
    \Drupal::messenger()->addStatus(t('Το πάγιο έξοδο διαγράφηκε.'));
    $form_state->setRedirectUrl(self::url());
  }

  /**
   * The settings page, scrolled to the fixed expenses.
   */
  protected static function url(array $query = []): Url {
    return Url::fromRoute('gg_members.settings', [], ['query' => $query, 'fragment' => 'recurring-expenses']);
  }

  protected static function expenses(): ExpenseManager {
    return \Drupal::service('gg_members.expenses');
  }

}
