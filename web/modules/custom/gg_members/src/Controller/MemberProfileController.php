<?php

namespace Drupal\gg_members\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Drupal\gg_members\MemberManager;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Read-only profile page for a single member.
 */
class MemberProfileController extends ControllerBase {

  protected const DOCUMENT_CATEGORIES = [
    'doctor_note' => 'Χαρτί γιατρού',
    'photo' => 'Φωτογραφία',
    'birth_certificate' => 'Πιστοποιητικό γέννησης',
    'card' => 'Δελτίο',
  ];

  public function __construct(protected MemberManager $memberManager) {}

  public static function create(ContainerInterface $container) {
    return new static($container->get('gg_members.manager'));
  }

  public function title(int $member): string {
    $record = $this->memberManager->getMember($member);
    return $record ? $record->name : (string) $this->t('Μέλος');
  }

  public function view(int $member): array {
    $record = $this->memberManager->getMember($member);
    if (!$record) {
      throw new NotFoundHttpException();
    }

    $fileStorage = $this->entityTypeManager()->getStorage('file');
    $documents = [];
    $photoUrl = NULL;
    foreach (self::DOCUMENT_CATEGORIES as $category => $label) {
      $files = [];
      $fids = array_map(fn(\stdClass $row) => (int) $row->fid, $this->memberManager->getMemberFiles($member, $category));
      foreach ($fileStorage->loadMultiple($fids) as $file) {
        $files[] = [
          'name' => $file->getFilename(),
          'url' => $file->createFileUrl(),
        ];
      }
      $documents[] = ['label' => $label, 'files' => $files];
      if ($category === 'photo' && $files) {
        $photoUrl = end($files)['url'];
      }
    }

    $back = MemberManager::backFromRequest();
    $openMonths = MemberManager::openMonths($this->memberManager->getLedger($member));
    $stats = $this->memberManager->getMemberStats($member);

    return [
      '#theme' => 'gg_members_profile',
      '#member' => [
        'name' => $record->name,
        'class_name' => $this->className((int) $record->class_tid),
        'status' => (bool) $record->status,
        'age' => $this->age($record->birth_date),
        'birth_date' => $this->formatDate($record->birth_date),
        'enrollment_date' => $this->formatDate($record->enrollment_date),
        'left_date' => $record->status ? NULL : $this->formatDate($record->left_date),
        'monthly_fee' => number_format((float) $record->monthly_fee, 2, ',', '.'),
        'phone' => $record->phone,
        'email' => $record->email,
        'card_number' => $record->card_number,
        'amka' => $record->amka,
        'guardian_name' => $record->guardian_name,
        'guardian_phone' => $record->guardian_phone,
        'notes' => $record->notes,
        'photo_url' => $photoUrl,
      ],
      '#finance' => [
        'unpaid_months' => array_map([MemberManager::class, 'formatLedgerMonth'], array_keys($openMonths), $openMonths),
        'outstanding' => MemberManager::formatMoney(MemberManager::outstanding($openMonths)),
        'total_paid' => number_format($stats['total_paid'], 2, ',', '.'),
        'payment_count' => $stats['payment_count'],
        'last_payment_date' => $this->formatDate($stats['last_payment_date']),
      ],
      '#documents' => $documents,
      '#urls' => [
        'edit' => MemberManager::withBack(Url::fromRoute('gg_members.member_edit', ['member' => $member]), $back),
        'payments' => MemberManager::withBack(Url::fromRoute('gg_members.payments', ['member' => $member]), $back),
        'delete' => MemberManager::withBack(Url::fromRoute('gg_members.member_delete', ['member' => $member]), $back),
        'reminder' => MemberManager::withBack(Url::fromRoute('gg_members.reminder', ['member' => $member]), $back),
        'back' => MemberManager::listUrl($back),
      ],
      '#attached' => ['library' => ['gg_members/admin', 'core/drupal.dialog.ajax']],
      '#cache' => ['max-age' => 0],
    ];
  }

  protected function className(int $tid): ?string {
    $term = $tid ? $this->entityTypeManager()->getStorage('taxonomy_term')->load($tid) : NULL;
    return $term ? $term->label() : NULL;
  }

  protected function formatDate(?string $date): ?string {
    $parsed = $date ? \DateTimeImmutable::createFromFormat('Y-m-d', $date) : FALSE;
    return $parsed ? $parsed->format('d/m/Y') : NULL;
  }

  protected function age(?string $birthDate): ?int {
    $parsed = $birthDate ? \DateTimeImmutable::createFromFormat('Y-m-d', $birthDate) : FALSE;
    return $parsed ? $parsed->diff(new \DateTimeImmutable('today'))->y : NULL;
  }

}
