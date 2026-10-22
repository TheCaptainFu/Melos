<?php

/**
 * @file
 * Fills the GG Members module with a realistic demo academy: about a year
 * and a half of history up to today, so the dashboard and statistics pages
 * have something to show.
 *
 * WIPES every existing member, payment, fee change and member document
 * first. Demo/lab sites only, never a client site.
 *
 * Run from the project root:
 *   ddev drush php:script scripts/gg-members-demo.php
 *
 * Seeded, so every run produces the same academy (relative to today).
 */

use Drupal\gg_members\MemberManager;

mt_srand(2026);

/** @var \Drupal\gg_members\MemberManager $manager */
$manager = \Drupal::service('gg_members.manager');
$database = \Drupal::database();
$today = new \DateTimeImmutable('today');
$thisMonth = $today->modify('first day of this month');

// --- Wipe ---------------------------------------------------------------

foreach ($database->query('SELECT id FROM {gg_members_member}')->fetchCol() as $id) {
  $manager->deleteMember((int) $id);
}

// Summer closure: the academy doesn't charge July and August.
\Drupal::configFactory()->getEditable('gg_members.settings')
  ->set('free_months', [7, 8])
  ->set('class_free_months', [])
  ->save();

// --- Classes --------------------------------------------------------------

$termStorage = \Drupal::entityTypeManager()->getStorage('taxonomy_term');
$classDefs = [
  // name => [fee before Sep of this season, age range, share of members,
  // payer profile weights: punctual, normal, late, bad].
  'Μίνι (5-7)' => [30, [5, 7], 22, [55, 30, 12, 3]],
  'Παίδων (8-11)' => [35, [8, 11], 30, [50, 32, 13, 5]],
  'Εφήβων (12-15)' => [40, [12, 15], 22, [25, 30, 27, 18]],
  'Αγωνιστικό (13-17)' => [50, [13, 17], 15, [60, 30, 8, 2]],
  'Ενηλίκων' => [45, [22, 48], 12, [45, 30, 17, 8]],
];
$classes = [];
$weight = 0;
foreach ($classDefs as $name => $def) {
  $existing = $termStorage->loadByProperties(['vid' => 'gg_members_class', 'name' => $name]);
  $term = $existing ? reset($existing) : $termStorage->create(['vid' => 'gg_members_class', 'name' => $name]);
  $term->set('weight', $weight++);
  $term->save();
  $classes[] = ['tid' => (int) $term->id(), 'name' => $name, 'fee' => $def[0], 'ages' => $def[1], 'share' => $def[2], 'profiles' => $def[3]];
}

// --- Helpers --------------------------------------------------------------

$pickWeighted = function (array $weights) {
  $roll = mt_rand(1, array_sum($weights));
  foreach ($weights as $key => $w) {
    if (($roll -= $w) <= 0) {
      return $key;
    }
  }
  return array_key_first($weights);
};

$firstMale = ['Γιώργος', 'Νίκος', 'Δημήτρης', 'Κώστας', 'Γιάννης', 'Παναγιώτης', 'Αλέξανδρος', 'Χρήστος', 'Βασίλης', 'Θανάσης', 'Στέλιος', 'Μιχάλης', 'Άγγελος', 'Λευτέρης', 'Σπύρος', 'Αντώνης', 'Μάριος', 'Φίλιππος', 'Οδυσσέας', 'Άρης'];
$firstFemale = ['Μαρία', 'Ελένη', 'Κατερίνα', 'Σοφία', 'Αναστασία', 'Δέσποινα', 'Χριστίνα', 'Ιωάννα', 'Βασιλική', 'Αθηνά', 'Δήμητρα', 'Ευαγγελία', 'Νεφέλη', 'Μυρτώ', 'Ζωή', 'Άννα', 'Ειρήνη', 'Φωτεινή', 'Ραφαέλα', 'Στέλλα'];
$lastNames = [
  ['Παπαδόπουλος', 'Παπαδοπούλου'], ['Γεωργίου', 'Γεωργίου'], ['Δημητρίου', 'Δημητρίου'], ['Νικολάου', 'Νικολάου'],
  ['Οικονόμου', 'Οικονόμου'], ['Αντωνίου', 'Αντωνίου'], ['Καραγιάννης', 'Καραγιάννη'], ['Βλάχος', 'Βλάχου'],
  ['Μακρής', 'Μακρή'], ['Παππάς', 'Παππά'], ['Ιωάννου', 'Ιωάννου'], ['Κωνσταντίνου', 'Κωνσταντίνου'],
  ['Αθανασίου', 'Αθανασίου'], ['Πετρόπουλος', 'Πετροπούλου'], ['Σταματόπουλος', 'Σταματοπούλου'], ['Αλεξίου', 'Αλεξίου'],
  ['Μαυρίδης', 'Μαυρίδου'], ['Τσολάκης', 'Τσολάκη'], ['Λαμπράκης', 'Λαμπράκη'], ['Χατζηδάκης', 'Χατζηδάκη'],
  ['Ζαφειρίου', 'Ζαφειρίου'], ['Κυριακίδης', 'Κυριακίδου'], ['Ραφαηλίδης', 'Ραφαηλίδου'], ['Σιδηρόπουλος', 'Σιδηροπούλου'],
];
$usedNames = [];
$makeName = function (bool $female) use (&$usedNames, $firstMale, $firstFemale, $lastNames) {
  do {
    $first = $female ? $firstFemale[mt_rand(0, count($firstFemale) - 1)] : $firstMale[mt_rand(0, count($firstMale) - 1)];
    $last = $lastNames[mt_rand(0, count($lastNames) - 1)][$female ? 1 : 0];
    $name = $first . ' ' . $last;
  } while (isset($usedNames[$name]));
  $usedNames[$name] = TRUE;
  return [$first, $last, $name];
};

// Valid ΑΜΚΑ: ddmmyy + 4 random digits + a Luhn check digit.
$makeAmka = function (string $birth) {
  $base = substr($birth, 8, 2) . substr($birth, 5, 2) . substr($birth, 2, 2) . sprintf('%04d', mt_rand(0, 9999));
  for ($check = 0; $check <= 9; $check++) {
    $sum = 0;
    foreach (array_reverse(str_split($base . $check)) as $i => $digit) {
      $digit = (int) $digit;
      if ($i % 2) {
        $digit *= 2;
        if ($digit > 9) {
          $digit -= 9;
        }
      }
      $sum += $digit;
    }
    if ($sum % 10 === 0) {
      return $base . $check;
    }
  }
};

$phone = fn() => '69' . mt_rand(1, 9) . sprintf('%07d', mt_rand(0, 9999999));

// --- Who joins when, and who leaves ---------------------------------------

$seasonStart = $thisMonth->format('n') >= 9
  ? $thisMonth->setDate((int) $thisMonth->format('Y'), 9, 1)
  : $thisMonth->setDate((int) $thisMonth->format('Y') - 1, 9, 1);
$lastSeason = $seasonStart->modify('-1 year');

// Enrollment month (relative to last season's September) => how many.
$intake = [];
// Veterans, from earlier seasons.
for ($i = 0; $i < 24; $i++) {
  // Mostly a September start, the odd one in January.
  $intake[] = $lastSeason->modify('-' . [12, 12, 12, 24, 24, 8, 12][mt_rand(0, 6)] . ' months');
}
// Last season: a big September, then a trickle.
foreach ([0 => 22, 1 => 8, 2 => 3, 3 => 1, 4 => 5, 5 => 2, 6 => 2, 7 => 1, 8 => 1] as $offset => $count) {
  for ($i = 0; $i < $count; $i++) {
    $intake[] = $lastSeason->modify('+' . $offset . ' months');
  }
}
// This season so far.
for ($offset = 0, $month = $seasonStart; $month <= $thisMonth; $offset++, $month = $month->modify('+1 month')) {
  $count = [0 => 20, 1 => 8, 2 => 3, 3 => 1][$offset] ?? 1;
  for ($i = 0; $i < $count; $i++) {
    $intake[] = $month;
  }
}

// --- Documents --------------------------------------------------------------

$pdf = "%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj 2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj 3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 595 842]>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n";
$png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');
$fileRepository = \Drupal::service('file.repository');
$addFile = function (int $memberId, string $category, string $dir, string $slug, bool $image) use ($manager, $fileRepository, $pdf, $png) {
  $file = $fileRepository->writeData($image ? $png : $pdf, 'private://gg_members/' . $dir . '/' . $slug . ($image ? '.png' : '.pdf'));
  $manager->syncMemberFiles($memberId, $category, array_merge(
    array_map(fn($row) => (int) $row->fid, $manager->getMemberFiles($memberId, $category)),
    [(int) $file->id()]
  ));
};

// --- Members ----------------------------------------------------------------

$classShares = array_combine(array_keys($classes), array_column($classes, 'share'));
$stats = ['members' => 0, 'active' => 0, 'payments' => 0, 'files' => 0];
$feeRaise = [];

foreach ($intake as $n => $enrollMonth) {
  $class = $classes[$pickWeighted($classShares)];
  $female = (bool) mt_rand(0, 1);
  [$first, $last, $name] = $makeName($female);
  $adult = $class['ages'][0] >= 18;

  $enrollment = $enrollMonth->modify('+' . mt_rand(0, 14) . ' days');
  if ($enrollment > $today) {
    $enrollment = $today;
  }
  // Age as of today: the class range for the season they're in now.
  $age = mt_rand($class['ages'][0], $class['ages'][1]);
  $birth = $today->modify('-' . $age . ' years')->modify('-' . mt_rand(0, 364) . ' days');

  // Leaving: a few mid-season, most at the end of last season (June).
  $leftDate = NULL;
  if ($enrollment < $seasonStart) {
    $roll = mt_rand(1, 100);
    if ($roll <= 17) {
      $leftDate = $seasonStart->modify('-3 months')->modify('+' . mt_rand(5, 25) . ' days');
    }
    elseif ($roll <= 24) {
      $leave = $lastSeason->modify('+' . mt_rand(3, 7) . ' months');
      if ($leave > $enrollment) {
        $leftDate = $leave->modify('+' . mt_rand(3, 25) . ' days');
      }
    }
  }
  elseif (mt_rand(1, 100) <= 4) {
    $leftDate = $today->modify('-' . mt_rand(1, 15) . ' days');
  }
  if ($leftDate && $leftDate <= $enrollment) {
    $leftDate = NULL;
  }

  $parentFirst = (mt_rand(0, 1) ? $firstFemale : $firstMale)[mt_rand(0, 19)];
  $id = $manager->createMember([
    'name' => $name,
    'class_tid' => $class['tid'],
    'monthly_fee' => (string) $class['fee'],
    'phone' => $adult ? $phone() : '',
    'email' => $adult ? 'demo' . ($n + 1) . '@example.com' : '',
    'guardian_name' => $adult ? '' : $parentFirst . ' ' . $last,
    'guardian_phone' => $adult ? '' : $phone(),
    'enrollment_date' => $enrollment->format('Y-m-d'),
    'birth_date' => $birth->format('Y-m-d'),
    'amka' => $makeAmka($birth->format('Y-m-d')),
    'card_number' => (string) mt_rand(100000, 999999),
    'status' => $leftDate ? 0 : 1,
    'left_date' => $leftDate?->format('Y-m-d'),
    'notes' => '',
  ]);
  $stats['members']++;
  $stats['active'] += $leftDate ? 0 : 1;

  // This season's fee rise (+5€) for everyone who was already there.
  if (!$leftDate && $enrollment < $seasonStart) {
    $feeRaise[$id] = $class['fee'] + 5;
  }
  elseif (!$leftDate) {
    // Joined this season: straight on the new price.
    $database->update('gg_members_member')->fields(['monthly_fee' => $class['fee'] + 5])->condition('id', $id)->execute();
    $manager->setFee($id, $class['fee'] + 5, $enrollment->format('Y-m'));
  }
  if (isset($feeRaise[$id])) {
    $database->update('gg_members_member')->fields(['monthly_fee' => $feeRaise[$id]])->condition('id', $id)->execute();
    $manager->setFee($id, $feeRaise[$id], $seasonStart->format('Y-m'));
  }

  // --- Payments: how this member pays. ---
  $profile = $pickWeighted(array_combine(['punctual', 'normal', 'late', 'bad'], $class['profiles']));
  $ledger = $manager->getLedger($id);
  $months = array_keys($ledger);
  $count = count($months);
  $unpaidTail = match ($profile) {
    'bad' => mt_rand(2, 5),
    'late' => mt_rand(0, 1),
    default => 0,
  };
  $pairs = $profile === 'punctual' && mt_rand(1, 100) <= 15;

  foreach ($months as $index => $month) {
    if ($index >= $count - $unpaidTail) {
      continue;
    }
    $monthStart = new \DateTimeImmutable($month . '-01');
    $due = $ledger[$month]['due'];
    if ($pairs && $index % 2 === 1 && isset($ledger[$months[$index - 1]])) {
      // Paid together with the previous month, see below.
      continue;
    }
    $day = match ($profile) {
      'punctual' => mt_rand(1, 7),
      'normal' => mt_rand(3, 20),
      'late' => mt_rand(10, 28),
      'bad' => mt_rand(15, 28),
    };
    $date = $monthStart->modify('+' . ($day - 1) . ' days');
    // Late payers sometimes settle the month in the next one.
    if (($profile === 'late' && mt_rand(1, 100) <= 30) || $profile === 'bad') {
      $date = $monthStart->modify('+1 month')->modify('+' . mt_rand(0, 14) . ' days');
    }
    if ($date > $today) {
      continue;
    }

    $toPay = [$month];
    if ($pairs && isset($months[$index + 1]) && $index + 1 < $count - $unpaidTail) {
      $toPay[] = $months[$index + 1];
    }
    foreach ($toPay as $payMonth) {
      $amount = $ledger[$payMonth]['due'];
      // The odd instalment: half now, the rest a few weeks later (or not yet).
      if (mt_rand(1, 100) <= 4) {
        $half = round($amount / 2);
        $manager->recordPayment($id, ['month_covered' => $payMonth, 'amount' => $half, 'payment_date' => $date->format('Y-m-d'), 'notes' => 'Μισή τώρα, μισή αργότερα']);
        $stats['payments']++;
        $rest = $date->modify('+' . mt_rand(10, 25) . ' days');
        // Only recent instalments are still open; older ones were settled.
        if ($rest <= $today && (mt_rand(0, 1) || $index < $count - 3)) {
          $manager->recordPayment($id, ['month_covered' => $payMonth, 'amount' => $amount - $half, 'payment_date' => $rest->format('Y-m-d')]);
          $stats['payments']++;
        }
        continue;
      }
      $manager->recordPayment($id, ['month_covered' => $payMonth, 'amount' => $amount, 'payment_date' => $date->format('Y-m-d')]);
      $stats['payments']++;
    }
  }

  // --- Documents: most have them, some are still missing. ---
  $slug = 'demo-' . $id;
  foreach ([
    ['doctor_note', 'doctor_notes', 78, FALSE],
    ['photo', 'photos', 62, TRUE],
    ['birth_certificate', 'birth_certificates', $adult ? 15 : 55, FALSE],
    ['card', 'cards', 58, FALSE],
  ] as [$category, $dir, $chance, $image]) {
    if (mt_rand(1, 100) <= $chance) {
      $addFile($id, $category, $dir, $slug . '-' . $category, $image);
      $stats['files']++;
    }
  }
}

echo json_encode($stats, JSON_UNESCAPED_UNICODE), "\n";
