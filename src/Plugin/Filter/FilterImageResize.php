<?php

namespace Drupal\image_resize_filter\Plugin\Filter;

use Drupal\Component\Utility\Html;
use Drupal\Core\Image\ImageFactory;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\filter\FilterProcessResult;
use Drupal\filter\Plugin\FilterBase;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\File\FileSystemInterface;

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
class FilterImageResize extends FilterBase implements ContainerFactoryPluginInterface {

  /**
   * The EntityRepository instance.
   *
   * @var \Drupal\Core\Entity\EntityRepositoryInterface
   */
  protected $entityRepository;
  /**
   * ImageFactory instance.
   *
   * @var \Drupal\Core\Image\ImageFactory
   */
  protected $imageFactory;
  /**
   * The FileSystem instance.
   *
   * @var \Drupal\Core\File\FileSystemInterface
   */
  protected $fileSystem;

  /**
   * FilterImageResize constructor.
   * @param array $configuration
   * @param string $plugin_id
   * @param mixed $plugin_definition
   * @param \Drupal\Core\Entity\EntityRepositoryInterface $entity_repository
   *   The entity Repository.
   * @param \Drupal\Core\Image\ImageFactory $image_factory
   *   Image Factory.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    EntityRepositoryInterface $entity_repository,
    ImageFactory $image_factory,
    FileSystemInterface $file_system) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->entityRepository = $entity_repository;
    $this->imageFactory = $image_factory;
    $this->fileSystem = $file_system;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('entity.repository'),
      $container->get('image.factory'),
      $container->get('file_system')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function process($text, $langcode) {
    return new FilterProcessResult($this->getImages($text));
  }

  /**
   * Locate all images in a piece of text that need replacing.
   *
   *   An array of settings that will be used to identify which images need
   *   updating. Includes the following:
   *
   *   - image_locations: An array of acceptable image locations.
   *     of the following values: "remote". Remote image will be downloaded and
   *     saved locally. This procedure is intensive as the images need to
   *     be retrieved to have their dimensions checked.
   *
   * @param string $text
   *   The text to be updated with the new img src tags.
   *
   * @return array $images
   *   An list of images.
   */
  private function getImages($text) {
    $settings = [];
    $config = \Drupal::config('image_resize_filter');
    $images = image_resize_filter_get_images($settings, $text);

    $search = [];
    $replace = [];

    foreach ($images as $image) {
      // Copy remote images locally.
      if ($image['location'] == 'remote') {
        // @todo Support remote Images
      }
      // Destination and local path are the same if we're just adding attributes.
      elseif (!$image['resize']) {
        $image['destination'] = $image['local_path'];
      }
      else {
        $path_info = image_resize_filter_pathinfo($image['local_path']);
        $local_file_dir = file_uri_target($path_info['dirname']);
        $local_file_path = 'resize/' . ($local_file_dir ? $local_file_dir . '/' : '') . $path_info['filename'] . '-' . $image['expected_size']['width'] . 'x' . $image['expected_size']['height'] . '.' . $path_info['extension'];
        $image['destination'] = $path_info['scheme'] . '://' . $local_file_path;
      }

      if (!file_exists($image['destination'])) {
        // Basic flood prevention of resizing.
        $resize_threshold = $config->get('threshold');
        $flood = \Drupal::flood();
        //if (!flood_is_allowed('image_resize_filter_resize', $resize_threshold, 120)) {
        if (!$flood->isAllowed('image_resize_filter_resize', $resize_threshold, 120)) {
          drupal_set_message(t('Image resize threshold of @count per minute reached. Some images have not been resized. Resave the content to resize remaining images.', ['@count' => floor($resize_threshold / 2)]), 'error', FALSE);
          continue;
        }
        $flood->register('image_resize_filter_resize', 120);

        // Create the resize directory.
        $directory = dirname($image['destination']);
        file_prepare_directory($directory, FILE_CREATE_DIRECTORY);

        // Move remote images into place if they are already the right size.
        if ($image['location'] == 'remote' && !$image['resize']) {
          $handle = fopen($image['destination'], 'w');
          fwrite($handle, file_get_contents($image['local_path']));
          fclose($handle);
        }
        // Resize the local image if the sizes don't match.
        elseif ($image['resize']) {
          //$res = image_load($image['local_path']);
          $copy = file_unmanaged_copy($image['local_path'], $image['destination'], FILE_EXISTS_RENAME);
          $res = $this->imageFactory->get($copy);
          if ($res) {
            // Image loaded successfully; resize.
            $res->resize($image['expected_size']['width'], $image['expected_size']['height']);
            $res->save();
          }
          else {
            // Image failed to load - type doesn't match extension or invalid; keep original file
            $handle = fopen($image['destination'], 'w');
            fwrite($handle, file_get_contents($image['local_path']));
            fclose($handle);
          }
        }
        @chmod($image['destination'], 0664);
      }

      // Delete our temporary file if this is a remote image.
      image_resize_filter_delete_temp_file($image['location'], $image['local_path']);

      // Replace the existing image source with the resized image.
      // Set the image we're currently updating in the callback function.
      $search[] = $image['img_tag'];
      $replace[] = image_resize_filter_image_tag($image, $settings);
    }

    return str_replace($search, $replace, $text);
  }

}
