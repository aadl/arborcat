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
    $delta = $_GET['subaccount'] ?? 0;
    $api_key = $user->field_api_key[$delta]->value;

    // Get patron info from API
    $guzzle = \Drupal::httpClient();
    try {
      $patron = json_decode($guzzle->get("$api_url/patron/$api_key/get")->getBody()->getContents());
      $fines = json_decode($guzzle->get("$api_url/patron/$api_key/fines")->getBody()->getContents());
    }
    catch (\Exception $e) {
      drupal_set_message('Error retrieving patron data', 'error');
      return [
        '#cache' => [
          'max-age' => 0, // Don't cache, always get fresh data
        ],
        '#markup' => '<h2 id="account-sum" class="no-margin">Account Summary</h2>'
      ];
    }

    $uid = $user->get('uid')->value;
    $email = $user->get('mail')->value;
    $payment_link = ($fines->total ? ' (<a href="/fees-payment">pay fees</a>)' : '');
    $card_is_current = $this->currentPatron($user, $patron->expires);

    $output = '<h2 id="account-sum" class="no-margin">Account Summary</h2>';
    $output .= "<img id=\"bcode-img\" src=\"$api_url/patron/$api_key/barcode\" alt=\"Image of barcode for scanning at selfchecks\">";
    $output .= '<table class="account-summary" class="l-overflow-clear"><tbody>';
    $output .= "<tr><th scope=\"row\">Library Card Number</th><td>$patron->card <a href=\"/user/$uid/edit/barcode\">(edit)</td></tr>";
    $output .= "<tr><th scope=\"row\">Account Balance</th><td>$" . number_format($fines->total, 2) . $payment_link . "</td></tr>";
    $output .= "<tr><th scope=\"row\">Card Expiration Date</th><td>" . date('m-d-Y', strtotime($patron->expires)) . "</td></tr>";
    $output .= "<tr><th scope=\"row\">Account Email</th><td>$email</a></td></tr>";
    $output .= "<tr><th scope=\"row\">Notifications Sent To</th><td>$patron->email</a></td></tr>";
    $output .= '</tbody></table>';

    if ($addl_barcodes = $user->get('field_additional_barcode')) {
      $output .= '<h2>Additional Barcodes</h2><em>We have stored your additional barcodes. ' .
                 'Full functionality to see Checkouts, Requests, and Fines coming soon!</em><ul>';
      foreach ($addl_barcodes as $addl_barcode) {
        $output .= "<li>$addl_barcode->value</li>";
      }
      $output .= '</ul>';
    }
    // $output .= '<a href="" class="button l-overflow-clear" role="button">Add another library card</a>';

    arborcat_patron_fines_expired($fines, $patron);

    return array(
      '#cache' => [
        'max-age' => 0, // Don't cache, always get fresh data
      ],
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

  public function currentPatron($user, $expiration)
  {
    if (strtotime($expiration) >= time()) {
      $user->addRole('patron');
      $user->save();
      return true;
    } else {
      $user->removeRole('patron');
      $user->save();
      return false;
    }
  }
}
