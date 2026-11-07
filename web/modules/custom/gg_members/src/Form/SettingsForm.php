<?php

namespace Drupal\gg_members\Form;

use Drupal\Component\Utility\Html;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Render\Markup;
use Drupal\Core\Url;
use Drupal\gg_members\MemberManager;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Module settings: which calendar months aren't charged (e.g. the summer
 * when the academy is closed), globally and per class. Other modules add
 * their own sections (e.g. the fixed expenses, gg_members_expenses).
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
    $form['#attributes']['class'][] = 'gg-members-settings';
    $form['#attributes']['lang'] = 'el';
    $form['#attached']['library'][] = 'gg_members/admin';

    // 1. Months without a fee.
    $form['general'] = self::section($this->t('Μήνες που δεν πληρώνουν'), $this->t('Τσεκάρετε τους μήνες που το τμήμα είναι κλειστό, π.χ. Ιούλιο και Αύγουστο. Αυτοί οι μήνες δεν χρεώνονται και δεν φαίνονται ως οφειλή, ούτε στους προηγούμενους χρόνους.'));
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

    $form = parent::buildForm($form, $form_state);
    $form['actions']['#attributes']['class'][] = 'gg-members-settings-actions';
    $form['actions']['submit']['#value'] = $this->t('Αποθήκευση ρυθμίσεων');
    return $form;
  }

  /**
   * A settings section: a card with a title and one plain sentence on what
   * it's for. Public, for the sections other modules add.
   */
  public static function section($title, $text): array {
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
