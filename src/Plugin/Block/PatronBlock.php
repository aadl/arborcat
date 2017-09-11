<?php
namespace Drupal\arborcat\Plugin\Block;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Block\BlockBase;
use Drupal\Core\Session\AccountInterface;

/**
 * Provides a Patron Info Block.
 *
 * @Block(
 *   id = "patron_block",
 *   admin_label = @Translation("Patron block"),
 * )
 */
class PatronBlock extends BlockBase {

  /**
   * {@inheritdoc}
   */
  public function build() {
    $api_url = \Drupal::config('arborcat.settings')->get('api_url');
    $user = \Drupal::routeMatch()->getParameter('user');
    $api_key = $user->get('field_api_key')->value;

    // Get patron info from API
    $guzzle = \Drupal::httpClient();
    $json = $guzzle->get("http://$api_url/patron/$api_key/get")->getBody()->getContents();
    $patron = json_decode($json);

    $output = '<h2>Account Summary</h2>';
    $output .= '<table id="account-summary"><tbody>';
    $output .= "<tr><th scope=\"row\">Library Card Number</th><td>$patron->card</td></tr>";
    $output .= "<tr><th scope=\"row\">Default Pickup Location</th><td>filler</td></tr>";
    $output .= "<tr><th scope=\"row\">Items Checked Out</th><td>filler</td></tr>";
    $output .= "<tr><th scope=\"row\">Account Balance</th><td>filler</td></tr>";
    $output .= "<tr><th scope=\"row\">Card Expiration Date</th><td>" . date('m-d-Y', strtotime($patron->expires)) . "</td></tr>";
    $output .= "<tr><th scope=\"row\">Account Email</th><td>$patron->email</td></tr>";
    $output .= "<tr><th scope=\"row\">Notifications Sent To</th><td>$patron->email</td></tr>";
    $output .= '</tbody></table>';

    return array(
      '#cache' => array(
        'max-age' => 0, // Don't cache, always get fresh data
      ),
      '#markup' => $output,
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
