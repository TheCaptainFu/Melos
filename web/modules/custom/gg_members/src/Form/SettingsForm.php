<?php

namespace Drupal\gg_members\Form;

use Drupal\Component\Utility\Html;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Render\Markup;
use Drupal\Core\Url;
use Drupal\gg_members\ExpenseManager;
use Drupal\gg_members\MemberManager;
use Drupal\gg_members\MemberReminders;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Module settings: the club's name, which calendar months aren't charged
 * (e.g. the summer when the academy is closed), globally and per class,
 * the payment reminder message, and the fixed monthly expenses.
 *
 * The fixed expenses have their own add/edit/delete buttons, which save
 * straight away and leave the rest of the settings alone.
 */
class SettingsForm extends ConfigFormBase {

  protected MemberManager $memberManager;

  protected ExpenseManager $expenses;

  public static function create(ContainerInterface $container) {
    $instance = parent::create($container);
    $instance->memberManager = $container->get('gg_members.manager');
    $instance->expenses = $container->get('gg_members.expenses');
    return $instance;
  }

  public function getFormId() {
    return 'gg_members_settings_form';
  }

  protected function getEditableConfigNames() {
    return ['gg_members.settings'];
  }

  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('gg_members.settings');
    $siteName = (string) $this->configFactory()->get('system.site')->get('name');

    $form['#attributes']['class'][] = 'gg-members-admin';
    $form['#attributes']['class'][] = 'gg-members-settings';
    $form['#attributes']['lang'] = 'el';
    $form['#attached']['library'][] = 'gg_members/admin';
    $form['#attached']['library'][] = 'gg_members/settings_form';

    // 1. The club.
    $form['club'] = $this->section($this->t('Ο σύλλογός σας'), $this->t('Το όνομα με το οποίο υπογράφονται τα μηνύματα προς τους γονείς και τα μέλη.'));
    $form['club']['club_name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Όνομα συλλόγου'),
      '#default_value' => $config->get('club_name'),
      '#placeholder' => $siteName,
      '#maxlength' => 128,
      '#attributes' => ['class' => ['gg-members-club-name']],
    ];


    // 2. Months without a fee.
    $form['general'] = $this->section($this->t('Μήνες που δεν πληρώνουν'), $this->t('Τσεκάρετε τους μήνες που το τμήμα είναι κλειστό, π.χ. Ιούλιο και Αύγουστο. Αυτοί οι μήνες δεν χρεώνονται και δεν φαίνονται ως οφειλή, ούτε στους προηγούμενους χρόνους.'));
    $form['general']['free_months'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Για όλες τις τάξεις'),
      '#title_display' => 'invisible',
      '#options' => MemberManager::MONTH_NAMES,
      '#default_value' => $config->get('free_months') ?? [],
      '#prefix' => '<div class="gg-members-month-chips">',
      '#suffix' => '</div>',
    ];

    $overrides = [];
    foreach ($config->get('class_free_months') ?? [] as $override) {
      $overrides[(int) $override['tid']] = $override['months'] ?? [];
    }
    $classes = $this->memberManager->getClasses();
    if ($classes) {
      // Rarely needed, so tucked away — open by itself when it's in use.
      $form['general']['classes'] = [
        '#type' => 'details',
        '#title' => $this->t('Κάποια τάξη έχει διαφορετικό πρόγραμμα;'),
        '#open' => (bool) $overrides,
        '#attributes' => ['class' => ['gg-members-settings-details']],
        '#tree' => TRUE,
        '#parents' => ['classes'],
      ];
      $form['general']['classes']['intro'] = [
        '#markup' => '<p class="gg-members-legend">' . $this->t('Ανάψτε τον διακόπτη της τάξης και διαλέξτε τους δικούς της μήνες. Οι υπόλοιπες τάξεις ακολουθούν τους μήνες από πάνω.') . '</p>',
      ];
      foreach ($classes as $tid => $term) {
        // One card per class: a toggle switch, and the class's own months
        // underneath while it's on.
        $form['general']['classes'][$tid] = [
          '#type' => 'container',
          '#attributes' => ['class' => ['gg-members-class-override']],
        ];
        $form['general']['classes'][$tid]['custom'] = [
          '#type' => 'checkbox',
          '#title' => $term->label(),
          '#default_value' => isset($overrides[$tid]),
          '#wrapper_attributes' => ['class' => ['gg-members-class-override__toggle']],
        ];
        $form['general']['classes'][$tid]['months'] = [
          '#type' => 'checkboxes',
          '#title' => $this->t('Μήνες χωρίς συνδρομή'),
          '#options' => MemberManager::MONTH_NAMES,
          '#default_value' => $overrides[$tid] ?? [],
          '#prefix' => '<div class="gg-members-month-chips">',
          '#suffix' => '</div>',
          '#states' => [
            'visible' => [':input[name="classes[' . $tid . '][custom]"]' => ['checked' => TRUE]],
          ],
        ];
      }
    }

    // 3. The reminder message, with one-click placeholders and a live
    // preview (js/settings-form.js).
    $form['reminders'] = $this->section($this->t('Μήνυμα υπενθύμισης'), $this->t('Το κείμενο που ανοίγει έτοιμο στο Viber, το SMS ή το WhatsApp όταν πατάτε «Υπενθύμιση» σε ένα μέλος που χρωστάει.'));
    $tokens = [
      '[όνομα]' => $this->t('Όνομα μέλους'),
      '[μήνες]' => $this->t('Μήνες'),
      '[ποσό]' => $this->t('Ποσό'),
      '[σύλλογος]' => $this->t('Σύλλογος'),
    ];
    $buttons = '';
    foreach ($tokens as $token => $label) {
      $buttons .= '<button type="button" class="gg-members-token" data-token="' . Html::escape($token) . '">+ ' . Html::escape($label) . '</button>';
    }
    $form['reminders']['tokens'] = [
      '#markup' => Markup::create('<div class="gg-members-tokens"><span class="gg-members-tokens__label">' . $this->t('Προσθέστε στο κείμενο:') . '</span>' . $buttons . '</div>'),
    ];
    $form['reminders']['reminder_template'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Κείμενο'),
      '#title_display' => 'invisible',
      '#default_value' => $config->get('reminder_template') ?: MemberReminders::DEFAULT_TEMPLATE,
      '#rows' => 4,
      '#attributes' => [
        'class' => ['gg-members-template'],
        'data-default' => MemberReminders::DEFAULT_TEMPLATE,
      ],
    ];
    $form['reminders']['preview'] = [
      '#markup' => Markup::create('<div class="gg-members-preview"'
        . ' data-name="' . Html::escape($this->t('Γιώργος Παπαδόπουλος')) . '"'
        . ' data-months="' . Html::escape(MemberReminders::monthsAccusative([date('Y-m', strtotime('first day of last month')), date('Y-m')])) . '"'
        . ' data-amount="70,00€"'
        . ' data-site-name="' . Html::escape($siteName) . '">'
        . '<div class="gg-members-preview__head"><span>' . $this->t('Έτσι θα το λάβει ο γονιός') . '</span>'
        . '<button type="button" class="gg-members-template-reset">' . $this->t('Επαναφορά αρχικού κειμένου') . '</button></div>'
        . '<div class="gg-members-preview__bubble"></div>'
        . '<div class="gg-members-preview__note">' . $this->t('Παράδειγμα με ένα φανταστικό μέλος· στην πράξη μπαίνουν τα στοιχεία κάθε μέλους.') . '</div>'
        . '</div>'),
    ];

    $this->buildRecurring($form);

    $form = parent::buildForm($form, $form_state);
    $form['actions']['#attributes']['class'][] = 'gg-members-settings-actions';
    $form['actions']['submit']['#value'] = $this->t('Αποθήκευση ρυθμίσεων');
    return $form;
  }

  /**
   * 4. Fixed monthly expenses: the same fields as a single expense, where
   * the date is the first one and its day repeats every month; the list of
   * them underneath. ?edit_recurring=ID loads one into the fields.
   */
  protected function buildRecurring(array &$form): void {
    $editId = (int) $this->getRequest()->query->get('edit_recurring');
    $editing = $editId ? $this->expenses->getRecurring($editId) : NULL;

    $form['recurring'] = $this->section($this->t('Πάγια έξοδα'), $this->t('Έξοδα που επαναλαμβάνονται κάθε μήνα, όπως το ενοίκιο και οι αμοιβές. Τα ορίζετε μία φορά και καταχωρούνται μόνα τους στα Έξοδα κάθε μήνα, την ίδια μέρα με την ημερομηνία που βάλατε.'));
    $form['recurring']['#attributes']['id'] = 'recurring-expenses';

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
      '#title' => $this->t('Ημερομηνία'),
      '#label_attributes' => $required,
      '#default_value' => $editing ? $editing->start_month . '-' . sprintf('%02d', $editing->day) : date('Y-m-d'),
    ];
    $form['recurring']['entry']['category'] = [
      '#type' => 'select',
      '#title' => $this->t('Κατηγορία'),
      '#label_attributes' => $required,
      '#options' => ExpenseManager::categoryOptions(),
      '#default_value' => $editing->category ?? 'rent',
    ];
    $form['recurring']['entry']['amount'] = [
      '#type' => 'number',
      '#title' => $this->t('Ποσό (€)'),
      '#label_attributes' => $required,
      '#step' => 0.01,
      '#min' => 0.01,
      '#default_value' => $editing->amount ?? '',
    ];
    $form['recurring']['entry']['description'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Περιγραφή'),
      '#maxlength' => 255,
      '#placeholder' => $this->t('π.χ. Ενοίκιο γηπέδου'),
      '#default_value' => $editing->description ?? '',
    ];
    $form['recurring']['buttons'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['gg-members-recurring-buttons']],
      'save' => [
        '#type' => 'submit',
        '#value' => $editing ? $this->t('Αποθήκευση πάγιου') : $this->t('Προσθήκη πάγιου'),
        '#name' => 'recurring_save',
        '#recurring_id' => $editing ? (int) $editing->id : NULL,
        '#limit_validation_errors' => [['recurring']],
        '#validate' => ['::validateRecurring'],
        '#submit' => ['::saveRecurring'],
      ],
    ];
    if ($editing) {
      $form['recurring']['buttons']['cancel'] = [
        '#type' => 'link',
        '#title' => $this->t('Ακύρωση'),
        '#url' => $this->recurringUrl(),
        '#attributes' => ['class' => ['button']],
      ];
    }

    $items = $this->expenses->allRecurring();
    if (!$items) {
      $form['recurring']['empty'] = ['#markup' => '<p class="gg-members-legend gg-members-recurring-empty">' . $this->t('Δεν έχετε ορίσει ακόμα πάγια έξοδα. Ξεκινήστε από το ενοίκιο και τις αμοιβές.') . '</p>'];
      return;
    }
    $monthly = array_sum(array_map(fn($item) => $item->active ? (float) $item->amount : 0, $items));
    $form['recurring']['list'] = [
      '#type' => 'table',
      '#attributes' => ['class' => ['gg-members-table', 'gg-members-recurring-table']],
      '#header' => [$this->t('Περιγραφή'), $this->t('Κατηγορία'), $this->t('Ποσό'), $this->t('Πότε'), ''],
      '#footer' => [[
        'data' => [
          ['data' => $this->t('Σύνολο ανά μήνα'), 'colspan' => 2],
          ['data' => Markup::create('<strong>' . MemberManager::formatMoney($monthly) . '€</strong>'), 'colspan' => 3],
        ],
      ]],
    ];
    foreach ($items as $item) {
      $id = (int) $item->id;
      [$label, $color] = ExpenseManager::CATEGORIES[$item->category] ?? ExpenseManager::CATEGORIES['other'];
      $when = $this->t('κάθε μήνα στις @day', ['@day' => $item->day])
        . '<span class="gg-members-cell-note">' . $this->t('από τον @month', ['@month' => MemberReminders::monthsAccusative([$item->start_month])])
        . ($item->only_open ? ' · ' . $this->t('εκτός κλειστών μηνών') : '')
        . (!$item->active ? ' · ' . $this->t('σε παύση') : '')
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
          '#title' => $this->t('Επεξεργασία'),
          '#url' => $this->recurringUrl(['edit_recurring' => $id]),
          '#attributes' => ['class' => ['gg-members-action--payments']],
        ],
        'delete' => [
          '#type' => 'submit',
          '#value' => $this->t('Διαγραφή'),
          '#name' => 'delete_recurring_' . $id,
          '#recurring_id' => $id,
          '#limit_validation_errors' => [],
          '#validate' => [],
          '#submit' => ['::deleteRecurring'],
          '#attributes' => [
            'class' => ['gg-members-expense-delete'],
            'onclick' => 'return confirm(' . json_encode((string) $this->t('Διαγραφή αυτού του πάγιου εξόδου; Όσα έχουν ήδη καταχωρηθεί στα Έξοδα θα μείνουν.')) . ');',
          ],
        ],
      ];
    }
  }

  public function validateRecurring(array &$form, FormStateInterface $form_state) {
    $values = (array) $form_state->getValue('recurring');
    if (empty($values['start_date'])) {
      $form_state->setErrorByName('recurring][start_date', $this->t('Βάλτε την ημερομηνία της πρώτης χρέωσης.'));
    }
    if ((float) ($values['amount'] ?? 0) <= 0) {
      $form_state->setErrorByName('recurring][amount', $this->t('Βάλτε το ποσό του πάγιου εξόδου.'));
    }
  }

  public function saveRecurring(array &$form, FormStateInterface $form_state) {
    $values = (array) $form_state->getValue('recurring');
    $id = $form_state->getTriggeringElement()['#recurring_id'];
    $category = $values['category'] ?? 'other';
    $this->expenses->saveRecurring([
      'start_date' => $values['start_date'],
      'category' => $category,
      'amount' => $values['amount'],
      'description' => trim((string) ($values['description'] ?? '')) ?: ExpenseManager::categoryOptions()[$category] ?? '',
    ], $id ? (int) $id : NULL);
    $this->messenger()->addStatus($id ? $this->t('Το πάγιο έξοδο αποθηκεύτηκε.') : $this->t('Το πάγιο έξοδο προστέθηκε· θα καταχωρείται αυτόματα κάθε μήνα.'));
    $form_state->setRedirectUrl($this->recurringUrl());
  }

  public function deleteRecurring(array &$form, FormStateInterface $form_state) {
    $this->expenses->deleteRecurring((int) $form_state->getTriggeringElement()['#recurring_id']);
    $this->messenger()->addStatus($this->t('Το πάγιο έξοδο διαγράφηκε.'));
    $form_state->setRedirectUrl($this->recurringUrl());
  }

  /**
   * This page, scrolled to the fixed expenses.
   */
  protected function recurringUrl(array $query = []): Url {
    return Url::fromRoute('gg_members.settings', [], ['query' => $query, 'fragment' => 'recurring-expenses']);
  }

  /**
   * A settings section: a card with a title and one plain sentence on what
   * it's for.
   */
  protected function section($title, $text): array {
    return [
      '#type' => 'container',
      '#attributes' => ['class' => ['gg-members-card', 'gg-members-settings-section']],
      'header' => [
        '#markup' => Markup::create('<div class="gg-members-settings-section__header">'
          . '<h2>' . Html::escape($title) . '</h2><p>' . Html::escape($text) . '</p></div>'),
      ],
    ];
  }

  public function submitForm(array &$form, FormStateInterface $form_state) {
    $overrides = [];
    foreach ((array) $form_state->getValue('classes') as $tid => $values) {
      if (!empty($values['custom'])) {
        $overrides[] = [
          'tid' => (int) $tid,
          'months' => self::checkedMonths($values['months'] ?? []),
        ];
      }
    }

    $this->config('gg_members.settings')
      ->set('free_months', self::checkedMonths($form_state->getValue('free_months')))
      ->set('class_free_months', $overrides)
      ->set('club_name', trim((string) $form_state->getValue('club_name')))
      // The built-in text is stored as "use the default", so a later
      // improvement to it reaches sites that never changed it.
      ->set('reminder_template', trim((string) $form_state->getValue('reminder_template')) === MemberReminders::DEFAULT_TEMPLATE ? '' : trim((string) $form_state->getValue('reminder_template')))
      ->save();

    $this->messenger()->addStatus($this->t('Οι ρυθμίσεις αποθηκεύτηκαν.'));
  }

  /**
   * The ticked months of a month checkboxes value, as sorted integers.
   */
  protected static function checkedMonths($value): array {
    $months = array_map('intval', array_keys(array_filter((array) $value)));
    sort($months);
    return $months;
  }

}
