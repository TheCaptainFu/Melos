<?php

namespace Drupal\gg_members\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\gg_members\MemberManager;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Billing settings: which calendar months aren't charged (e.g. the summer
 * when the academy is closed), globally and per class.
 */
class SettingsForm extends ConfigFormBase {

  protected MemberManager $memberManager;

  public static function create(ContainerInterface $container) {
    $instance = parent::create($container);
    $instance->memberManager = $container->get('gg_members.manager');
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

    $form['#attributes']['class'][] = 'gg-members-admin';
    $form['#attached']['library'][] = 'gg_members/admin';

    $form['back'] = MemberManager::backLinks();

    $form['general'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Μήνες χωρίς συνδρομή'),
      '#attributes' => ['class' => ['gg-members-fieldset']],
    ];
    $form['general']['free_months'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Για όλες τις τάξεις'),
      '#description' => $this->t('Π.χ. Ιούλιος και Αύγουστος, αν το τμήμα κλείνει το καλοκαίρι. Αυτοί οι μήνες δεν χρεώνονται και δεν εμφανίζονται ως οφειλή. Η αλλαγή ισχύει και για τους προηγούμενους μήνες.'),
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
      $form['classes'] = [
        '#type' => 'fieldset',
        '#title' => $this->t('Διαφορετικοί μήνες ανά τάξη'),
        '#attributes' => ['class' => ['gg-members-fieldset']],
        '#tree' => TRUE,
      ];
      $form['classes']['intro'] = [
        '#markup' => '<p class="gg-members-legend">' . $this->t('Μόνο αν κάποια τάξη έχει άλλο πρόγραμμα από τις υπόλοιπες. Άναψε τον διακόπτη και διάλεξε τους δικούς της μήνες· οι υπόλοιπες τάξεις ακολουθούν τους μήνες από πάνω.') . '</p>',
      ];
      foreach ($classes as $tid => $term) {
        // One card per class: a toggle switch, and the class's own months
        // underneath while it's on.
        $form['classes'][$tid] = [
          '#type' => 'container',
          '#attributes' => ['class' => ['gg-members-class-override']],
        ];
        $form['classes'][$tid]['custom'] = [
          '#type' => 'checkbox',
          '#title' => $term->label(),
          '#default_value' => isset($overrides[$tid]),
          '#wrapper_attributes' => ['class' => ['gg-members-class-override__toggle']],
        ];
        $form['classes'][$tid]['months'] = [
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

    $form = parent::buildForm($form, $form_state);
    $form['actions']['submit']['#value'] = $this->t('Αποθήκευση');
    return $form;
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
