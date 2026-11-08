<?php

declare(strict_types=1);

namespace Drupal\Tests\shared_costs\Unit;

use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the logo and its copies: the favicon, the touch icon and the watermark.
 *
 * They are files that nothing else reads until a browser asks for them, so a
 * missing or wrong one would only show as a broken image.
 */
#[Group('shared_costs')]
final class ThemeIconsTest extends UnitTestCase {

  /**
   * The theme's folder.
   */
  private const THEME = __DIR__ . '/../../../themes/stable_manager_theme';

  /**
   * Tests the logo and the horse: SVG, with the logo's tile and the horse.
   */
  public function testSvgs(): void {
    $logo = new \DOMDocument();
    $this->assertTrue($logo->load(self::THEME . '/logo.svg'), 'logo.svg is valid XML.');
    $svg = $logo->documentElement;
    $this->assertNotNull($svg);
    $this->assertSame('svg', $svg->nodeName);
    $this->assertSame('0 0 64 64', $svg->getAttribute('viewBox'));
    $this->assertSame('Stable Manager', $svg->getAttribute('aria-label'));
    $this->assertSame(1, $logo->getElementsByTagName('rect')->length, 'The tile.');
    $this->assertSame(1, $logo->getElementsByTagName('path')->length, 'The horse.');

    // The horse alone is what the stat card's watermark is cut out of.
    $horse = new \DOMDocument();
    $this->assertTrue($horse->load(self::THEME . '/images/horse.svg'), 'horse.svg is valid XML.');
    $this->assertSame(1, $horse->getElementsByTagName('path')->length);
    $path = $horse->getElementsByTagName('path')->item(0);
    $this->assertNotNull($path);
    $this->assertNotSame('', $path->getAttribute('d'));
  }

  /**
   * Tests the favicon for browsers without SVG icons: sizes in an .ico.
   */
  public function testFavicon(): void {
    $ico = file_get_contents(self::THEME . '/favicon.ico');
    $this->assertIsString($ico);
    // An ICO starts with three 16-bit numbers: 0, the type (1 is an icon) and
    // the number of images, and then 16 bytes for each image, which start with
    // its width and height in pixels.
    $header = array_values((array) unpack('v3', $ico));
    $this->assertSame([0, 1], array_slice($header, 0, 2));
    $sizes = [];
    for ($i = 0; $i < $header[2]; $i++) {
      $pixels = array_values((array) unpack('C2', $ico, 6 + 16 * $i));
      // 0 stands for 256.
      $sizes[] = ($pixels[0] ?: 256) . 'x' . ($pixels[1] ?: 256);
    }
    $this->assertSame(['16x16', '32x32', '48x48'], $sizes);
  }

  /**
   * Tests the touch icon: a PNG of 180 by 180, what iOS asks for.
   */
  public function testTouchIcon(): void {
    $size = getimagesize(self::THEME . '/apple-touch-icon.png');
    $this->assertIsArray($size);
    $this->assertSame([180, 180, IMAGETYPE_PNG], [$size[0], $size[1], $size[2]]);
  }

  /**
   * Tests that every file a component's CSS points to exists.
   */
  public function testStatCardFiles(): void {
    $directory = self::THEME . '/components/stat-card';
    $css = file_get_contents($directory . '/stat-card.css');
    $this->assertIsString($css);
    $this->assertSame(1, preg_match_all('/url\(\s*["\']?([^"\')]+)["\']?\s*\)/', $css, $matches), 'The watermark.');
    foreach ($matches[1] as $url) {
      $this->assertFileExists($directory . '/' . $url);
    }
    $this->assertSame('horse.svg', basename($matches[1][0]));
  }

}
