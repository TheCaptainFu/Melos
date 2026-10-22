<?php

namespace Drupal\gg_members\Form;

use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\gg_members\MemberManager;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Confirmation step for removing a member and their payment history.
 */
class DeleteMemberForm extends ConfirmFormBase {

  protected int $memberId;

  protected \stdClass $member;

  public function __construct(protected MemberManager $memberManager) {}

  public static function create(ContainerInterface $container) {
    return new static($container->get('gg_members.manager'));
  }

  public function getFormId() {
    return 'gg_members_delete_member_form';
  }

  public function buildForm(array $form, FormStateInterface $form_state, $member = NULL) {
    $record = $this->memberManager->getMember((int) $member);
    if (!$record) {
      throw new NotFoundHttpException();
    }
    $this->memberId = (int) $member;
    $this->member = $record;

    return parent::buildForm($form, $form_state);
  }

  public function getQuestion() {
    return $this->t('Διαγραφή του μέλους @name;', ['@name' => $this->member->name]);
  }

  public function getDescription() {
    return $this->t('Θα διαγραφεί μόνιμα, μαζί με όλο το ιστορικό πληρωμών του. Αυτή η ενέργεια δεν αναιρείται.');
  }

  public function getConfirmText() {
    return $this->t('Διαγραφή');
  }

  public function getCancelText() {
    return $this->t('Ακύρωση');
  }

  public function getCancelUrl() {
    return MemberManager::listUrl(MemberManager::backFromRequest());
  }

  public function submitForm(array &$form, FormStateInterface $form_state) {
    $this->memberManager->deleteMember($this->memberId);
    $this->messenger()->addStatus($this->t('Το μέλος διαγράφηκε.'));
    $form_state->setRedirectUrl(MemberManager::listUrl(MemberManager::backFromRequest()));
  }

}
