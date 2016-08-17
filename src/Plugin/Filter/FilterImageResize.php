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
   * @var EntityRepositoryInterface
   */
  protected $entityRepository;
  /**
   * ImageFactory instance.
   *
   * @var ImageFactory
   */
  protected $imageFactory;
  /**
   * The FileSystem instance.
   *
   * @var FileSystemInterface
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
    $dom = Html::load($text);
    $xpath = new \DOMXPath($dom);
    /** @var \DOMNode $node */
    foreach ($xpath->query('//img') as $node) {
      $file = $this->entityRepository->loadEntityByUuid('file', $node->getAttribute('data-entity-uuid'));
      // If the image hasn't an uuid then don't try to resize it.
      if (is_null($file)) {
        continue;
      }
      $image = $this->imageFactory->get($node->getAttribute('src'));
      // Checking if the image needs to be resized.
      if ($image->getWidth() == $node->getAttribute('width') && $image->getHeight() == $node->getAttribute('height')) {
        continue;
      }
      $target = file_uri_target($file->getFileUri());
      // Checking if the image was already resized:
      if (file_exists('public://resize/' . $target)) {
        $node->setAttribute('src', file_url_transform_relative(file_create_url('public://resize/' . $target)));
        continue;
      }
      // Delete this when https://www.drupal.org/node/2211657#comment-11510213
      // be fixed.
      $dirname = $this->fileSystem->dirname('public://resize/' . $target);
      if (!file_exists($dirname)) {
        file_prepare_directory($dirname, FILE_CREATE_DIRECTORY);
      }

      // Checks if the resize filter exists if is not then create it.
      $copy = file_unmanaged_copy($file->getFileUri(), 'public://resize/' . $target, FILE_EXISTS_REPLACE);
      $copy_image = $this->imageFactory->get($copy);
      $copy_image->resize($node->getAttribute('width'), $node->getAttribute('height'));
      $copy_image->save();
      $node->setAttribute('src', file_url_transform_relative(file_create_url($copy)));
    }
    return Html::serialize($dom);
  }

}
