<?php /**
 * @file
 * Contains \Drupal\arborcat_lists\Controller\DefaultController.
 */

namespace Drupal\arborcat_lists\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Default controller for the arborcat_lists module.
 */
class DefaultController extends ControllerBase {

  public function user_lists($uid = NULL) {
    $user = \Drupal\user\Entity\User::load(\Drupal::currentUser()->id());
    $lists = arborcat_lists_get_lists($user->get('uid')->value);

    return [
      '#theme' => 'user_lists',
      '#lists' => $lists
    ];
  }

  public function view_user_list($lid = NULL) {
    $user = \Drupal\user\Entity\User::load(\Drupal::currentUser()->id());
    $connection = \Drupal::database();

    // grab list uid
    $query = $connection->query("SELECT * FROM arborcat_user_lists WHERE id=:lid", 
      [':lid' => $lid]);
    $list = $query->fetch();
    if ($user->get('uid')->value == $list->uid || $list->public || $user->hasRole('administrator')) {
      $query = $connection->query("SELECT * FROM arborcat_user_list_items WHERE list_id=:lid ORDER BY list_order ASC", 
        [':lid' => $lid]);
      $items = $query->fetchAll();
      $list_items = [];
      $list_items['user_owns'] = ($user->get('uid')->value == $list->uid || $user->hasRole('administrator') ? true : false);
      $list_items['title'] = $list->title;
      $api_url = \Drupal::config('arborcat.settings')->get('api_url');
      $guzzle = \Drupal::httpClient();
      foreach ($items as $item) {
        // grab bib record
        $json = $guzzle->get("http://$api_url/record/$item->bib")->getBody()->getContents();
        $bib_record = json_decode($json);
        $mat_types = $guzzle->get("http://$api_url/mat-names")->getBody()->getContents();
        $mat_name = json_decode($mat_types);
        $bib_record->mat_name = $mat_name->{$bib_record->mat_code};
        $list_items[$item->item_id] = $bib_record;
        $list_items[$item->item_id]->list_order = $item->list_order; 
      }

      return [
        '#title' => t($list->title),
        '#theme' => 'user_list_view',
        '#list_items' => $list_items
      ];
    } else {
      return [
        '#title' => t('Access Denied'),
        '#markup' => t('You do not have permission to view this list')
      ];
    }

  }

  public function delete_list($lid) {
    $user = \Drupal\user\Entity\User::load(\Drupal::currentUser()->id());
    $connection = \Drupal::database();

    // grab list uid
    $query = $connection->query("SELECT * FROM arborcat_user_lists WHERE id=:lid", 
      [':lid' => $lid]);
    $result = $query->fetch();

    if ($user->get('uid')->value == $result->uid || $user->hasRole('administrator')) {
      $connection->delete('arborcat_user_list_items')
        ->condition('list_id', $result->id)
        ->execute();
      $connection->delete('arborcat_user_lists')
        ->condition('id', $result->id)
        ->execute();

      $response['success'] = 'List successfully deleted';
    } else {
      $response['error'] = "You don't have permission to delete this list";
    }

    return new JsonResponse($response);
  }

  public function add_list_item($lid, $bib) {
    $user = \Drupal\user\Entity\User::load(\Drupal::currentUser()->id());
    $connection = \Drupal::database();

    // grab list uid
    $query = $connection->query("SELECT uid FROM arborcat_user_lists WHERE id=:lid", 
      [':lid' => $lid]);
    $result = $query->fetch();

    if ($user->get('uid')->value == $result->uid || $user->hasRole('administrator')) {
      // check if bib is already in the list
      $query = $connection->query("SELECT bib FROM arborcat_user_list_items WHERE list_id=:lid AND bib=:bib",
        [':lid' => $lid, ':bib' => $bib]);
      if (count($query->fetchAll())) {
        $response['error'] = 'Item is already on this list';

        return new JsonResponse($response);
      }

      // get total items in list for list_order column insert
      $query = $connection->query("SELECT item_id FROM arborcat_user_list_items WHERE list_id=:lid",
        [':lid' => $lid]);
      $count = count($query->fetchAll());

      $connection->insert('arborcat_user_list_items')
        ->fields([
          'list_id' => $lid,
          'bib' => $bib,
          'list_order' => $count + 1
        ])
        ->execute();

      $response['success'] = 'Item successfully added to list';
    } else {
      $response['error'] = 'You are not authorized to add to this list';
    }

    return new JsonResponse($response);
  }

  public function delete_list_item($lid, $bib) {
    $user = \Drupal\user\Entity\User::load(\Drupal::currentUser()->id());
    $connection = \Drupal::database();

    // grab list uid
    $query = $connection->query("SELECT uid FROM arborcat_user_lists WHERE id=:lid", 
      [':lid' => $lid]);
    $result = $query->fetch();

    if ($user->get('uid')->value == $result->uid || $user->hasRole('administrator')) {
      $query = $connection->query("SELECT * FROM arborcat_user_list_items WHERE list_id=:lid AND bib=:bib",
        [':lid' => $lid, ':bib' => $bib]);
      $row = $query->fetch();
      $connection->delete('arborcat_user_list_items')
        ->condition('item_id', $row->item_id)
        ->execute();
      $connection->update('arborcat_user_list_items')
        ->condition('list_id', $lid, '=')
        ->condition('list_order', $row->list_order, '>')
        ->expression('list_order', 'list_order - 1')
        ->execute();

      $response['success'] = 'Item removed from list';
    } else {
      $response['error'] = "You don't have permission to delete this list item";
    }

    return new JsonResponse($response);
  }

}
