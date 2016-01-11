<?php

/**
 * @file
 * Contains \Drupal\image_resize_filter\Plugin\Filter\FilterImageLinkToSource.
 */

namespace Drupal\image_resize_filter\Plugin\Filter;

use Drupal\Component\Utility\Html;
use Drupal\Component\Utility\UrlHelper;
use Drupal\filter\FilterProcessResult;
use Drupal\filter\Plugin\FilterBase;

/**
 * Provides a filter to link images derivates to source (original) image.
 *
 * @Filter(
 *   id = "filter_image_link_to_source",
 *   title = @Translation("Link images derivates to source"),
 *   description = @Translation("Link an image derivate to its source (original) image."),
 *   type = Drupal\filter\Plugin\FilterInterface::TYPE_TRANSFORM_REVERSIBLE
 * )
 */
class FilterImageLinkToSource extends FilterBase {

  /**
   * {@inheritdoc}
   */
  public function process($text, $langcode) {
    $result = new FilterProcessResult($text);

    $dom = Html::load($text);
    $xpath = new \DOMXPath($dom);

    /** @var \DOMNode $node */
    foreach ($xpath->query('//img') as $node) {
      // Read the data-align attribute's value, then delete it.
      $width = $node->getAttribute('width');
      $height = $node->getAttribute('height');
      $src = $node->getAttribute('src');

      if (!UrlHelper::isExternal($src)) {
        if ($width || $height) {

          /** @var \DOMNode $element */
          $element = $dom->createElement('a');
          $element->setAttribute('href', $src);
          $node->parentNode->replaceChild($element, $node);
          $element->appendChild($node);
        }
      }
    }
    $result->setProcessedText(Html::serialize($dom));

    return $result;
  }

}
