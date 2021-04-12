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
    $api_key = $this->getConfiguration()['api_key'];

    // Get Checkouts from API
    $guzzle = \Drupal::httpClient();
    try {
      $json = $guzzle->get("$api_url/patron/$api_key/checkouts", ['timeout' => 180])->getBody()->getContents();
    }
    catch (\Exception $e) {
      \Drupal::messenger()->addError('Error retrieving checkouts');
      return [
        '#cache' => [
          'max-age' => 0, // Don't cache, always get fresh data
        ],
        '#markup' => "<h2 id=\"checkouts\">Checkouts</h2>"
      ];
    }

    $checkouts = json_decode($json);
    $total = (count((array) $checkouts->out) ? ' (' . count((array) $checkouts->out) . ')' : '');

    $output = "<div><h2 id=\"checkouts\" class=\"l-inline-b\">Checkouts$total</h2>";
    $output .= "<span class=\"checkouts-feeds\"><a href=\"webcal://api.aadl.org/patron/$api_key/feed/ical\">&nbsp;<i class=\"fa fa-calendar\" aria-hidden=\"true\"></i>&nbsp;iCal</a></span></div>";
    if ($checkouts->out) {
      $output .= "<table id=\"checkouts-table\" data-api-key=\"$api_key\"><thead><tr>";
      $output .= '<th class="no-mobile-display check-all no-sort" data-checked="false" data-sort-method="none">&#10004;</th>';
      $output .= '<th>Title</th>';
      $output .= '<th class="no-mobile-display">Author</th>';
      $output .= '<th class="no-mobile-display no-tab-display">Format</th>';
      $output .= '<th data-sort-default>Due</th>';
      $output .= '<th class="no-sort" data-sort-method="none">Renew</th>';
      $output .= '</tr></thead><tbody>';
      $count = 1;
      foreach ($checkouts->out as $checkout) {
        if (strpos($checkout->material, 'DVD') !== false || strpos($checkout->material, 'Blu-Ray') !== false) {
          $checkout->material = explode(' ', $checkout->material)[0];
          $checkout->author = '';
        }
        $swapped = explode('-', $checkout->due);
        $timestamp = $swapped[1] . '-' . $swapped[0] . '-' . $swapped[2];
        $timestamp = strtotime($timestamp);
        // both these timestamps are for 12am of the given day, so greater than is sufficient in this case
        $overdue = (strtotime(date('d-m-Y')) > $timestamp ? 'error-text' : '');
        $output .= ($count > 50 ? '<tr class="checkout-row hide-row">' : '<tr class="checkout-row">');
        $output .="<td class=\"no-mobile-display\"><input class=\"renew-checkbox\" type=\"checkbox\"></td>";
        $title = (strlen($checkout->title) > 35 ? substr($checkout->title, 0, 35) . '...' : $checkout->title);
        if (strpos($checkout->material, 'ILL' !== false)) {
          $output .= "<td>$title</td>";
        } else {
          $output .= "<td><a href=\"/catalog/record/$checkout->bnum\">" . htmlentities($title) . " $checkout->mag_issue <span class=\"no-desk-display\">($checkout->material)</span></a></td>";
        }
        $output .= "<td class=\"no-mobile-display\"><a href=\"/search/catalog/author:&quot;$checkout->author&quot;\">$checkout->author</a></td>";
        $output .="<td class=\"no-mobile-display no-tab-display\">$checkout->material</td>";
        $output .= "<td class=\"checkout-due $overdue\" data-sort=\"$timestamp\">$checkout->due</td>";
        $output .= "<td class=\"item-renew-status\"><button class=\"button item-renew\" data-copy-id=\"$checkout->copyid\">Renew</button></td>";
        $output .= '</tr>';
        $count++;
      }
      $output .= '</tbody></table>';
      if ($count - 1 > 50) $output .= '<a href="" data-target="#checkouts-table" data-state="hidden" class="show-table-rows l-block base-margin-bottom">View All</a>';
      $output .= '<button class="button no-mobile-display l-overflow-clear" id="renew-selected">Renew Selected</button>';
      $output .= '<button class="button l-overflow-clear" id="item-renew-all">Renew All</button>';
    } else {
      $output .= '<p><em>You have no items checked out</em></p>';
    }
    // protect against varying evg char encoding
    $output = mb_convert_encoding($output, "UTF-8");

    return array(
      '#cache' => array(
        'max-age' => 0, // Don't cache, always get fresh data
      ),
      '#markup' => $output,
      '#allowed_tags' => ['table', 'thead', 'th', 'tbody', 'tr', 'td', 'input', 'p', 'em', 'h2', 'a', 'button', 'span', 'div', 'i']
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
