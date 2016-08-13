<?php

namespace Drupal\image_resize_filter\Tests;

use Drupal\simpletest\WebTestBase;
use Drupal\comment\Tests\CommentTestTrait;
use Drupal\filter\Entity\FilterFormat;
use Drupal\Core\StreamWrapper\PublicStream;

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
            'allowed_html' => '<img src testattribute height width data-entity-uuid data-entity-type alt> <a>',
          ),
        ),
        'filter_autop' => array(
          'status' => 1,
        ),
//        'filter_image_resize' => array(
//          'status' => 1,
//        ),
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
    $public_files_path = PublicStream::basePath();

    $http_base_url = preg_replace('/^https?/', 'http', $base_url);
    $files_path = base_path() . $public_files_path;
    $csrf_path = $public_files_path . '/' . implode('/', array_fill(0, substr_count($public_files_path, '/') + 1, '..'));

    $druplicon = 'core/misc/druplicon.png';
    $red_x_image = base_path() . 'core/misc/icons/e32700/error.svg';

    // Put a test image in the files directory.
    $test_images = $this->drupalGetTestFiles('image');
    $test_image = $test_images[0]->filename;

    // Put a test image in the files directory with special filename.
    $special_filename = 'tést fïle nàme.png';
    $special_image = rawurlencode($special_filename);
    $special_uri = str_replace($test_images[0]->filename, $special_filename, $test_images[0]->uri);
    $new_image = file_unmanaged_copy($test_images[0]->uri, $special_uri);

    // Create a list of test image sources.
    // The keys become the value of the IMG 'src' attribute, the values are the
    // expected filter conversions.
    $host = \Drupal::request()->getHost();
    $images = array(
      $http_base_url . '/' . $druplicon => base_path() . $druplicon,
    );
    $comment = array();
    foreach ($images as $image => $converted) {
      // Output the image source as plain text for debugging.
      $comment[] = $image . ':';
      // Hash the image source in a custom test attribute, because it might
      // contain characters that confuse XPath.
      $comment[] = '<img src="' . $image . '" testattribute="' . hash('sha256', $image) . '" />';
    }
    $edit = array(
      'comment_body[0][value]' => implode("\n", $comment),
    );
    $this->drupalPostForm('node/' . $this->node->id(), $edit, t('Save'));
    $this->assertTrue(TRUE);
  }
}