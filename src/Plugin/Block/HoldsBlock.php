<?php
namespace Drupal\arborcat\Plugin\Block;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Block\BlockBase;
use Drupal\Core\Session\AccountInterface;

/**
 * Provides a Patron Holds Block.
 *
 * @Block(
 *   id = "holds_block",
 *   admin_label = @Translation("Holds block"),
 * )
 */
class HoldsBlock extends BlockBase {

  /**
   * {@inheritdoc}
   */
  public function build() {
    $api_url = \Drupal::config('arborcat.settings')->get('api_url');
    $user = \Drupal::routeMatch()->getParameter('user');
    $api_key = $user->get('field_api_key')->value;

    // Get Checkouts from API
    $json = file_get_contents("http://$api_url/patron/$api_key/holds");
    $holds = json_decode($json);

    $rows = array();
    foreach ($holds as $hold) {
      $rows[] = (array) $hold;
    }

    return array(
      '#markup' => '<h1>USER HOLDS</h1>',
      'holds_table' => array(
        '#type' => 'table',
        '#header' => array_keys($rows[0]),
        '#rows' => $rows,
      ),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function access(AccountInterface $account, $return_as_object = FALSE) {
    $route_name = \Drupal::routeMatch()->getRouteName();
    return ($route_name == 'entity.user.canonical' ? AccessResult::allowed() : AccessResult::forbidden());
  }
}
