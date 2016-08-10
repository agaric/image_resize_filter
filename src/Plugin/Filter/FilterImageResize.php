<?php

namespace Drupal\image_resize_filter\Plugin\Filter;

use Drupal\Component\Utility\Html;
use Drupal\Core\Image\ImageFactory;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\filter\FilterProcessResult;
use Drupal\filter\Plugin\FilterBase;
use Drupal\Core\Entity\EntityRepositoryInterface;

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

  protected $entityRepository;
  protected $imageFactory;

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
  public function __construct(array $configuration, $plugin_id, $plugin_definition, EntityRepositoryInterface $entity_repository, ImageFactory $image_factory) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->entityRepository = $entity_repository;
    $this->imageFactory = $image_factory;
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
      $container->get('image.factory')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function process($text, $langcode) {
    $images = $this->getImages($text);
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
  private function getImages($text) {
    // If getting this far, the image exists and is not the right size, needs
    // to be saved locally from a remote server, or needs attributes added.
    // Add all information to a list of images that need resizing.
    // @todo determine if these values are needed to get the resize.
    // $images[] = array(
    // 'attributes' => $attributes,
    // 'resize' => $resize,
    // 'img_tag' => $img_tag,
    // 'has_link' => $has_link,
    // 'original_query' => $src_query,
    // 'extension' => $extension,
    // );
    $images = [];
    $dom = Html::load($text);
    $xpath = new \DOMXPath($dom);
    /** @var \DOMNode $node */
    foreach ($xpath->query('//img') as $node) {
      $file = $this->entityRepository->loadEntityByUuid('file', $node->getAttribute('data-entity-uuid'));
      $image = $this->imageFactory->get($file->getFileUri());

      // Read the data-align attribute's value, then delete it.
      $images[] = [
        'uuid' => $node->getAttribute('data-entity-uuid'),
        'expected_size' => [
          'width' => $node->getAttribute('width'),
          'height' => $node->getAttribute('height'),
        ],
        'actual_size' => [
          'width' => $image->getWidth(),
          'height' => $image->getHeight(),
        ],
        'original' => $node->getAttribute('src'),
        // @todo Support for remote images.
        'location' => 'local',
        'local_path' => $image->getSource(),
        //'name' => $image->
        'mime' => $image->getMimeType(),
      ];
    }
    return $images;
  }

}
