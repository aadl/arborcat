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
    try {
      $json = $guzzle->get("$api_url/patron/$api_key/checkouts", ['timeout' => 180])->getBody()->getContents();
    }
    catch (\Exception $e) {
      drupal_set_message('Error retrieving checkouts', 'error');
      return [
        '#cache' => [
          'max-age' => 0, // Don't cache, always get fresh data
        ],
        '#markup' => "<h2 id=\"checkouts\">Checkouts</h2>"
      ];
    }

    $checkouts = json_decode($json);
    $total = (count((array) $checkouts->out) ? ' (' . count((array) $checkouts->out) . ')' : '');

    $output = "<h2 id=\"checkouts\">Checkouts$total</h2>";
    if ($checkouts->out) {
      $output .= "<table id=\"checkouts-table\" data-api-key=\"$api_key\"><thead><tr>";
      $output .= '<th class="no-mobile-display check-all no-sort" data-checked="false" data-sort-method="none">&#10004;</th>';
      $output .= '<th>Title</th>';
      $output .= '<th class="no-mobile-display">Author</th>';
      $output .= '<th class="no-mobile-display no-tab-display">Format</th>';
      $output .= '<th data-sort-default>Due</th>';
      $output .= '<th class="no-sort" data-sort-method="none">Renew</th>';
      $output .= '</tr></thead><tbody>';
      // this loop catches both out and lost items to display
      foreach ($checkouts->out as $checkout) {
        $swapped = explode('-', $checkout->due);
        $timestamp = $swapped[1] . '-' . $swapped[0] . '-' . $swapped[2];
        $timestamp = strtotime($timestamp);
        $output .= '<tr class="checkout-row">';
        $output .="<td class=\"no-mobile-display\"><input class=\"renew-checkbox\" type=\"checkbox\"></td>";
        $output .= "<td><a href=\"/catalog/record/$checkout->bnum\">" . (strlen($checkout->title) > 35 ? substr($checkout->title, 0, 35) . '...' : $checkout->title) . " <span class=\"no-desk-display\">($checkout->material)</span></a></td>";
        $output .= "<td class=\"no-mobile-display\"><a href=\"/search/catalog/author:&quot;$checkout->author&quot;\">$checkout->author</a></td>";
        $output .="<td class=\"no-mobile-display no-tab-display\">$checkout->material</td>";
        $output .= "<td class=\"checkout-due\" data-sort=\"$timestamp\">$checkout->due</td>";
        $output .= "<td class=\"item-renew-status\"><button class=\"button item-renew\" data-copy-id=\"$checkout->copyid\">Renew</button></td>";
        $output .= '</tr>';
      }
      $output .= '</tbody></table>';
      $output .= '<button class="button no-mobile-display l-overflow-clear" id="renew-selected">Renew Selected</button>';
      $output .= '<button class="button l-overflow-clear" id="item-renew-all">Renew All</button>';
    } else {
      $output .= '<p><em>You have no items checked out</em></p>';
    }

    return array(
      '#cache' => array(
        'max-age' => 0, // Don't cache, always get fresh data
      ),
      '#markup' => $output,
      '#allowed_tags' => ['table', 'thead', 'th', 'tbody', 'tr', 'td', 'input', 'p', 'em', 'h2', 'a', 'button', 'span']
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
