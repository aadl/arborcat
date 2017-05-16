<?php
namespace Drupal\arborcat\Plugin\Block;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Block\BlockBase;
use Drupal\Core\Session\AccountInterface;

/**
 * Provides a Patron Checkouts Block.
 *
 * @Block(
 *   id = "checkouts_block",
 *   admin_label = @Translation("Checkouts block"),
 * )
 */
class CheckoutsBlock extends BlockBase {

  /**
   * {@inheritdoc}
   */
  public function build() {
    // Get Checkouts from API
    $json = file_get_contents('http://nginx2/patron/checkouts');
    $checkouts = json_decode($json);

    $rows = array();
    foreach ($checkouts as $checkout) {
      $rows[] = (array) $checkout; //$checkout_list .= "<li>$checkout->format: $checkout->title by $checkout->author</li>";
    }

    return array(
      '#markup' => '<h1>USER CHECKOUTS</h1>',
      'checkout_table' => array(
        '#type' => 'table',
        '#header' => array_keys($rows[0]),
        '#rows' => $rows,
      ),
//      '#attached' => array('library' => array('arborcat/patron-functions')),
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
