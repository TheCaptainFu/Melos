<?php

/**
 * @file
 * Hooks provided by the GG Members module.
 */

/**
 * Adds sections to a member's profile page.
 *
 * @param array $build
 *   The profile's render array; put sections in $build['#extra'].
 * @param \stdClass $member
 *   The member's row from gg_members_member.
 */
function hook_gg_members_profile_alter(array &$build, \stdClass $member) {
  $build['#extra']['example'] = ['#markup' => '<div class="gg-members-card">…</div>'];
}

/**
 * Removes a module's own rows about a member that is being deleted.
 *
 * Runs inside the deletion's transaction.
 *
 * @param int $id
 *   The member's ID.
 */
function hook_gg_members_member_delete(int $id) {
  \Drupal::database()->delete('example_table')->condition('member_id', $id)->execute();
}
