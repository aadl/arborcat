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
    $json = $guzzle->get("$api_url/patron/$api_key/checkouts")->getBody()->getContents();
    $checkouts = json_decode($json);

    $output = '<h2>Checkouts</h2>';
    if ($checkouts->out || $checkouts->lost) {
      $output .= "<table id=\"checkouts-table\" data-api-key=\"$api_key\"><thead><tr>";
      $output .= '<th class="no-mobile-display check-all" data-checked="false">&#10004;</th>';
      $output .= '<th>Title</th>';
      $output .= '<th>Format</th>';
      $output .= '<th class="no-mobile-display">Author</th>';
      $output .= '<th>Due</th>';
      $output .= '<th>Renew</th>';
      $output .= '</tr></thead><tbody>';
      // this loop catches both out and lost items to display
      foreach ($checkouts as $outType) {
        foreach ($outType as $checkout) {
          $output .= '<tr class="checkout-row">';
          $output .="<td class=\"no-mobile-display\"><input class=\"renew-checkbox\" type=\"checkbox\"></td>";
          $output .= "<td><a href=\"/catalog/record/$checkout->bnum\">$checkout->title</a></td>";
          $output .= "<td>$checkout->material</td>";
          $output .= "<td class=\"no-mobile-display\">$checkout->author</td>";
          $output .= "<td class=\"checkout-due\">$checkout->due</td>";
          $output .= "<td class=\"item-renew-status\"><button class=\"button item-renew\" data-copy-id=\"$checkout->copyid\">Renew</button></td>";
          $output .= '</tr>'; 
        }
      }
      $output .= '</tbody></table>';
      $output .= '<button class="button l-overflow-clear" id="renew-selected">Renew Selected</button>';
      $output .= '<button class="button l-overflow-clear" id="item-renew-all">Renew All</button>';
    } else {
      $output .= '<p><em>You have no items checked out</em></p>';
    }
    
    return array(
      '#cache' => array(
        'max-age' => 0, // Don't cache, always get fresh data
      ),
      '#markup' => $output,
      '#allowed_tags' => ['table', 'thead', 'th', 'tbody', 'tr', 'td', 'input', 'p', 'em', 'h2', 'a', 'button']
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
