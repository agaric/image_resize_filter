<?php

namespace Drupal\image_resize_filter\Tests;

use Drupal\simpletest\WebTestBase;
use Drupal\comment\Tests\CommentTestTrait;
use Drupal\filter\Entity\FilterFormat;
use Drupal\Core\StreamWrapper\PublicStream;
use Drupal\file\Entity\File;

/**
 * Functional tests to test the filter_image_resize filter.
 * @group image_resize_filter
 */
class ResizeImageTest extends WebTestBase {

  use CommentTestTrait;

  /**
   * Modules to enable.
   *
   * @var array
   */
  public static $modules = ['filter', 'file', 'image_resize_filter', 'node', 'comment'];

  /**
   * An authenticated user.
   *
   * @var \Drupal\user\UserInterface
   */
  protected $webUser;


  protected function setUp() {
    parent::setUp();

    // Setup Filtered HTML text format.
    $filtered_html_format = FilterFormat::create(array(
      'format' => 'filtered_html',
      'name' => 'Filtered HTML',
      'filters' => array(
        'filter_html' => array(
          'status' => 1,
          'settings' => array(
            'allowed_html' => '<img src height width data-entity-uuid data-entity-type alt> <a>',
          ),
        ),
        'filter_autop' => array(
          'status' => 1,
        ),
        'filter_image_resize' => array(
          'status' => 1,
        ),
      ),
    ));
    $filtered_html_format->save();

    // Setup users.
    $this->webUser = $this->drupalCreateUser(array(
      'access content',
      'access comments',
      'post comments',
      'skip comment approval',
      $filtered_html_format->getPermissionName(),
    ));
    $this->drupalLogin($this->webUser);

    // Setup a node to comment and test on.
    $this->drupalCreateContentType(array('type' => 'page', 'name' => 'Basic page'));
    // Add a comment field.
    $this->addDefaultCommentField('node', 'page');
    $this->node = $this->drupalCreateNode();
  }

  /**
   * Test the resize feature.
   */
  public function testResizeImages() {
    global $base_url;
    $druplicon = 'core/misc/druplicon.png';
    $uri  = file_unmanaged_copy($druplicon, 'public://druplicon.png', FILE_EXISTS_REPLACE);
    $file = File::create([
      'uri' => $uri,
      'uuid' => 'thisisauuid',
    ]);
    $file->save();
    $relative_path = str_replace($base_url, '', file_create_url($uri));
    $images['inline-image'] = '<img alt="This is a description" data-entity-type="file" data-entity-uuid="' . $file->uuid() . '" height="50" src="' . $relative_path . '" width="44">';
    $comment = [];
    foreach ($images as $key => $image) {
      $comment[$key] = $image;
    }
    $edit = array(
      'comment_body[0][value]' => implode("\n", $comment),
    );
    $this->drupalPostForm('node/' . $this->node->id(), $edit, t('Save'));
    $expected = 'public://resize/druplicon.png';
    $expected_relative_path = str_replace($base_url, '', file_create_url($expected));
    $this->assertNoRaw($relative_path, 'The original image is gone.');
    $this->assertRaw($expected_relative_path, 'The resize version was found.');
    $this->assertTrue(file_exists($expected), 'The resize file exists.');
  }

}
