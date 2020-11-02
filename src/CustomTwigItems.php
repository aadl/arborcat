<?php

namespace Drupal\arborcat;
use Michelf\Markdown;

/**
 * Class CustomTwigItems.
 */
class CustomTwigItems extends \Twig_Extension {
  public function getFilters() {
    return [
      new \Twig_SimpleFilter('markdown_to_html', [$this, 'markdownToHTML']),
    ];
  }


  /**
  * Twig filter callback: Converts markdown text to HTML.
  *
  * @param array $build
  *   Render array of a field.
  *
  * @return string
  *   HTML text. If $build is not a render array of a field, NULL is
  *   returned.
  */
  public function markdownToHTML(array $build) {
    // Only proceed if this is a renderable field array.
    if (isset($build) && count($build) > 0) {
      $combined_text = implode("\n", $build);
      $html_text = Markdown::defaultTransform($combined_text);

      return ($html_text) ? $html_text : NULL;
    }
    
    return NULL;
  }


}
