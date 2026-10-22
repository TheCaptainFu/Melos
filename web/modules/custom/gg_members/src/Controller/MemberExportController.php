<?php

namespace Drupal\gg_members\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\gg_members\MemberManager;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Streams the full member roster as a CSV file - opens directly in Excel,
 * with Greek headers and a UTF-8 BOM so the Greek text renders correctly
 * instead of as mojibake.
 */
class MemberExportController extends ControllerBase {

  public function __construct(protected MemberManager $memberManager) {}

  public static function create(ContainerInterface $container) {
    return new static($container->get('gg_members.manager'));
  }

  public function export(): StreamedResponse {
    $memberManager = $this->memberManager;

    $response = new StreamedResponse(function () use ($memberManager) {
      $handle = fopen('php://output', 'w');
      fwrite($handle, "\xEF\xBB\xBF");

      fputcsv($handle, [
        'Ονοματεπώνυμο',
        'Ημερομηνία γέννησης',
        'Αριθμός δελτίου',
        'ΑΜΚΑ',
        'Τάξη',
        'Μηνιαία συνδρομή (€)',
        'Τηλέφωνο',
        'Email',
        'Ημερομηνία εγγραφής',
        'Κηδεμόνας',
        'Τηλέφωνο κηδεμόνα',
        'Κατάσταση',
        'Απλήρωτοι μήνες',
        'Οφειλή (€)',
        'Χαρτί γιατρού',
        'Φωτογραφία',
        'Πιστοποιητικό γέννησης',
        'Δελτίο',
        'Σημειώσεις',
      ], ';');

      $paidByMember = $memberManager->getPaidAmountsByMember();
      $feesByMember = $memberManager->getFeeHistoryByMember();
      $filesByMember = $memberManager->getFileCategoriesByMember();

      foreach ($memberManager->membersQuery()->execute() as $record) {
        $memberId = (int) $record->id;
        $ledger = $memberManager->computeLedger($record, $paidByMember[$memberId] ?? [], $feesByMember[$memberId] ?? []);
        $openMonths = MemberManager::openMonths($ledger);
        $enrollment = $record->enrollment_date ? \DateTimeImmutable::createFromFormat('Y-m-d', $record->enrollment_date) : FALSE;
        $birth = $record->birth_date ? \DateTimeImmutable::createFromFormat('Y-m-d', $record->birth_date) : FALSE;

        fputcsv($handle, [
          $record->name,
          $birth ? $birth->format('d/m/Y') : $record->birth_date,
          // Text, like ΑΜΚΑ, so leading zeros survive.
          $record->card_number ? '="' . $record->card_number . '"' : '',
          // Prefixed so Excel keeps it as text (no leading-zero loss / 1E+10).
          $record->amka ? '="' . $record->amka . '"' : '',
          $record->class_name,
          number_format((float) $record->monthly_fee, 2, ',', ''),
          $record->phone,
          $record->email,
          $enrollment ? $enrollment->format('d/m/Y') : $record->enrollment_date,
          $record->guardian_name,
          $record->guardian_phone,
          $record->status ? 'Ενεργό' : 'Ανενεργό',
          $openMonths ? implode(', ', array_map([MemberManager::class, 'formatLedgerMonth'], array_keys($openMonths), $openMonths)) : '-',
          number_format(MemberManager::outstanding($ledger), 2, ',', ''),
          isset($filesByMember[$memberId]['doctor_note']) ? 'Ναι' : 'Όχι',
          isset($filesByMember[$memberId]['photo']) ? 'Ναι' : 'Όχι',
          isset($filesByMember[$memberId]['birth_certificate']) ? 'Ναι' : 'Όχι',
          isset($filesByMember[$memberId]['card']) ? 'Ναι' : 'Όχι',
          $record->notes,
        ], ';');
      }

      fclose($handle);
    });

    $response->headers->set('Content-Type', 'text/csv; charset=utf-8');
    $response->headers->set('Content-Disposition', 'attachment; filename="melh-' . date('Y-m-d') . '.csv"');

    return $response;
  }

}
