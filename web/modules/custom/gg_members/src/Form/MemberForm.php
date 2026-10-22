<?php

namespace Drupal\gg_members\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\gg_members\MemberManager;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Add/edit form for a single member.
 */
class MemberForm extends FormBase {

  protected ?\stdClass $member = NULL;

  public function __construct(
    protected MemberManager $memberManager,
  ) {}

  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('gg_members.manager'),
    );
  }

  public function getFormId() {
    return 'gg_members_member_form';
  }

  public function buildForm(array $form, FormStateInterface $form_state, ?int $member = NULL) {
    $this->member = $member ? $this->memberManager->getMember($member) : NULL;
    if ($member && !$this->member) {
      throw new \Symfony\Component\HttpKernel\Exception\NotFoundHttpException();
    }

    $form['#attributes']['class'][] = 'gg-members-admin';
    $form['#attached']['library'][] = 'gg_members/admin';
    $form['#attached']['library'][] = 'gg_members/file_delete';

    $form['back'] = MemberManager::backLinks($member);

    $form['id'] = ['#type' => 'value', '#value' => $member];

    $classOptions = [];
    foreach ($this->memberManager->getClasses() as $term) {
      $classOptions[$term->id()] = $term->label();
    }

    $form['personal'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Προσωπικά στοιχεία'),
      '#attributes' => ['class' => ['gg-members-fieldset']],
    ];

    $form['personal']['name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Ονοματεπώνυμο'),
      '#required' => TRUE,
      '#maxlength' => 255,
      '#default_value' => $this->member->name ?? '',
    ];

    $form['personal']['birth_date'] = [
      '#type' => 'date',
      '#title' => $this->t('Ημερομηνία γέννησης'),
      '#default_value' => $this->member->birth_date ?? '',
      '#attributes' => ['max' => date('Y-m-d')],
    ];

    $form['personal']['card_number'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Αριθμός δελτίου'),
      '#maxlength' => 32,
      '#size' => 15,
      '#default_value' => $this->member->card_number ?? '',
      '#attributes' => ['autocomplete' => 'off'],
    ];

    $form['personal']['amka'] = [
      '#type' => 'textfield',
      '#title' => $this->t('ΑΜΚΑ'),
      '#maxlength' => 11,
      '#size' => 15,
      '#default_value' => $this->member->amka ?? '',
      '#attributes' => ['inputmode' => 'numeric', 'pattern' => '[0-9]{11}', 'autocomplete' => 'off'],
    ];

    $form['subscription'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Συνδρομή'),
      '#attributes' => ['class' => ['gg-members-fieldset']],
    ];

    $form['subscription']['class_tid'] = [
      '#type' => 'select',
      '#title' => $this->t('Τάξη'),
      '#options' => $classOptions,
      '#empty_option' => $this->t('- Επίλεξε τάξη -'),
      '#required' => TRUE,
      '#default_value' => $this->member->class_tid ?? NULL,
    ];
    if (!$classOptions) {
      $form['subscription']['class_tid']['#description'] = $this->t('Δεν υπάρχουν ακόμα τάξεις. <a href=":url">Πρόσθεσε μία πρώτα</a>.', [
        ':url' => \Drupal\Core\Url::fromRoute('entity.taxonomy_vocabulary.overview_form', ['taxonomy_vocabulary' => 'gg_members_class'])->toString(),
      ]);
    }

    $form['subscription']['monthly_fee'] = [
      '#type' => 'number',
      '#title' => $this->t('Μηνιαία συνδρομή (€)'),
      '#step' => 0.01,
      '#min' => 0,
      '#required' => TRUE,
      '#default_value' => $this->member->monthly_fee ?? '',
    ];

    if ($this->member) {
      $form['subscription']['fee_from'] = [
        '#type' => 'date',
        '#title' => $this->t('Η νέα συνδρομή ισχύει από'),
        '#description' => $this->t('Μετράει μόνο ο μήνας. Χρησιμοποιείται μόνο αν αλλάξεις το ποσό· οι προηγούμενοι μήνες χρεώνονται με τη συνδρομή που ίσχυε τότε.'),
        '#default_value' => (new \DateTimeImmutable('first day of this month'))->format('Y-m-d'),
      ];
    }

    $form['subscription']['enrollment_date'] = [
      '#type' => 'date',
      '#title' => $this->t('Ημερομηνία εγγραφής'),
      '#default_value' => $this->member->enrollment_date ?? '',
    ];

    $form['subscription']['status'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Ενεργό μέλος'),
      '#default_value' => $this->member ? (bool) $this->member->status : TRUE,
    ];

    $form['subscription']['left_date'] = [
      '#type' => 'date',
      '#title' => $this->t('Ημερομηνία αποχώρησης'),
      '#description' => $this->t('Ο μήνας αποχώρησης χρεώνεται κανονικά· από τον επόμενο μήνα και μετά δεν χρεώνονται συνδρομές. Αν μείνει κενό, μπαίνει η σημερινή ημερομηνία.'),
      '#default_value' => $this->member->left_date ?? '',
      '#states' => [
        'visible' => [':input[name="status"]' => ['checked' => FALSE]],
      ],
    ];

    $form['contact'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Στοιχεία επικοινωνίας'),
      '#attributes' => ['class' => ['gg-members-fieldset']],
    ];
    $form['contact']['phone'] = [
      '#type' => 'tel',
      '#title' => $this->t('Τηλέφωνο'),
      '#default_value' => $this->member->phone ?? '',
    ];
    $form['contact']['email'] = [
      '#type' => 'email',
      '#title' => $this->t('Email'),
      '#default_value' => $this->member->email ?? '',
    ];

    $form['guardian'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Στοιχεία κηδεμόνα (για ανήλικους)'),
      '#attributes' => ['class' => ['gg-members-fieldset']],
    ];
    $form['guardian']['guardian_name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Ονοματεπώνυμο κηδεμόνα'),
      '#maxlength' => 255,
      '#default_value' => $this->member->guardian_name ?? '',
    ];
    $form['guardian']['guardian_phone'] = [
      '#type' => 'tel',
      '#title' => $this->t('Τηλέφωνο κηδεμόνα'),
      '#default_value' => $this->member->guardian_phone ?? '',
    ];

    $form['medical'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Έγγραφα'),
      '#attributes' => ['class' => ['gg-members-fieldset']],
    ];
    $form['medical']['doctor_note_fids'] = [
      '#type' => 'managed_file',
      '#title' => $this->t('Χαρτί γιατρού'),
      '#multiple' => TRUE,
      '#upload_location' => 'private://gg_members/doctor_notes',
      '#upload_validators' => [
        'FileExtension' => ['extensions' => 'pdf jpg jpeg png'],
        'FileSizeLimit' => ['fileLimit' => 5 * 1024 * 1024],
      ],
      '#default_value' => $this->memberFileFids('doctor_note'),
      '#after_build' => [[static::class, 'translateFileButtons']],
    ];
    $form['medical']['photo_fids'] = [
      '#type' => 'managed_file',
      '#title' => $this->t('Φωτογραφία'),
      '#multiple' => TRUE,
      '#upload_location' => 'private://gg_members/photos',
      '#upload_validators' => [
        'FileExtension' => ['extensions' => 'jpg jpeg png'],
        'FileSizeLimit' => ['fileLimit' => 5 * 1024 * 1024],
      ],
      '#default_value' => $this->memberFileFids('photo'),
      '#after_build' => [[static::class, 'translateFileButtons']],
    ];
    $form['medical']['birth_certificate_fids'] = [
      '#type' => 'managed_file',
      '#title' => $this->t('Πιστοποιητικό γέννησης'),
      '#multiple' => TRUE,
      '#upload_location' => 'private://gg_members/birth_certificates',
      '#upload_validators' => [
        'FileExtension' => ['extensions' => 'pdf jpg jpeg png'],
        'FileSizeLimit' => ['fileLimit' => 5 * 1024 * 1024],
      ],
      '#default_value' => $this->memberFileFids('birth_certificate'),
      '#after_build' => [[static::class, 'translateFileButtons']],
    ];
    $form['medical']['card_fids'] = [
      '#type' => 'managed_file',
      '#title' => $this->t('Δελτίο'),
      '#multiple' => TRUE,
      '#upload_location' => 'private://gg_members/cards',
      '#upload_validators' => [
        'FileExtension' => ['extensions' => 'pdf jpg jpeg png'],
        'FileSizeLimit' => ['fileLimit' => 5 * 1024 * 1024],
      ],
      '#default_value' => $this->memberFileFids('card'),
      '#after_build' => [[static::class, 'translateFileButtons']],
    ];

    $form['notes'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Σημειώσεις'),
      '#rows' => 3,
      '#default_value' => $this->member->notes ?? '',
    ];

    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->member ? $this->t('Αποθήκευση αλλαγών') : $this->t('Προσθήκη μέλους'),
    ];

    return $form;
  }

  /**
   * #after_build callback for the managed_file fields: translates core's
   * "Remove selected"/"Upload" button text to Greek. Has to run via
   * #after_build (not buildForm() directly, not hook_form_alter — that runs
   * *before* #process builds these sub-elements) since remove_button/
   * upload_button don't exist until managed_file's own #process has run.
   */
  public static function translateFileButtons(array $element, FormStateInterface $form_state): array {
    if (isset($element['remove_button'])) {
      $element['remove_button']['#value'] = t('Αφαίρεση επιλεγμένων');
    }
    if (isset($element['upload_button'])) {
      $element['upload_button']['#value'] = t('Μεταφόρτωση');
    }
    return $element;
  }

  /**
   * Fids of the current member's already-uploaded files in one category,
   * for the managed_file widget's #default_value.
   */
  protected function memberFileFids(string $category): array {
    if (!$this->member) {
      return [];
    }
    return array_map(
      fn(\stdClass $row) => (int) $row->fid,
      $this->memberManager->getMemberFiles((int) $this->member->id, $category)
    );
  }

  public function validateForm(array &$form, FormStateInterface $form_state) {
    $birthDate = $form_state->getValue('birth_date');
    if ($birthDate && $birthDate > date('Y-m-d')) {
      $form_state->setErrorByName('birth_date', $this->t('Η ημερομηνία γέννησης δεν μπορεί να είναι στο μέλλον.'));
    }

    $amka = trim((string) $form_state->getValue('amka'));
    if ($amka !== '') {
      if (!preg_match('/^\d{11}$/', $amka)) {
        $form_state->setErrorByName('amka', $this->t('Ο ΑΜΚΑ πρέπει να έχει ακριβώς 11 ψηφία.'));
      }
      elseif (!self::isValidAmka($amka)) {
        $form_state->setErrorByName('amka', $this->t('Ο ΑΜΚΑ δεν είναι έγκυρος. Έλεγξε μήπως έχει γραφτεί λάθος κάποιο ψηφίο.'));
      }
    }
  }

  /**
   * ΑΜΚΑ numbers end in a Luhn check digit, so this catches most typos (a
   * wrong or swapped digit) that a plain 11-digit check would let through.
   */
  protected static function isValidAmka(string $amka): bool {
    $sum = 0;
    foreach (array_reverse(str_split($amka)) as $i => $digit) {
      $digit = (int) $digit;
      if ($i % 2 === 1) {
        $digit *= 2;
        if ($digit > 9) {
          $digit -= 9;
        }
      }
      $sum += $digit;
    }
    return $sum % 10 === 0;
  }

  public function submitForm(array &$form, FormStateInterface $form_state) {
    $data = [
      'name' => trim((string) $form_state->getValue('name')),
      'class_tid' => $form_state->getValue('class_tid'),
      'monthly_fee' => $form_state->getValue('monthly_fee'),
      'phone' => trim((string) $form_state->getValue('phone')),
      'email' => trim((string) $form_state->getValue('email')),
      'guardian_name' => trim((string) $form_state->getValue('guardian_name')),
      'guardian_phone' => trim((string) $form_state->getValue('guardian_phone')),
      'enrollment_date' => $form_state->getValue('enrollment_date'),
      'birth_date' => $form_state->getValue('birth_date'),
      'card_number' => trim((string) $form_state->getValue('card_number')),
      'amka' => trim((string) $form_state->getValue('amka')),
      'status' => $form_state->getValue('status'),
      'left_date' => $form_state->getValue('status')
        ? NULL
        : ($form_state->getValue('left_date') ?: date('Y-m-d')),
      'notes' => trim((string) $form_state->getValue('notes')),
    ];

    $id = $form_state->getValue('id');
    if ($id) {
      $feeFrom = $form_state->getValue('fee_from');
      $this->memberManager->updateMember((int) $id, $data, $feeFrom ? substr($feeFrom, 0, 7) : NULL);
      $this->messenger()->addStatus($this->t('Οι αλλαγές αποθηκεύτηκαν.'));
    }
    else {
      $id = $this->memberManager->createMember($data);
      $this->messenger()->addStatus($this->t('Το μέλος προστέθηκε.'));
    }

    foreach ([
      'doctor_note' => 'doctor_note_fids',
      'photo' => 'photo_fids',
      'birth_certificate' => 'birth_certificate_fids',
      'card' => 'card_fids',
    ] as $category => $fieldName) {
      // managed_file's own value structure is ['fids' => [...]], but its
      // #validate callback (ManagedFile::validateManagedFile(), since
      // #extended defaults to FALSE) collapses that down to a flat array of
      // fids in $form_state by the time submitForm() runs — so this must
      // read the field's value directly, not a nested ['fids'] key.
      $fids = (array) $form_state->getValue($fieldName);
      $this->memberManager->syncMemberFiles((int) $id, $category, $fids);
    }

    $form_state->setRedirectUrl(MemberManager::listUrl(MemberManager::backFromRequest()));
  }

}
