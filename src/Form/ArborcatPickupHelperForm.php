<?php

/**
 * @file
 * Contains \Drupal\arborcat\Form\ArborcatPickupHelperForm
 */

namespace Drupal\arborcat\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Render\Element;
use Drupal\Core\Url;

class ArborcatPickupHelperForm extends FormBase
{
    public function getFormId()
    {
        return 'arborcat_pickup_Helper_form';
    }

    public function buildForm(array $form, FormStateInterface $form_state)
    {
        $form['#attributes'] = ['class' => 'l-overflow-clear'];
        $form['text_entry'] = [
        '#title' => "Enter a barcode to see all eligible pickup appointment locations",
        '#type' => 'textfield',
        '#maxlength' => 500,
        '#default_value' => ($_GET['bcode'] ?? ''),
        '#attributes' => ['pattern' => '21621[0-9]{9}']
      ];
        $form['bcode-submit'] = [
        '#type' => 'submit',
        '#value' => 'Search',
      ];
        return $form;
    }

    public function submitForm(array &$form, FormStateInterface $form_state)
    {
        $barcode = $form_state->getValue('text_entry');
        $path = '/pickuphelper';
        $path_param = [
          'bcode' => $barcode
        ];
        $url = Url::fromUserInput($path, ['query' => $path_param]);
        $form_state->setRedirectUrl($url);
    }
}
