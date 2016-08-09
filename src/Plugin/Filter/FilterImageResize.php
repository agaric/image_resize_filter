<?php

namespace Drupal\image_resize_filter\Plugin\Filter;

use Drupal\Component\Utility\Html;
use Drupal\Component\Utility\UrlHelper;
use Drupal\filter\FilterProcessResult;
use Drupal\filter\Plugin\FilterBase;
use Drupal\Core\Image;
use Drupal\Core\StreamWrapper\StreamWrapperManagerInterface;

/**
 * Provides a filter to resize images.
 *
 * @Filter(
 *   id = "filter_image_resize",
 *   title = @Translation("Image resize filter"),
 *   description = @Translation("The image resize filter analyze <img> tags and compare the given height and width attributes to the actual file. If the file dimensions are different than those given in the <img> tag, the image will be copied and the src attribute will be updated to point to the resized image."),
 *   type = Drupal\filter\Plugin\FilterInterface::TYPE_TRANSFORM_REVERSIBLE
 * )
 */
class FilterImageResize extends FilterBase {

  /**
   * The stream wrapper manager.
   *
   * @var \Drupal\Core\StreamWrapper\StreamWrapperManagerInterface
   */
  protected $streamWrapperManager;

  /**
   * FilterImageResize constructor.
   * @param array $configuration
   * @param string $plugin_id
   * @param mixed $plugin_definition
   * @param \Drupal\Core\StreamWrapper\StreamWrapperManagerInterface $stream_wrapper_manager
   *   The stream wrapper manager.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, StreamWrapperManagerInterface $stream_wrapper_manager) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->streamWrapperManager = $stream_wrapper_manager;
  }

  /**
   * {@inheritdoc}
   */
  public function process($text, $langcode) {
    $settings = [
      'image_locations' => ['local'],
    ];
    $images = $this->getImages($settings, $text);
    dpm($images);
    $result = new FilterProcessResult($text);
    return $result;
  }

  /**
   * Locate all images in a piece of text that need replacing.
   *
   * @param array $settings
   *   An array of settings that will be used to identify which images need
   *   updating. Includes the following:
   *
   *   - image_locations: An array of acceptable image locations.
   *     of the following values: "remote". Remote image will be downloaded and
   *     saved locally. This procedure is intensive as the images need to
   *     be retrieved to have their dimensions checked.
   * @param string $text
   *   The text to be updated with the new img src tags.
   *
   * @return array $images
   *   An list of images.
   */
  private function getImages($settings, $text) {
    $images = [];

    // Find all image tags, ensuring that they have a src.
    $matches = [];
    preg_match_all('/((<a [^>]*>)[ ]*)?(<img[^>]*?src[ ]*=[ ]*"([^"]+)"[^>]*>)/i', $text, $matches);
    // Loop through matches and find if replacements are necessary.
    // $matches[0]: All complete image tags and preceding anchors.
    // $matches[1]: The anchor tag of each match (if any).
    // $matches[2]: The anchor tag and trailing whitespace of each match if any.
    // $matches[3]: The complete img tag.
    // $matches[4]: The src value of each match.
    foreach ($matches[0] as $key => $match) {
      $has_link = (bool) $matches[1][$key];
      $img_tag = $matches[3][$key];
      // Extract the query string (image style token) if any from the url.
      $src_query = parse_url($matches[4][$key], PHP_URL_QUERY);
      if ($src_query) {
        $src = substr($matches[4][$key], 0, -strlen($src_query) - 1);
      }
      else {
        $src = $matches[4][$key];
      }
      $resize = NULL;
      $image_size = NULL;
      $attributes = array();

      // Find attributes of this image tag.
      $attribute_matches = array();
      preg_match_all('/([\w\-]+)[ ]*=[ ]*"([^"]*)"/i', $img_tag, $attribute_matches);
      foreach ($attribute_matches[0] as $key_attribute => $match_attribute) {
        $attribute = $attribute_matches[1][$key_attribute];
        $attribute_value = $attribute_matches[2][$key_attribute];
        $attributes[$attribute] = $attribute_value;
      }
      // Height and width need to be matched specifically because they may come
      // as either an HTML attribute or as part of a style attribute.
      foreach (array('width', 'height') as $property) {
        $property_matches = [];
        preg_match_all('/[ \'";]' . $property . '[ ]*([=:])[ ]*"?([0-9]+)(%?)"?/i', $img_tag, $property_matches);

        // If this image uses percentage width or height, do not process it.
        if (in_array('%', $property_matches[3])) {
          $resize = FALSE;
          break;
        }

        // In the odd scenario there is both a style="width: xx" and a
        // width="xx" tag, base our calculations off the style tag, since that's
        // what the browser will display.
        $property_key = 0;
        $property_count = count($property_matches[1]);
        if ($property_count) {
          $property_key = array_search(':', $property_matches[1]);
        }
        $attributes[$property] = !empty($property_matches[2][$property_key]) ? $property_matches[2][$property_key] : '';
      }

      // Determine if this is a local or remote file.
      $base_path = base_path();
      $location = 'unknown';
      if (strpos($src, $base_path) === 0) {
        $location = 'local';
      }
      elseif (preg_match('/http[s]?:\/\/' . preg_quote($_SERVER['HTTP_HOST'] . $base_path, '/') . '/', $src)) {
        $location = 'local';
      }
      elseif (strpos($src, 'http') === 0) {
        $location = 'remote';
      }

      // If not resizing images in this location, continue on to the next image.
      if (!in_array($location, $settings['image_locations'])) {
        continue;
      }

      // Convert the URL to a local path.
      $local_path = NULL;
      if ($location == 'local') {
        // Remove the http:// and base path.
        $local_path = preg_replace('/(http[s]?:\/\/' . preg_quote($_SERVER['HTTP_HOST'], '/') . ')?' . preg_quote(base_path(), '/') . '/', '', $src, 1);

        // Build a list of acceptable language prefixes.
        $lang_codes = '';
        //if (array_key_exists('locale-url', variable_get('language_negotiation_language', array())) && variable_get('locale_language_negotiation_url_part', 0) == 0) {
        //  $languages = language_list();
        //  $lang_codes = array();
        //  foreach ($languages as $key => $language) {
        //    if ($language->prefix) {
        //     $lang_codes[$key] = preg_quote($language->prefix, '!');
        //    }
        // }
        // $lang_codes = $lang_codes ? '((' . implode('|', $lang_codes) . ')/)?' : '';
        //}

        // Convert to a public file system URI.
        $directory_path = $this->streamWrapperManager->getViaScheme('public')->getUri() . '/';
        dpm($directory_path);
        //$directory_path = file_stream_wrapper_get_instance_by_scheme('public')->getDirectoryPath() . '/';
        return true;
        if (preg_match('!^' . preg_quote($directory_path, '!') . '!', $local_path)) {
          $local_path = 'public://' . preg_replace('!^' . preg_quote($directory_path, '!') . '!', '', $local_path);
        }
        // Convert to a file system path if using private files.
        elseif (preg_match('!^(\?q\=)?' . $lang_codes . 'system/files/!', $local_path)) {
          $local_path = 'private://' . preg_replace('!^(\?q\=)?' . $lang_codes . 'system/files/!', '', $local_path);
        }
        $local_path = rawurldecode($local_path);
      }

      // If this is an Image preset, generate the source image if necessary.
      // Formatted as "uri://styles/[style-name]/[schema-name]/[original-path]".
      $image_style_matches = array();
      $scheme = file_uri_scheme($local_path);
      if (!file_exists($local_path) && preg_match('!^' . $scheme . '://styles/([a-z0-9_\-]+)/([a-z0-9_\-]+)/(.*)$!i', $local_path, $image_style_matches) && function_exists('image_style_path')) {
        $style_name = $image_style_matches[1];
        $original_path = $scheme . '://' . $image_style_matches[3];
        if ($style = image_style_load($style_name)) {
          image_style_create_derivative($style, $original_path, $local_path);
        }
      }

      // If this is a remote image, retrieve it to check its size.
      if ($location == 'remote') {
        // Basic flood prevention on remote images.
        $resize_threshold = variable_get('image_resize_filter_threshold', 10);
        if (!flood_is_allowed('image_resize_filter_remote', $resize_threshold, 120)) {
          drupal_set_message(t('Image resize threshold of @count remote images has been reached. Please use fewer remote images.', array('@count' => $resize_threshold)), 'error', FALSE);
          continue;
        }
        flood_register_event('image_resize_filter_remote', 120);

        $result = drupal_http_request($src);
        if ($result->code == 200) {
          $tmp_file = drupal_tempnam('temporary://', 'image_resize_filter_');
          $handle = fopen($tmp_file, 'w');
          fwrite($handle, $result->data);
          fclose($handle);
          $local_path = $tmp_file;
        }
      }

      // Get the image size.
      if (is_file($local_path)) {
        $image_size = @getimagesize($local_path);
      }

      // All this work and the image isn't even there. Bummer. Next image please.
      if (empty($image_size)) {
        image_resize_filter_delete_temp_file($location, $local_path);
        continue;
      }

      $actual_width = (int) $image_size[0];
      $actual_height = (int) $image_size[1];

      // If either height or width is missing, calculate the other.
      if (empty($attributes['width']) && empty($attributes['height'])) {
        $attributes['width'] = $actual_width;
        $attributes['height'] = $actual_height;
      }
      if (empty($attributes['height']) && is_numeric($attributes['width'])) {
        $ratio = $actual_height / $actual_width;
        $attributes['height'] = (int) round($ratio * $attributes['width']);
      }
      elseif (empty($attributes['width']) && is_numeric($attributes['height'])) {
        $ratio = $actual_width / $actual_height;
        $attributes['width'] = (int) round($ratio * $attributes['height']);
      }

      // Determine if this image requires a resize.
      if (!isset($resize)) {
        $resize = ($actual_width != $attributes['width'] || $actual_height != $attributes['height']);
      }

      // Skip processing if the image is a remote tracking image.
      if ($location == 'remote' && $actual_width == 1 && $actual_height == 1) {
        image_resize_filter_delete_temp_file($location, $local_path);
        continue;
      }

      // Check the image extension by name.
      $extension_matches = array();
      preg_match('/\.([a-zA-Z0-9]+)$/', $src, $extension_matches);
      if (!empty($extension_matches)) {
        $extension = strtolower($extension_matches[1]);
      }
      // If the name extension failed (such as an image generated by a script),
      // See if we can determine an extension by MIME type.
      elseif (isset($image_size['mime'])) {
        switch ($image_size['mime']) {
          case 'image/png':
            $extension = 'png';
            break;

          case 'image/gif':
            $extension = 'gif';
            break;

          case 'image/jpeg':
          case 'image/pjpeg':
            $extension = 'jpg';
            break;
        }
      }

      // If we're not certain we can resize this image, skip it.
      if (!isset($extension) || !in_array(strtolower($extension), array('png', 'jpg', 'jpeg', 'gif'))) {
        image_resize_filter_delete_temp_file($location, $local_path);
        continue;
      }

      // If getting this far, the image exists and is not the right size, needs
      // to be saved locally from a remote server, or needs attributes added.
      // Add all information to a list of images that need resizing.
      $images[] = array(
        'expected_size' => array('width' => $attributes['width'], 'height' => $attributes['height']),
        'actual_size' => array('width' => $image_size[0], 'height' => $image_size[1]),
        'attributes' => $attributes,
        'resize' => $resize,
        'img_tag' => $img_tag,
        'has_link' => $has_link,
        'original' => $src,
        'original_query' => $src_query,
        'location' => $location,
        'local_path' => $local_path,
        'mime' => $image_size['mime'],
        'extension' => $extension,
      );
    }

    return $images;
  }

}
