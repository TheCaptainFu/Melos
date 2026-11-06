<?php

namespace Drupal\gg_members_attendance\Form;

use Drupal\Component\Utility\Html;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Render\Markup;
use Drupal\Core\Url;
use Drupal\gg_members\MemberManager;
use Drupal\gg_members_attendance\Attendance;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * The "Παρουσίες" tab: who came on a day (today unless ?date= says
 * otherwise) and when; add someone who forgot their card, remove a
 * mistaken scan.
 */
class DayForm extends FormBase {

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

  public function getFormId() {
    return 'gg_members_attendance_day_form';
  }

  public function buildForm(array $form, FormStateInterface $form_state) {
    $date = $this->getRequest()->query->get('date');
    $today = date('Y-m-d');
    if (!is_string($date) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || $date > $today) {
      $date = $today;
    }
    $rows = $this->attendance->day($date);

    $form['#attributes']['class'][] = 'gg-members-admin';
    $form['#attributes']['class'][] = 'gg-attendance-day';
    $form['#attributes']['lang'] = 'el';
    $form['#attached']['library'][] = 'gg_members/admin';
    $form['#attached']['library'][] = 'gg_members_attendance/admin';
    $form['#cache']['max-age'] = 0;
    $form['date'] = ['#type' => 'value', '#value' => $date];

    $form['head'] = ['#markup' => Markup::create($this->head($date, $today, count($rows)))];

    // Someone who came without their card.
    $present = array_column($rows, 'member_id');
    $options = [];
    foreach ($this->memberManager->membersQuery(['status' => 1])->execute() as $member) {
      if (!in_array((int) $member->id, $present, TRUE)) {
        $options[(int) $member->id] = $member->name . ($member->class_name ? ' — ' . $member->class_name : '');
      }
    }
    $form['add'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['gg-members-card', 'gg-attendance-day__add']],
    ];
    $form['add']['member'] = [
      '#type' => 'select',
      '#title' => $this->t('Ήρθε χωρίς κάρτα;'),
      '#options' => $options,
      '#empty_option' => $this->t('- Επιλέξτε μέλος -'),
    ];
    $form['add']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Προσθήκη'),
    ];

    $form['list'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['gg-members-card', 'gg-attendance-day__list']],
    ];
    if (!$rows) {
      $form['list']['empty'] = ['#markup' => '<p class="gg-members-legend">' . $this->t('Δεν έχει καταγραφεί κανείς αυτή τη μέρα.') . '</p>'];
      return $form;
    }
    $form['list']['table'] = [
      '#type' => 'table',
      '#attributes' => ['class' => ['gg-members-table']],
      '#header' => [$this->t('Ώρα'), $this->t('Μέλος'), $this->t('Τάξη'), $this->t('Τρόπος'), ''],
    ];
    foreach ($rows as $row) {
      $id = $row['id'];
      $form['list']['table'][$id] = [
        'time' => ['#markup' => '<strong>' . $row['time'] . '</strong>'],
        'name' => [
          '#type' => 'link',
          '#title' => $row['name'],
          '#url' => Url::fromRoute('gg_members.member_view', ['member' => $row['member_id']]),
          '#attributes' => ['class' => ['gg-members-name-link']],
        ],
        'class' => ['#markup' => $row['class_name'] ? Html::escape($row['class_name']) : '—'],
        'method' => ['#markup' => $row['method'] === 'manual' ? $this->t('Χειροκίνητα') : $this->t('QR')],
        'delete' => [
          '#type' => 'submit',
          '#value' => $this->t('Αφαίρεση'),
          '#name' => 'remove_' . $id,
          '#attendance_id' => $id,
          '#limit_validation_errors' => [['date']],
          '#validate' => [],
          '#submit' => ['::remove'],
          '#attributes' => [
            'class' => ['gg-members-expense-delete'],
            'onclick' => 'return confirm(' . json_encode((string) $this->t('Αφαίρεση από τις παρουσίες της μέρας;')) . ');',
          ],
        ],
      ];
    }
    return $form;
  }

  /**
   * ‹ day › and how many came, with the scanner button.
   */
  protected function head(string $date, string $today, int $count): string {
    $day = new \DateTimeImmutable($date);
    $previous = $day->modify('-1 day')->format('Y-m-d');
    $next = $day->modify('+1 day')->format('Y-m-d');
    $weekdays = ['Κυριακή', 'Δευτέρα', 'Τρίτη', 'Τετάρτη', 'Πέμπτη', 'Παρασκευή', 'Σάββατο'];
    $label = $date === $today
      ? $this->t('Σήμερα, @date', ['@date' => $day->format('d/m/Y')])
      : $weekdays[(int) $day->format('w')] . ', ' . $day->format('d/m/Y');
    $link = fn(string $d) => Url::fromRoute('gg_members_attendance.today', [], $d === $today ? [] : ['query' => ['date' => $d]])->toString();

    return '<div class="gg-attendance-day__head">'
      . '<div class="gg-members-month-nav">'
      . '<a class="gg-members-month-nav__arrow" href="' . $link($previous) . '" aria-label="' . $this->t('Προηγούμενη μέρα') . '">‹</a>'
      . '<h2 class="gg-members-month-nav__label">' . Html::escape((string) $label) . '</h2>'
      . ($date < $today
        ? '<a class="gg-members-month-nav__arrow" href="' . $link($next) . '" aria-label="' . $this->t('Επόμενη μέρα') . '">›</a>'
          . '<a class="gg-members-month-nav__today" href="' . $link($today) . '">' . $this->t('Σήμερα') . '</a>'
        : '<span class="gg-members-month-nav__arrow is-disabled" aria-hidden="true">›</span>')
      . '</div>'
      . '<a class="button button--primary gg-attendance-day__scan" href="' . Url::fromRoute('gg_members_attendance.scan')->toString() . '" target="_blank">' . $this->t('Άνοιγμα σαρωτή') . '</a>'
      . '</div>'
      . '<div class="gg-members-stats"><div class="gg-members-stat">'
      . '<span class="gg-members-stat__value">' . $count . '</span>'
      . '<span class="gg-members-stat__label">' . ($date === $today ? $this->t('Ήρθαν σήμερα') : $this->t('Ήρθαν')) . '</span>'
      . '</div></div>';
  }

  public function validateForm(array &$form, FormStateInterface $form_state) {
    if (!$form_state->getValue('member')) {
      $form_state->setErrorByName('member', $this->t('Επιλέξτε το μέλος που ήρθε.'));
    }
  }

  public function submitForm(array &$form, FormStateInterface $form_state) {
    $date = $form_state->getValue('date');
    $this->attendance->record((int) $form_state->getValue('member'), 'manual', $date);
    $this->messenger()->addStatus($this->t('Προστέθηκε στις παρουσίες.'));
    $form_state->setRedirectUrl($this->dayUrl($date));
  }

  public function remove(array &$form, FormStateInterface $form_state) {
    $this->attendance->delete((int) $form_state->getTriggeringElement()['#attendance_id']);
    $this->messenger()->addStatus($this->t('Αφαιρέθηκε από τις παρουσίες.'));
    $form_state->setRedirectUrl($this->dayUrl($form_state->getValue('date')));
  }

  protected function dayUrl(string $date): Url {
    return Url::fromRoute('gg_members_attendance.today', [], $date === date('Y-m-d') ? [] : ['query' => ['date' => $date]]);
  }

}
