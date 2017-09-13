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
    $api_url = \Drupal::config('arborcat.settings')->get('api_url');
    $user = \Drupal::routeMatch()->getParameter('user');
    $api_key = $user->get('field_api_key')->value;

    // Get Checkouts from API
    $guzzle = \Drupal::httpClient();
    $json = $guzzle->get("http://$api_url/patron/$api_key/checkouts")->getBody()->getContents();
    $checkouts = json_decode($json);

    $output = '<h2>Checkouts</h2>';
    if ($checkouts->out) {
      $output .= '<table><thead><tr>';
      $output .= '<th></th>';
      $output .= '<th>Title</th>';
      $output .= '<th>Author</th>';
      $output .= '<th>Due</th>';
      $output .= '</tr></thead><tbody>';
      foreach ($checkouts->out as $k => $checkout) {
        $output .= "<td><input type=\"checkbox\" value=\"$k\"></td>";
        $output .= "<td><a href=\"/catalog/record/$checkout->bnum\">$checkout->title</a></td>";
        $output .= "<td>$checkout->author</td>";
        $output .= "<td>$checkout->due</td>";
        $output .= '</tr>'; 
      }
      $output .= '</tbody></table>';
    } else {
      $output .= '<p><em>You have no items checked out</em></p>';
    }
    
    return array(
      '#cache' => array(
        'max-age' => 0, // Don't cache, always get fresh data
      ),
      '#markup' => $output,
      '#allowed_tags' => ['table', 'thead', 'th', 'tbody', 'tr', 'td', 'input', 'p', 'em', 'h2', 'a']
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
